<?php
// logout.php - Secure logout

require_once __DIR__ . '/session_bootstrap.php';

// --- No-cache headers ---
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

// --- Fully clear session data ---
$_SESSION = [];

// --- Delete the session cookie ---
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// --- Destroy session on server ---
session_destroy();

// --- Generate a new session ID immediately (to avoid reuse) ---
session_start();
session_regenerate_id(true);
session_write_close();

// --- Redirect to login ---
header('Location: index.php');
exit();
?>
