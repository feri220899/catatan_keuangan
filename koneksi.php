<?php
/**
 * koneksi.php — Koneksi ke database MySQL
 *
 * Include file ini di api.php / login.php / generate_users.php.
 * Variabel $conn tersedia setelah include.
 */

$db_host = "127.0.0.1";
$db_user = "root";
$db_pass = "root";
$db_name = "keuangan";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'pesan' => 'Koneksi database gagal.']);
    exit;
}

$conn->set_charset('utf8mb4');
