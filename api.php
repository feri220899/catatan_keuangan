<?php
/**
 * api.php — Backend Catatan Keuangan
 *
 * Struktur data.json:
 *   { "pemasukan": [...], "pengeluaran": [...], "categories": [...] }
 *
 * GET  api.php                           → baca semua data
 * POST api.php  action=transaksi         → tambah transaksi (masuk ke pemasukan/pengeluaran)
 * POST api.php  action=hapus_transaksi   → hapus transaksi (cari di kedua array)
 * POST api.php  action=tambah_kategori   → tambah kategori pengeluaran
 * POST api.php  action=hapus_kategori    → hapus kategori pengeluaran
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$DATA_FILE = __DIR__ . '/data.json';

/* =====================================================
   FUNGSI BANTU
===================================================== */

function bacaData(string $path): array {
    $default = ['pemasukan' => [], 'pengeluaran' => [], 'categories' => []];

    if (!file_exists($path)) return $default;

    $data = json_decode(file_get_contents($path), true);

    if (!is_array($data)) return $default;

    // Pastikan semua kunci selalu ada
    foreach (['pemasukan', 'pengeluaran', 'categories'] as $key) {
        if (!isset($data[$key])) $data[$key] = [];
    }

    return $data;
}

function simpanData(string $path, array $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;

    $fp = fopen($path, 'c');
    if (!$fp) return false;

    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
    }

    fclose($fp);
    return true;
}

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

    jsonResponse(bacaData($DATA_FILE));

