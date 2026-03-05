<?php
require_once __DIR__ . '/auth.php';

// Bersihkan semua data sesi
$_SESSION = [];

// Hapus cookie sesi
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();
header('Location: login.php');
exit;
