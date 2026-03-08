<?php
// --- secure-session bootstrap (include this at the very top of every entry file) ---
session_name('MAHALSESSID');           // isolate your app's session cookie
ini_set('session.use_strict_mode', 1); // reject uninitialized IDs
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax'); // use 'Strict' if you never need cross-site POSTs
// If you want to keep sessions in-app, uncomment after creating a writable folder:
// session_save_path(__DIR__ . '/sessions');

session_start();
