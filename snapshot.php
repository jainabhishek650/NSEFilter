<?php
// snapshot.php — live NSE data for the screener (XAMPP / PHP 7.4+ with curl)
// Put it in the same folder as nse-sector-screener.html, e.g. C:\xampp\htdocs\nse-screener\
// Then open http://localhost/nse-screener/nse-sector-screener.html

header('Content-Type: application/json');
header('Cache-Control: no-store');

const CACHE_SECONDS = 45;      // shorter than the page's 60 s poll, so each poll gets fresh data
const GAP_MICROSECONDS = 300000; // 0.3 s pause between sector requests
const CANDLE_MIN = 5;            // breakout candle size (must match the page)
const BREAK_BUFFER = 0.0005;     // new day high/low must beat the old one by 0.05%

// Indices to fetch, grouped by NSE type. Empty array for a type = fetch every index of that type.
const SOURCES = [
  'Sectoral Indices' => [
    'NIFTY AUTO', 'NIFTY PHARMA', 'NIFTY BANK', 'NIFTY PSU BANK', 'NIFTY METAL',
    'NIFTY CEMENT', 'NIFTY FMCG', 'NIFTY REALTY', 'NIFTY IT', 'NIFTY OIL AND GAS'
  ],
  'Broad Market Indices' => ['NIFTY 50'],
];

const BASE = 'https://www.nseindia.com';
// Index list endpoint per type. If your sector JSON came from a different URL, change it here.
function list_url($type)  { return BASE . '/api/heatmap-index?type=' . rawurlencode($type); }
function stock_url($type, $index) {
  return BASE . '/api/heatmap-symbols?type=' . rawurlencode($type) . '&indices=' . rawurlencode($index);
}

function fail($code, $msg) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }

if (!function_exists('curl_init')) {
  fail(500, 'PHP curl is off. In XAMPP: Config > php.ini, remove the ; before extension=curl, restart Apache.');
}

$dir = __DIR__ . '/cache';
if (!is_dir($dir) && !mkdir($dir, 0777, true)) fail(500, 'Cannot create cache folder next to snapshot.php');
$cacheFile  = "$dir/snapshot.json";
$cookieFile = "$dir/cookies.txt";

// ---------- Replay endpoints ----------
// snapshot.php?days            -> ["29-Oct-2026", "28-Oct-2026", ...] (newest first)
// snapshot.php?day=29-Oct-2026 -> that day's saved breakout record
if (isset($_GET['days'])) {
  $days = [];
  foreach (glob("$dir/breaks-*.json") ?: [] as $f) {
    $d = substr(basename($f), 7, -5);
    if ($ts = strtotime($d)) $days[] = ['day' => $d, 'ts' => $ts];
  }
  usort($days, fn($a, $b) => $b['ts'] - $a['ts']);
  echo json_encode(array_column($days, 'day'));
  exit;
}
if (isset($_GET['day'])) {
  $d = (string)$_GET['day'];
  if (!preg_match('/^\d{1,2}-[A-Za-z]{3}-\d{4}$/', $d) || !is_file("$dir/breaks-$d.json")) fail(404, 'No saved data for that day');
  readfile("$dir/breaks-$d.json");
  exit;
}

// Fresh enough? Serve the cached copy.
if (is_file($cacheFile) && time() - filemtime($cacheFile) < CACHE_SECONDS) { readfile($cacheFile); exit; }

// Only one request refreshes at a time; others get the last copy.
$lock = fopen("$dir/lock", 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
  if (is_file($cacheFile)) { readfile($cacheFile); exit; }
  fail(503, 'warming up');
}

function nse_get($url, $cookieFile) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_ENCODING       => '',            // accept gzip
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_COOKIEFILE     => $cookieFile,
    CURLOPT_HTTPHEADER     => [
      'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
      'Accept: application/json, text/plain, */*',
      'Accept-Language: en-US,en;q=0.9',
      'Referer: ' . BASE . '/market-data/live-market-indices/heatmap',
    ],
  ]);
  // XAMPP on Windows often lacks a CA bundle setting; use the one it ships with
  if (!ini_get('curl.cainfo')) {
    foreach (['C:/xampp/apache/bin/curl-ca-bundle.crt', 'C:/xampp/php/extras/ssl/cacert.pem'] as $ca) {
      if (is_file($ca)) { curl_setopt($ch, CURLOPT_CAINFO, $ca); break; }
    }
  }
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  return [$code, $body, $err];
}

// ---------- Breakout tracking: 5-minute candles, whole day, saved to cache/breaks-<date>.json ----------
// "29-Oct-2026 14:32:53" -> ["29-Oct-2026", candle index from 09:15, or null outside 09:15–15:30]
function parse_time($updated) {
  $p = explode(' ', trim((string)$updated));
  if (count($p) < 2) return [null, null];
  $hm = explode(':', $p[1]);
  $min = intval($hm[0]) * 60 + intval($hm[1] ?? 0);
  $idx = ($min >= 555 && $min < 930) ? intdiv($min - 555, CANDLE_MIN) : null;
  return [$p[0], $idx];
}

function streak_of($list, $cur) {
  if (!$list) return 0;
  $k = count($list) - 1;
  if ($list[$k] < $cur - 1) return 0;   // current candle may still be forming
  $n = 1;
  while ($k > 0 && $list[$k - 1] === $list[$k] - 1) { $n++; $k--; }
  return $n;
}

