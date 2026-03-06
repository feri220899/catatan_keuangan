<?php
/**
 * api.php — Backend Catatan Keuangan (MySQL)
 *
 * GET  api.php                           → baca semua data
 * POST api.php  action=transaksi         → tambah transaksi
 * POST api.php  action=hapus_transaksi   → hapus transaksi
 * POST api.php  action=tambah_kategori   → tambah kategori
 * POST api.php  action=hapus_kategori    → hapus kategori
 */

require_once __DIR__ . '/auth.php';
requireLogin();

require_once __DIR__ . '/koneksi.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

/* =====================================================
   FUNGSI BANTU
===================================================== */

function jsonResponse(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* =====================================================
   ROUTING
===================================================== */

$method = $_SERVER['REQUEST_METHOD'];

// ——— GET: kembalikan semua data ———
if ($method === 'GET') {

    global $conn;

    // Pemasukan
    $pemasukan = [];
    $res = $conn->query("SELECT id, tanggal, jumlah, catatan, diinput_oleh FROM pemasukan ORDER BY tanggal DESC");
    while ($row = $res->fetch_assoc()) {
        $row['jumlah'] = (float) $row['jumlah'];
        $pemasukan[] = $row;
    }

    // Pengeluaran
    $pengeluaran = [];
    $res = $conn->query("SELECT id, tanggal, kategori_id, kategori_nama, jumlah, catatan, diinput_oleh FROM pengeluaran ORDER BY tanggal DESC");
    while ($row = $res->fetch_assoc()) {
        $row['jumlah'] = (float) $row['jumlah'];
        $pengeluaran[] = $row;
    }

    // Kategori
    $categories = [];
    $res = $conn->query("SELECT id, nama FROM categories ORDER BY nama ASC");
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row;
    }

    jsonResponse([
        'pemasukan'   => $pemasukan,
        'pengeluaran' => $pengeluaran,
        'categories'  => $categories,
        'nama_user'   => $_SESSION['nama'] ?? $_SESSION['username'] ?? '',
    ]);

// ——— POST: tentukan aksi berdasarkan field 'action' ———
} elseif ($method === 'POST') {

    global $conn;

    $body  = file_get_contents('php://input');
    $input = json_decode($body, true);

    if (!is_array($input)) {
        jsonResponse(['status' => 'error', 'pesan' => 'Body request tidak valid.'], 400);
    }

    $action = trim($input['action'] ?? 'transaksi');

    /* --------------------------------------------------
       AKSI: TAMBAH TRANSAKSI
    -------------------------------------------------- */
    if ($action === 'transaksi') {

        $tanggal = trim($input['tanggal'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            jsonResponse(['status' => 'error', 'pesan' => 'Tanggal tidak valid.'], 422);
        }

        $jenis = trim($input['jenis'] ?? '');
        if (!in_array($jenis, ['pemasukan', 'pengeluaran'], true)) {
            jsonResponse(['status' => 'error', 'pesan' => 'Jenis transaksi tidak valid.'], 422);
        }

        $jumlah = isset($input['jumlah']) ? (float) $input['jumlah'] : 0;
        if ($jumlah <= 0) {
            jsonResponse(['status' => 'error', 'pesan' => 'Jumlah harus angka positif.'], 422);
        }

        $catatan      = mb_substr(trim($input['catatan'] ?? ''), 0, 500);
        $id           = uniqid('txn_', true);
        $diinput_oleh = $_SESSION['nama'] ?? $_SESSION['username'] ?? '';

        if ($jenis === 'pemasukan') {
            $stmt = $conn->prepare(
                "INSERT INTO pemasukan (id, tanggal, jumlah, catatan, diinput_oleh) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('ssdss', $id, $tanggal, $jumlah, $catatan, $diinput_oleh);

            if (!$stmt->execute()) {
                jsonResponse(['status' => 'error', 'pesan' => 'Gagal menyimpan transaksi.'], 500);
            }

            $transaksi = [
                'id'           => $id,
                'tanggal'      => $tanggal,
                'jumlah'       => $jumlah,
                'catatan'      => $catatan,
                'diinput_oleh' => $diinput_oleh,
            ];

        } else {
            $kategori_id   = trim($input['kategori_id'] ?? '');
            $kategori_nama = '';

            if ($kategori_id !== '') {
                $stmt_cat = $conn->prepare("SELECT nama FROM categories WHERE id = ?");
                $stmt_cat->bind_param('s', $kategori_id);
                $stmt_cat->execute();
                $stmt_cat->bind_result($kategori_nama);
                if (!$stmt_cat->fetch()) {
                    $kategori_id   = '';
                    $kategori_nama = '';
                }
                $stmt_cat->close();
            }

            $stmt = $conn->prepare(
                "INSERT INTO pengeluaran (id, tanggal, kategori_id, kategori_nama, jumlah, catatan, diinput_oleh) VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('ssssdss', $id, $tanggal, $kategori_id, $kategori_nama, $jumlah, $catatan, $diinput_oleh);

            if (!$stmt->execute()) {
                jsonResponse(['status' => 'error', 'pesan' => 'Gagal menyimpan transaksi.'], 500);
            }

            $transaksi = [
                'id'            => $id,
                'tanggal'       => $tanggal,
                'kategori_id'   => $kategori_id,
                'kategori_nama' => $kategori_nama,
                'jumlah'        => $jumlah,
                'catatan'       => $catatan,
                'diinput_oleh'  => $diinput_oleh,
            ];
        }

        $stmt->close();
        jsonResponse(['status' => 'ok', 'pesan' => 'Transaksi berhasil disimpan.', 'transaksi' => $transaksi], 201);

    /* --------------------------------------------------
       AKSI: HAPUS TRANSAKSI
    -------------------------------------------------- */
    } elseif ($action === 'hapus_transaksi') {

        $id = trim($input['id'] ?? '');
        if ($id === '') {
            jsonResponse(['status' => 'error', 'pesan' => 'ID transaksi wajib diisi.'], 422);
        }

        $found = false;

        foreach (['pemasukan', 'pengeluaran'] as $tabel) {
            $stmt = $conn->prepare("DELETE FROM `$tabel` WHERE id = ?");
            $stmt->bind_param('s', $id);
            $stmt->execute();
            if ($stmt->affected_rows > 0) $found = true;
            $stmt->close();
        }

        if (!$found) {
            jsonResponse(['status' => 'error', 'pesan' => 'Transaksi tidak ditemukan.'], 404);
        }

        jsonResponse(['status' => 'ok', 'pesan' => 'Transaksi berhasil dihapus.']);

    /* --------------------------------------------------
       AKSI: TAMBAH KATEGORI
    -------------------------------------------------- */
    } elseif ($action === 'tambah_kategori') {

        $nama = trim($input['nama'] ?? '');
        if ($nama === '' || mb_strlen($nama) > 100) {
            jsonResponse(['status' => 'error', 'pesan' => 'Nama kategori tidak valid (maks 100 karakter).'], 422);
        }

        // Cek duplikat (case-insensitive)
        $stmt = $conn->prepare("SELECT id FROM categories WHERE LOWER(nama) = LOWER(?)");
        $stmt->bind_param('s', $nama);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            jsonResponse(['status' => 'error', 'pesan' => 'Kategori sudah ada.'], 422);
        }
        $stmt->close();

        $id = 'cat_' . uniqid();
        $stmt = $conn->prepare("INSERT INTO categories (id, nama) VALUES (?, ?)");
        $stmt->bind_param('ss', $id, $nama);

        if (!$stmt->execute()) {
            jsonResponse(['status' => 'error', 'pesan' => 'Gagal menyimpan kategori.'], 500);
        }
        $stmt->close();

        jsonResponse(['status' => 'ok', 'pesan' => 'Kategori berhasil ditambahkan.', 'kategori' => ['id' => $id, 'nama' => $nama]], 201);

    /* --------------------------------------------------
       AKSI: HAPUS KATEGORI
    -------------------------------------------------- */
    } elseif ($action === 'hapus_kategori') {

        $id = trim($input['id'] ?? '');
        if ($id === '') {
            jsonResponse(['status' => 'error', 'pesan' => 'ID kategori wajib diisi.'], 422);
        }

        $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->bind_param('s', $id);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            $stmt->close();
            jsonResponse(['status' => 'error', 'pesan' => 'Kategori tidak ditemukan.'], 404);
        }
        $stmt->close();

        jsonResponse(['status' => 'ok', 'pesan' => 'Kategori berhasil dihapus.']);

    } else {

        jsonResponse(['status' => 'error', 'pesan' => 'Aksi tidak dikenal.'], 400);
    }

} else {

    jsonResponse(['status' => 'error', 'pesan' => 'Method tidak didukung.'], 405);
}
