<?php
/**
 * auth.php — Helper sesi sederhana
 *
 * Include file ini di api.php dan halaman yang butuh login.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

/** Cek apakah user sudah login */
function isLoggedIn(): bool {
    return !empty($_SESSION['logged_in']);
}

/** Dipakai di api.php — kirim JSON 401 jika belum login */
function requireLogin(): void {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'pesan' => 'Belum login.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