function update_breaks($dir, $stocks, $sectors) {
  $rows = []; $member = [];
  foreach ($stocks as $index => $list) if (is_array($list)) foreach ($list as $r) if (isset($r['symbol'])) {
    $rows[$r['symbol']] = $r;
    $member[$r['symbol']][] = $index;
  }
  $day = null;
  foreach ($rows as $r) { [$d] = parse_time($r['lastUpdatedTime'] ?? ''); if ($d) { $day = $d; break; } }
  if (!$day) return null;

  $file = "$dir/breaks-$day.json";              // new file each trading day = automatic reset
  $state = is_file($file) ? json_decode(file_get_contents($file), true) : null;
  if (!$state) $state = ['since' => null, 'cur' => null, 'stocks' => []];
  // for replay: sector names and which sectors each stock belongs to
  foreach ($sectors as $s) if (isset($s['index'])) $state['names'][$s['index']] = $s['indexLongName'] ?? $s['index'];
  foreach ($member as $sym => $list) {
    $state['sec'][$sym] = array_values(array_unique(array_merge($state['sec'][$sym] ?? [], $list)));
  }

  foreach ($rows as $sym => $r) {
    [, $idx] = parse_time($r['lastUpdatedTime'] ?? '');
    if ($idx === null) continue;                  // outside market hours
    $H = (float)($r['high'] ?? 0); $L = (float)($r['low'] ?? 0);
    if ($H <= 0 || $L <= 0) continue;
    $st = $state['stocks'][$sym] ?? null;
    if (!$st) {
      $st = ['dh' => $H, 'dl' => $L, 'ci' => $idx, 'hb4' => $H, 'lb4' => $L, 'hb' => false, 'lb' => false, 'bh' => [], 'bl' => []];
    } else {
      if ($idx !== $st['ci']) {                   // new candle: remember the day high/low before it
        $st['ci'] = $idx; $st['hb4'] = $st['dh']; $st['lb4'] = $st['dl']; $st['hb'] = false; $st['lb'] = false;
      }
      if (!$st['hb'] && $H > $st['hb4'] * (1 + BREAK_BUFFER)) { $st['hb'] = true; $st['bh'][] = $idx; }
      if (!$st['lb'] && $L < $st['lb4'] * (1 - BREAK_BUFFER)) { $st['lb'] = true; $st['bl'][] = $idx; }
      $st['dh'] = $H; $st['dl'] = $L;
    }
    // for replay: LTP and % change at the latest moment of this candle
    $st['px'][(string)$idx] = [round((float)($r['lastPrice'] ?? 0), 2), round((float)($r['pChange'] ?? 0), 2)];
    $state['stocks'][$sym] = $st;
    if ($state['since'] === null || $idx < $state['since']) $state['since'] = $idx;
    if ($state['cur'] === null || $idx > $state['cur']) $state['cur'] = $idx;
  }
  file_put_contents($file, json_encode($state));

  // summary per stock: [highBreaks, lastHighIdx, highStreak, lowBreaks, lastLowIdx, lowStreak]
  $cur = $state['cur']; $sum = [];
  foreach ($state['stocks'] as $sym => $st) {
    if (!$st['bh'] && !$st['bl']) continue;
    $sum[$sym] = [count($st['bh']), $st['bh'] ? end($st['bh']) : -1, streak_of($st['bh'], $cur),
                  count($st['bl']), $st['bl'] ? end($st['bl']) : -1, streak_of($st['bl'], $cur)];
  }
  return ['candle' => $cur, 'since' => $state['since'], 'breaks' => (object)$sum];
}

function refresh_cookies($cookieFile) { nse_get(BASE, $cookieFile); }

function get_json($url, $cookieFile) {
  $last = '';
  for ($attempt = 1; $attempt <= 3; $attempt++) {
    [$code, $body, $err] = nse_get($url, $cookieFile);
    if ($code === 200) {
      $json = json_decode($body, true);
      if (is_array($json)) return isset($json['data']) ? $json['data'] : $json;
      $last = 'NSE returned non-JSON';
    } else {
      $last = $err ?: "HTTP $code";
    }
    refresh_cookies($cookieFile);
    sleep(1);
  }
  throw new Exception($last);
}

try {
  if (!is_file($cookieFile) || time() - filemtime($cookieFile) > 600) refresh_cookies($cookieFile);

  $sectors = [];
  $stocks = [];
  $errors = [];
  foreach (SOURCES as $type => $keep) {
    try {
      $all = get_json(list_url($type), $cookieFile);
    } catch (Exception $e) { $errors[] = "$type list: " . $e->getMessage(); continue; }
    foreach ($all as $s) {
      if ($keep && !in_array($s['index'] ?? '', $keep, true)) continue;
      $s['type'] = $type;
      $sectors[] = $s;
      try { $stocks[$s['index']] = get_json(stock_url($type, $s['index']), $cookieFile); }
      catch (Exception $e) { $errors[] = $s['index'] . ': ' . $e->getMessage(); }
      usleep(GAP_MICROSECONDS);
    }
  }
  if (!$sectors) throw new Exception('No index data. ' . implode('; ', $errors));

  $bo = update_breaks($dir, $stocks, $sectors);
  $out = json_encode([
    'fetchedAt' => gmdate('c'), 'sectors' => $sectors, 'stocks' => (object)$stocks, 'errors' => $errors,
    'candle' => $bo['candle'] ?? null, 'since' => $bo['since'] ?? null, 'breaks' => $bo['breaks'] ?? (object)[],
  ]);
  file_put_contents($cacheFile, $out);
  echo $out;
} catch (Exception $e) {
  if (is_file($cacheFile)) { readfile($cacheFile); }   // serve last good copy
  else fail(502, 'NSE fetch failed: ' . $e->getMessage());
} finally {
  flock($lock, LOCK_UN);
}