// ——— POST: tentukan aksi berdasarkan field 'action' ———
} elseif ($method === 'POST') {

    $body  = file_get_contents('php://input');
    $input = json_decode($body, true);

    if (!is_array($input)) {
        jsonResponse(['status' => 'error', 'pesan' => 'Body request tidak valid.'], 400);
    }

    $action = trim($input['action'] ?? 'transaksi');

    /* --------------------------------------------------
       AKSI: TAMBAH TRANSAKSI
       Disimpan ke array 'pemasukan' atau 'pengeluaran'
       sesuai jenis yang dipilih.
    -------------------------------------------------- */
    if ($action === 'transaksi') {

        // Validasi tanggal
        $tanggal = trim($input['tanggal'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            jsonResponse(['status' => 'error', 'pesan' => 'Tanggal tidak valid.'], 422);
        }

        // Validasi jenis
        $jenis = trim($input['jenis'] ?? '');
        if (!in_array($jenis, ['pemasukan', 'pengeluaran'], true)) {
            jsonResponse(['status' => 'error', 'pesan' => 'Jenis transaksi tidak valid.'], 422);
        }

        // Validasi jumlah
        $jumlah = isset($input['jumlah']) ? (float) $input['jumlah'] : 0;
        if ($jumlah <= 0) {
            jsonResponse(['status' => 'error', 'pesan' => 'Jumlah harus angka positif.'], 422);
        }

        // Catatan (opsional, maks 500 karakter)
        $catatan = mb_substr(trim($input['catatan'] ?? ''), 0, 500);

        $data = bacaData($DATA_FILE);

        if ($jenis === 'pemasukan') {
            // ── Pemasukan: tidak butuh kategori ──
            $transaksi = [
                'id'      => uniqid('txn_', true),
                'tanggal' => $tanggal,
                'jumlah'  => $jumlah,
                'catatan' => $catatan,
            ];
            $data['pemasukan'][] = $transaksi;

        } else {
            // ── Pengeluaran: simpan snapshot kategori ──
            $kategori_id   = trim($input['kategori_id'] ?? '');
            $kategori_nama = '';

            if ($kategori_id !== '') {
                foreach ($data['categories'] as $cat) {
                    if ($cat['id'] === $kategori_id) {
                        $kategori_nama = $cat['nama'];
                        break;
                    }
                }
                if ($kategori_nama === '') $kategori_id = ''; // ID tidak valid
            }

            $transaksi = [
                'id'            => uniqid('txn_', true),
                'tanggal'       => $tanggal,
                'kategori_id'   => $kategori_id,
                'kategori_nama' => $kategori_nama,
                'jumlah'        => $jumlah,
                'catatan'       => $catatan,
            ];
            $data['pengeluaran'][] = $transaksi;
        }

        if (!simpanData($DATA_FILE, $data)) {
            jsonResponse(['status' => 'error', 'pesan' => 'Gagal menyimpan data.'], 500);
        }

        jsonResponse(['status' => 'ok', 'pesan' => 'Transaksi berhasil disimpan.', 'transaksi' => $transaksi], 201);

    /* --------------------------------------------------
       AKSI: HAPUS TRANSAKSI
       Cari ID di array 'pemasukan' DAN 'pengeluaran'.
    -------------------------------------------------- */
    } elseif ($action === 'hapus_transaksi') {

        $id = trim($input['id'] ?? '');

        if ($id === '') {
            jsonResponse(['status' => 'error', 'pesan' => 'ID transaksi wajib diisi.'], 422);
        }

        $data  = bacaData($DATA_FILE);
        $found = false;

        // Cari dan hapus dari kedua array
        foreach (['pemasukan', 'pengeluaran'] as $arr) {
            $baru = [];
            foreach ($data[$arr] as $t) {
                if ($t['id'] === $id) {
                    $found = true; // Ditemukan — lewati (hapus)
                } else {
                    $baru[] = $t;
                }
            }
            $data[$arr] = $baru;
        }

        if (!$found) {
            jsonResponse(['status' => 'error', 'pesan' => 'Transaksi tidak ditemukan.'], 404);
        }

        if (!simpanData($DATA_FILE, $data)) {
            jsonResponse(['status' => 'error', 'pesan' => 'Gagal menghapus transaksi.'], 500);
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

        $data = bacaData($DATA_FILE);

        // Cek duplikat (case-insensitive)
        foreach ($data['categories'] as $cat) {
            if (mb_strtolower($cat['nama']) === mb_strtolower($nama)) {
                jsonResponse(['status' => 'error', 'pesan' => 'Kategori sudah ada.'], 422);
            }
        }

        $kategori = ['id' => 'cat_' . uniqid(), 'nama' => $nama];
        $data['categories'][] = $kategori;

        if (!simpanData($DATA_FILE, $data)) {
            jsonResponse(['status' => 'error', 'pesan' => 'Gagal menyimpan kategori.'], 500);
        }

        jsonResponse(['status' => 'ok', 'pesan' => 'Kategori berhasil ditambahkan.', 'kategori' => $kategori], 201);

    /* --------------------------------------------------
       AKSI: HAPUS KATEGORI
    -------------------------------------------------- */
    } elseif ($action === 'hapus_kategori') {

        $id = trim($input['id'] ?? '');

        if ($id === '') {
            jsonResponse(['status' => 'error', 'pesan' => 'ID kategori wajib diisi.'], 422);
        }

        $data  = bacaData($DATA_FILE);
        $found = false;
        $baru  = [];

        foreach ($data['categories'] as $cat) {
            if ($cat['id'] === $id) {
                $found = true;
            } else {
                $baru[] = $cat;
            }
        }

        if (!$found) {
            jsonResponse(['status' => 'error', 'pesan' => 'Kategori tidak ditemukan.'], 404);
        }

        $data['categories'] = $baru;

        if (!simpanData($DATA_FILE, $data)) {
            jsonResponse(['status' => 'error', 'pesan' => 'Gagal menghapus kategori.'], 500);
        }

        jsonResponse(['status' => 'ok', 'pesan' => 'Kategori berhasil dihapus.']);

    } else {

        jsonResponse(['status' => 'error', 'pesan' => 'Aksi tidak dikenal.'], 400);
    }

} else {

    jsonResponse(['status' => 'error', 'pesan' => 'Method tidak didukung.'], 405);
}
