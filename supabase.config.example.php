<?php
// Copy this file to supabase.config.php (same folder as snapshot.php) and fill it in.
// supabase.config.php holds your SECRET key: it must stay on the recorder PC only.
// It is listed in .gitignore so it never goes to GitHub.
return [
  'url'         => 'https://YOUR-PROJECT-ID.supabase.co',
  'service_key' => 'PASTE_THE_SECRET_KEY_HERE',   // Supabase -> Project Settings -> API Keys -> secret key (or legacy service_role)
];
