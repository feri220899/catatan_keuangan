<?php
/**
 * koneksi.php — Koneksi ke database MySQL
 *
 * Include file ini di api.php / login.php / generate_users.php.
 * Variabel $conn tersedia setelah include.
 */

$db_host = "sql310.infinityfree.com";
$db_user = "if0_41304100";
$db_pass = "Se7xC9pLKiu";
$db_name = "if0_41304100_keuangan";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'pesan' => 'Koneksi database gagal.']);
    exit;
}

$conn->set_charset('utf8mb4');
