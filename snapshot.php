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
  'Thematic Indices'     => ['NIFTY ENERGY'],
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

// snapshot.php?candles=ZYDUSLIFE[&day=30-Oct-2026] -> readable table of the recorded 5-minute candles
if (isset($_GET['candles'])) {
  header('Content-Type: text/plain; charset=utf-8');
  $sym = strtoupper(trim((string)$_GET['candles']));
  $d = isset($_GET['day']) ? (string)$_GET['day'] : null;
  if (!$d) {                                       // latest saved day
    $best = 0;
    foreach (glob("$dir/breaks-*.json") ?: [] as $f) { $x = substr(basename($f), 7, -5); if (($t = strtotime($x)) > $best) { $best = $t; $d = $x; } }
  }
  if (!$d || !preg_match('/^\d{1,2}-[A-Za-z]{3}-\d{4}$/', $d) || !is_file("$dir/breaks-$d.json")) { echo "No saved data for that day.\n"; exit; }
  $state = json_decode(file_get_contents("$dir/breaks-$d.json"), true);
  $st = $state['stocks'][$sym] ?? null;
  if (!$st) { echo "$sym not found on $d. Check the symbol and that its sector is in SOURCES.\n"; exit; }
  $k = $st['k'] ?? [];
  echo "$sym  $d   (candles built from 1-minute snapshots)\n\n";
  printf("%-13s %10s %10s %10s %10s  %s\n", 'Candle', 'Open', 'High', 'Low', 'Close', 'Colour');
  $prev = null;
  $keys = array_map('intval', array_keys($k)); sort($keys);
  foreach ($keys as $i) {
    if ($prev !== null && $i !== $prev + 1) echo "  ... gap: no data for " . ($i - $prev - 1) . " candle(s) ...\n";
    $c = $k[(string)$i];
    $from = 555 + $i * CANDLE_MIN; $to = $from + CANDLE_MIN;
    $col = $c[3] > $c[0] ? 'green' : ($c[3] < $c[0] ? 'red' : 'flat');
    printf("%02d:%02d-%02d:%02d   %10.2f %10.2f %10.2f %10.2f  %s\n", intdiv($from, 60), $from % 60, intdiv($to, 60), $to % 60, $c[0], $c[1], $c[2], $c[3], $col);
    $prev = $i;
  }
  echo "\n9 EMA " . round($st['e9'] ?? 0, 2) . "   15 EMA " . round($st['e15'] ?? 0, 2) . "  (as of the last completed candle)\n";
  $sig = array_values(array_filter($state['signals'] ?? [], fn($g) => $g['s'] === $sym));
  echo "Setups detected: " . count($sig) . "\n";
  $lastK = $keys ? $k[(string)end($keys)] : null;
  if ($lastK && isset($lastK[6])) echo "VWAP now " . $lastK[6] . "\n";
  foreach ($sig as $g) {
    $t = 555 + ($g['i'] + 1) * CANDLE_MIN;
    printf("  %02d:%02d %s  entry %.2f  SL %.2f  target %.2f  %s  Nifty %s  VWAP %s\n", intdiv($t, 60), $t % 60,
      $g['d'] === 'L' ? 'LONG ' : 'SHORT', $g['e'], $g['sl'], $g['tg'], $g['a'] ? 'A+ (' . $g['a'] . ' EMA)' : '',
      $g['nok'] ? 'agrees' : 'AGAINST', isset($g['vok']) ? ($g['vok'] ? 'agrees' : 'AGAINST') : 'n/a');
  }
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
// "29-Oct-2026 14:32:53" -> ["29-Oct-2026", candle index from 09:15 (null outside 09:15–15:30), minute of day]
function parse_time($updated) {
  $p = explode(' ', trim((string)$updated));
  if (count($p) < 2) return [null, null, null];
  $hm = explode(':', $p[1]);
  $min = intval($hm[0]) * 60 + intval($hm[1] ?? 0);
  $idx = ($min >= 555 && $min < 930) ? intdiv($min - 555, CANDLE_MIN) : null;
  return [$p[0], $idx, $min];
}

function streak_of($list, $cur) {
  if (!$list) return 0;
  $k = count($list) - 1;
  if ($list[$k] < $cur - 1) return 0;   // current candle may still be forming
  $n = 1;
  while ($k > 0 && $list[$k - 1] === $list[$k] - 1) { $n++; $k--; }
  return $n;
}

// ---------- Setup detection ----------
// Long:  3+ green candles, exactly 2 red, then a green trigger candle. Entry = trigger close,
//        SL = trigger low, target = entry + 3 x risk. Nifty 50 must be up on the day,
//        and the trigger must close above VWAP.
//        A+ = trigger candle touched the 9 EMA or 15 EMA. Short = mirror.
const SETUP_MIN_TREND = 3;   // trend candles before the pullback
const SETUP_RR = 3;          // target = 3 x risk
const EMA_FAST = 9;
const EMA_SLOW = 15;
const SETUP_BREAKOUT_WINDOW = 6;  // stock counts as "in Breakouts" if it broke out (same direction) within the last 6 candles

function candle_color($k) { return $k[3] > $k[0] ? 1 : ($k[3] < $k[0] ? -1 : 0); } // [o,h,l,c,v,p,vwap]

function nifty_at($state, $idx) {             // Nifty 50 % change at (or just before) this candle
  $best = null; $bi = -1;
  foreach (($state['nifty'] ?? []) as $i => $p) { $i = (int)$i; if ($i <= $idx && $i > $bi) { $bi = $i; $best = $p; } }
  return $best;
}

// Called when candle $ci of $sym is complete
function finalize_candle(&$state, $sym, &$st, $ci) {
  $K = $st['k'][(string)$ci] ?? null;
  if (!$K) return;
  $st['done'] = $ci;

  // 1. EMAs on 5-minute closes (carried over from the previous day when available)
  $c = $K[3];
  foreach ([['e9', EMA_FAST], ['e15', EMA_SLOW]] as [$f, $n]) {
    $st[$f] = ($st[$f] ?? null) === null ? $c : $c * (2 / ($n + 1)) + $st[$f] * (1 - 2 / ($n + 1));
  }

  // 2. Pullback breakouts (close-based). Highs: the highest high is the level; a red candle that
  //    makes no new high = pullback; a later candle CLOSING above the level = 1 breakout, and its
  //    high becomes the next level. Lows are the mirror image (green bounce candle, close below).
  if (!isset($st['swh'])) {                     // first completed candle (or record from an older version)
    $st['swh'] = $K[1]; $st['swl'] = $K[2]; $st['pbh'] = false; $st['pbl'] = false;
    $st['bh'] = []; $st['bl'] = []; $st['bhl'] = []; $st['bll'] = [];
  } else {
    $color = candle_color($K);
    if ($st['pbh'] && $K[3] > $st['swh'] * (1 + BREAK_BUFFER)) {
      $st['bh'][] = $ci; $st['bhl'][] = round($st['swh'], 2); $st['swh'] = $K[1]; $st['pbh'] = false;
    } elseif ($K[1] > $st['swh']) { $st['swh'] = $K[1]; $st['pbh'] = false; }
    elseif ($color === -1) { $st['pbh'] = true; }

    if ($st['pbl'] && $K[3] < $st['swl'] * (1 - BREAK_BUFFER)) {
      $st['bl'][] = $ci; $st['bll'][] = round($st['swl'], 2); $st['swl'] = $K[2]; $st['pbl'] = false;
    } elseif ($K[2] < $st['swl']) { $st['swl'] = $K[2]; $st['pbl'] = false; }
    elseif ($color === 1) { $st['pbl'] = true; }
  }

  // 3. Results of earlier signals on this stock (SL checked first if both hit in one candle)
  foreach ($state['signals'] as &$g) {
    if ($g['s'] !== $sym || $g['r'] !== null || $g['i'] >= $ci) continue;
    if ($g['d'] === 'L') {
      if ($K[2] <= $g['sl']) { $g['r'] = 'S'; $g['x'] = $ci; }
      elseif ($K[1] >= $g['tg']) { $g['r'] = 'T'; $g['x'] = $ci; }
    } else {
      if ($K[1] >= $g['sl']) { $g['r'] = 'S'; $g['x'] = $ci; }
      elseif ($K[2] <= $g['tg']) { $g['r'] = 'T'; $g['x'] = $ci; }
    }
  }
  unset($g);

  // 4. Pattern ending at this candle: trend(3+) -> 2 opposite -> trigger
  $col = function ($i) use ($st) { $k = $st['k'][(string)$i] ?? null; return $k ? candle_color($k) : null; };
  $dir = candle_color($K);
  if ($dir === 0) return;
  if ($col($ci - 1) !== -$dir || $col($ci - 2) !== -$dir) return;     // exactly 2 opposite candles...
  $trend = 0;
  for ($i = $ci - 3; $col($i) === $dir; $i--) $trend++;               // ...preceded by the trend colour
  if ($trend < SETUP_MIN_TREND) return;

  $entry = $K[3];
  $sl = $dir === 1 ? $K[2] : $K[1];
  $risk = abs($entry - $sl);
  if ($risk <= 0) return;
  $tg = $dir === 1 ? $entry + SETUP_RR * $risk : $entry - SETUP_RR * $risk;

  $touch = [];
  if ($K[2] <= $st['e9'] && $st['e9'] <= $K[1]) $touch[] = '9';
  if ($K[2] <= $st['e15'] && $st['e15'] <= $K[1]) $touch[] = '15';

  // on the Breakouts list at the trigger? (pullback breakout for longs / breakdown for shorts, recent)
  $barr = $dir === 1 ? ($st['bh'] ?? []) : ($st['bl'] ?? []);
  $recent = array_values(array_filter($barr, fn($b) => $b > $ci - SETUP_BREAKOUT_WINDOW && $b <= $ci));
  $boCount = count(array_filter($barr, fn($b) => $b <= $ci));

  $np = nifty_at($state, $ci);
  $vw = (isset($K[6]) && $K[6] > 0) ? $K[6] : null;      // day VWAP at the trigger candle close
  $state['signals'][] = [
    's' => $sym, 'd' => $dir === 1 ? 'L' : 'S', 'i' => $ci, 'n' => $trend,
    'e' => round($entry, 2), 'sl' => round($sl, 2), 'tg' => round($tg, 2),
    'a' => implode('+', $touch),                          // "", "9", "15" or "9+15"
    'np' => $np, 'nok' => $np !== null && ($dir === 1 ? $np > 0 : $np < 0),
    'bo' => count($recent) > 0, 'boc' => $boCount,         // in Breakouts list / breakouts so far today
    'vw' => $vw, 'vok' => $vw !== null && ($dir === 1 ? $entry > $vw : $entry < $vw),   // close on the right side of VWAP
    'r' => null, 'x' => null,                             // result: 'T' target / 'S' stop, x = candle
  ];
}

// Previous trading day's closing EMAs, so today's EMAs don't start cold
function previous_emas($dir, $day) {
  $today = strtotime($day); $best = null; $bt = 0;
  foreach (glob("$dir/breaks-*.json") ?: [] as $f) {
    $t = strtotime(substr(basename($f), 7, -5));
    if ($t && $t < $today && $t > $bt) { $bt = $t; $best = $f; }
  }
  if (!$best) return [];
  $prev = json_decode(file_get_contents($best), true);
  $out = [];
  foreach (($prev['stocks'] ?? []) as $sym => $st) if (isset($st['e9'])) $out[$sym] = [$st['e9'], $st['e15'] ?? $st['e9']];
  return $out;
}

function update_breaks($dir, $stocks, $sectors) {
  $rows = []; $member = [];
  foreach ($stocks as $index => $list) if (is_array($list)) foreach ($list as $r) if (isset($r['symbol'])) {
    $rows[$r['symbol']] = $r;
    $member[$r['symbol']][] = $index;
  }
  $day = null; $nowIdx = null;
  foreach ($rows as $r) {
    [$d, $i] = parse_time($r['lastUpdatedTime'] ?? '');
    if ($d && !$day) $day = $d;
    if ($i !== null && ($nowIdx === null || $i > $nowIdx)) $nowIdx = $i;
  }
  if (!$day) return null;

  $file = "$dir/breaks-$day.json";              // new file each trading day = automatic reset
  $state = is_file($file) ? json_decode(file_get_contents($file), true) : null;
  if (!$state) $state = ['since' => null, 'cur' => null, 'stocks' => [], 'signals' => [], 'seed' => previous_emas($dir, $day)];
  if (!isset($state['signals'])) $state['signals'] = [];

  // sector names, membership, and Nifty 50 % change for this candle
  foreach ($sectors as $s) if (isset($s['index'])) {
    $state['names'][$s['index']] = $s['indexLongName'] ?? $s['index'];
    if ($s['index'] === 'NIFTY 50' && $nowIdx !== null) $state['nifty'][(string)$nowIdx] = (float)($s['pChange'] ?? 0);
  }
  foreach ($member as $sym => $list) {
    $state['sec'][$sym] = array_values(array_unique(array_merge($state['sec'][$sym] ?? [], $list)));
  }

  foreach ($rows as $sym => $r) {
    [, $idx, $min] = parse_time($r['lastUpdatedTime'] ?? '');
    $st = $state['stocks'][$sym] ?? null;
    if ($st && !isset($st['k'])) {              // record from an older version: add candle fields
      $v0 = (float)($r['totalTradedVolume'] ?? 0);
      $st += ['k' => [], 'vol' => $v0, 'vs' => $v0, 'done' => $st['ci'] ?? -1, 'e9' => null, 'e15' => null];
    }

    if ($idx === null) {                          // after 15:30: close the last candle once
      if ($st && $min !== null && $min >= 930 && ($st['done'] ?? -1) < $st['ci']) {
        finalize_candle($state, $sym, $st, $st['ci']);
        $state['stocks'][$sym] = $st;
      }
      continue;
    }
    $H = (float)($r['high'] ?? 0); $L = (float)($r['low'] ?? 0);
    $ltp = (float)($r['lastPrice'] ?? 0); $vol = (float)($r['totalTradedVolume'] ?? 0); $pc = (float)($r['pChange'] ?? 0);
    $vwap = round((float)($r['VWAP'] ?? 0), 2);
    if ($H <= 0 || $L <= 0 || $ltp <= 0) continue;

    if (!$st) {
      $seed = $state['seed'][$sym] ?? [null, null];
      $st = ['dh' => $H, 'dl' => $L, 'ci' => $idx, 'hb4' => $H, 'lb4' => $L, 'hb' => false, 'lb' => false,
             'bh' => [], 'bl' => [], 'k' => [], 'vol' => $vol, 'vs' => $vol, 'done' => -1, 'e9' => $seed[0], 'e15' => $seed[1]];
      $st['k'][(string)$idx] = [$ltp, $ltp, $ltp, $ltp, 0, $pc, $vwap];
    } else {
      if (!isset($st['k'][(string)$st['ci']]) && $idx === $st['ci']) {   // upgraded record: start this candle now
        $st['k'][(string)$idx] = [$ltp, $ltp, $ltp, $ltp, 0, $pc, $vwap];
      }
      if ($idx !== $st['ci']) {                   // previous candle finished
        if (($st['done'] ?? -1) < $st['ci']) finalize_candle($state, $sym, $st, $st['ci']);
        $st['ci'] = $idx; $st['hb4'] = $st['dh']; $st['lb4'] = $st['dl']; $st['hb'] = false; $st['lb'] = false;
        $st['vs'] = $st['vol'];
        $st['k'][(string)$idx] = [$ltp, $ltp, $ltp, $ltp, 0, $pc, $vwap];
      }
      // candle OHLCV from 1-minute snapshots (day high/low catch spikes between polls)
      $k = $st['k'][(string)$idx];
      $k[1] = max($k[1], $ltp); $k[2] = min($k[2], $ltp);
      if ($H > $st['dh']) $k[1] = max($k[1], $H);
      if ($L < $st['dl']) $k[2] = min($k[2], $L);
      $k[3] = $ltp; $k[4] = max(0, $vol - $st['vs']); $k[5] = $pc; $k[6] = $vwap;
      $st['k'][(string)$idx] = $k;
      $st['dh'] = $H; $st['dl'] = $L; $st['vol'] = $vol;
    }
    $state['stocks'][$sym] = $st;
    if ($state['since'] === null || $idx < $state['since']) $state['since'] = $idx;
    if ($state['cur'] === null || $idx > $state['cur']) $state['cur'] = $idx;
  }
  file_put_contents($file, json_encode($state));

  // summary per stock: [breakouts, lastBreakoutIdx, lastLevel, breakdowns, lastBreakdownIdx, lastLevel,
  //                      pullingBackNow (highs), pullingBackNow (lows)]
  $cur = $state['cur']; $sum = [];
  foreach ($state['stocks'] as $sym => $st) {
    if (!isset($st['swh'])) continue;             // not yet on the pullback rule (first candle still forming)
    if (empty($st['bh']) && empty($st['bl'])) continue;
    $bh = $st['bh'] ?? []; $bl = $st['bl'] ?? [];
    $bhl = $st['bhl'] ?? []; $bll = $st['bll'] ?? [];
    $sum[$sym] = [count($bh), $bh ? end($bh) : -1, $bhl ? end($bhl) : null,
                  count($bl), $bl ? end($bl) : -1, $bll ? end($bll) : null,
                  empty($st['pbh']) ? 0 : 1, empty($st['pbl']) ? 0 : 1];
  }
  return ['candle' => $cur, 'since' => $state['since'], 'breaks' => (object)$sum, 'signals' => $state['signals'], 'sec' => $state['sec'] ?? []];
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
  // Each wanted index is looked up in its listed group first, then in the other NSE groups,
  // so an index filed under a different group (e.g. Thematic) is still found.
  $ALL_TYPES = ['Broad Market Indices', 'Sectoral Indices', 'Thematic Indices', 'Strategy Indices'];
  $lists = [];
  $getList = function ($type) use (&$lists, $cookieFile, &$errors) {
    if (!array_key_exists($type, $lists)) {
      try { $lists[$type] = get_json(list_url($type), $cookieFile); }
      catch (Exception $e) { $lists[$type] = []; $errors[] = "$type list: " . $e->getMessage(); }
      usleep(GAP_MICROSECONDS);
    }
    return $lists[$type];
  };
  $want = [];                                     // index name => group it is listed under in SOURCES
  foreach (SOURCES as $type => $keep) {
    if (!$keep) { foreach ($getList($type) as $s) if (isset($s['index'])) $want[$s['index']] = $type; }
    else foreach ($keep as $name) $want[$name] = $type;
  }
  $where = [];
  foreach ($want as $name => $type) {
    foreach (array_merge([$type], array_values(array_diff($ALL_TYPES, [$type]))) as $t) {
      foreach ($getList($t) as $s) if (($s['index'] ?? '') === $name) { $s['type'] = $t; $where[$name] = $s; break 2; }
    }
    if (!isset($where[$name])) $errors[] = "$name not found in any NSE index group";
    elseif ($where[$name]['type'] !== $type) $errors[] = "note: $name is under '{$where[$name]['type']}', not '$type' (works anyway)";
  }
  foreach ($where as $name => $s) {
    $sectors[] = $s;
    try { $stocks[$name] = get_json(stock_url($s['type'], $name), $cookieFile); }
    catch (Exception $e) { $errors[] = "$name: " . $e->getMessage(); }
    usleep(GAP_MICROSECONDS);
  }
  if (!$sectors) throw new Exception('No index data. ' . implode('; ', $errors));

  $bo = update_breaks($dir, $stocks, $sectors);
  $out = json_encode([
    'fetchedAt' => gmdate('c'), 'sectors' => $sectors, 'stocks' => (object)$stocks, 'errors' => $errors,
    'candle' => $bo['candle'] ?? null, 'since' => $bo['since'] ?? null, 'breaks' => $bo['breaks'] ?? (object)[],
    'signals' => $bo['signals'] ?? [],
  ]);
  file_put_contents($cacheFile, $out);
  echo $out;
} catch (Exception $e) {
  if (is_file($cacheFile)) { readfile($cacheFile); }   // serve last good copy
  else fail(502, 'NSE fetch failed: ' . $e->getMessage());
} finally {
  flock($lock, LOCK_UN);
}
