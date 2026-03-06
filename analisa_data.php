<?php
require_once __DIR__ . '/auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/koneksi.php';

header('Content-Type: application/json; charset=utf-8');

$today     = date('Y-m-d');
$bulan_ini = date('Y-m-01');

$filter_dari   = trim($_GET['dari']   ?? '');
$filter_sampai = trim($_GET['sampai'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_dari))   $filter_dari   = $bulan_ini;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_sampai)) $filter_sampai = $today;

if ($filter_dari > $filter_sampai) {
    [$filter_dari, $filter_sampai] = [$filter_sampai, $filter_dari];
}

$fd = $conn->real_escape_string($filter_dari);
$fs = $conn->real_escape_string($filter_sampai);
$wp = "WHERE tanggal BETWEEN '$fd' AND '$fs'";

$is_semua = (isset($_GET['semua']) && $_GET['semua'] == '1');

if ($is_semua) $wp = '';

// 1. Overview totals
$res = $conn->query("SELECT COALESCE(SUM(jumlah),0) AS total, COUNT(*) AS cnt FROM pemasukan $wp");
$row = $res->fetch_assoc();
$total_pemasukan = (float)$row['total'];
$cnt_pemasukan   = (int)$row['cnt'];

$res = $conn->query("SELECT COALESCE(SUM(jumlah),0) AS total, COUNT(*) AS cnt FROM pengeluaran $wp");
$row = $res->fetch_assoc();
$total_pengeluaran = (float)$row['total'];
$cnt_pengeluaran   = (int)$row['cnt'];

$saldo           = $total_pemasukan - $total_pengeluaran;
$total_transaksi = $cnt_pemasukan + $cnt_pengeluaran;

// 2. Pengeluaran per kategori
$per_kategori = [];
$res = $conn->query(
    "SELECT COALESCE(NULLIF(kategori_nama,''), 'Tanpa Kategori') AS kategori,
            SUM(jumlah) AS total, COUNT(*) AS cnt
     FROM pengeluaran $wp
     GROUP BY kategori
     ORDER BY total DESC"
);
while ($row = $res->fetch_assoc()) {
    $per_kategori[] = [
        'kategori' => $row['kategori'],
        'total'    => (float)$row['total'],
        'cnt'      => (int)$row['cnt'],
    ];
}

// 3. Tren harian
$trenMap = [];
$res = $conn->query(
    "SELECT tanggal, SUM(jumlah) AS jumlah, 'pemasukan' AS jenis FROM pemasukan $wp GROUP BY tanggal
     UNION ALL
     SELECT tanggal, SUM(jumlah) AS jumlah, 'pengeluaran' AS jenis FROM pengeluaran $wp GROUP BY tanggal
     ORDER BY tanggal"
);
while ($row = $res->fetch_assoc()) {
    $tgl = $row['tanggal'];
    if (!isset($trenMap[$tgl])) $trenMap[$tgl] = ['masuk' => 0, 'keluar' => 0];
    if ($row['jenis'] === 'pemasukan') $trenMap[$tgl]['masuk']  += (float)$row['jumlah'];
    else                               $trenMap[$tgl]['keluar'] += (float)$row['jumlah'];
}
ksort($trenMap);

$tren_labels = [];
$tren_masuk  = [];
$tren_keluar = [];
foreach ($trenMap as $tgl => $val) {
    $parts = explode('-', $tgl);
    $tren_labels[] = $parts[2] . '/' . $parts[1];
    $tren_masuk[]  = $val['masuk'];
    $tren_keluar[] = $val['keluar'];
}

// 4. Saldo kumulatif harian
$saldo_kumulatif = [];
$running = 0;
foreach ($tren_masuk as $i => $m) {
    $running += $m - $tren_keluar[$i];
    $saldo_kumulatif[] = $running;
}

// 5. Kontribusi per user — pemasukan
$per_user_masuk = [];
$res = $conn->query(
    "SELECT COALESCE(NULLIF(diinput_oleh,''), 'Tidak diketahui') AS user,
            SUM(jumlah) AS total, COUNT(*) AS cnt
     FROM pemasukan $wp
     GROUP BY user
     ORDER BY total DESC"
);
while ($row = $res->fetch_assoc()) {
    $per_user_masuk[] = ['user' => $row['user'], 'total' => (float)$row['total'], 'cnt' => (int)$row['cnt']];
}

// 6. Kontribusi per user — pengeluaran
$per_user_keluar = [];
$res = $conn->query(
    "SELECT COALESCE(NULLIF(diinput_oleh,''), 'Tidak diketahui') AS user,
            SUM(jumlah) AS total, COUNT(*) AS cnt
     FROM pengeluaran $wp
     GROUP BY user
     ORDER BY total DESC"
);
while ($row = $res->fetch_assoc()) {
    $per_user_keluar[] = ['user' => $row['user'], 'total' => (float)$row['total'], 'cnt' => (int)$row['cnt']];
}

// 7. Gabungan semua user
$all_users_raw = array_unique(array_merge(
    array_column($per_user_masuk,  'user'),
    array_column($per_user_keluar, 'user')
));
sort($all_users_raw);

$user_masuk_map  = array_column($per_user_masuk,  'total', 'user');
$user_keluar_map = array_column($per_user_keluar, 'total', 'user');

$all_users       = array_values($all_users_raw);
$user_masuk_arr  = array_map(fn($u) => $user_masuk_map[$u]  ?? 0, $all_users);
$user_keluar_arr = array_map(fn($u) => $user_keluar_map[$u] ?? 0, $all_users);

// 8. Top 5 pengeluaran terbesar
$top_pengeluaran = [];
$res = $conn->query(
    "SELECT tanggal, COALESCE(NULLIF(kategori_nama,''),'-') AS kategori_nama,
            jumlah, catatan, diinput_oleh
     FROM pengeluaran $wp
     ORDER BY jumlah DESC
     LIMIT 5"
);
while ($row = $res->fetch_assoc()) {
    $row['jumlah'] = (float)$row['jumlah'];
    $top_pengeluaran[] = $row;
}

// 9. Top 5 pemasukan terbesar
$top_pemasukan = [];
$res = $conn->query(
    "SELECT tanggal, jumlah, catatan, diinput_oleh
     FROM pemasukan $wp
     ORDER BY jumlah DESC
     LIMIT 5"
);
while ($row = $res->fetch_assoc()) {
    $row['jumlah'] = (float)$row['jumlah'];
    $top_pemasukan[] = $row;
}

$rata_pemasukan   = $cnt_pemasukan   > 0 ? $total_pemasukan   / $cnt_pemasukan   : 0;
$rata_pengeluaran = $cnt_pengeluaran > 0 ? $total_pengeluaran / $cnt_pengeluaran : 0;

echo json_encode([
    'total_pemasukan'   => $total_pemasukan,
    'total_pengeluaran' => $total_pengeluaran,
    'saldo'             => $saldo,
    'cnt_pemasukan'     => $cnt_pemasukan,
    'cnt_pengeluaran'   => $cnt_pengeluaran,
    'total_transaksi'   => $total_transaksi,
    'rata_pemasukan'    => $rata_pemasukan,
    'rata_pengeluaran'  => $rata_pengeluaran,
    'per_kategori'      => $per_kategori,
    'tren_labels'       => $tren_labels,
    'tren_masuk'        => $tren_masuk,
    'tren_keluar'       => $tren_keluar,
    'saldo_kumulatif'   => $saldo_kumulatif,
    'all_users'         => $all_users,
    'user_masuk'        => $user_masuk_arr,
    'user_keluar'       => $user_keluar_arr,
    'top_pengeluaran'   => $top_pengeluaran,
    'top_pemasukan'     => $top_pemasukan,
    'filter_dari'       => $filter_dari,
    'filter_sampai'     => $filter_sampai,
    'is_semua'          => $is_semua,
], JSON_UNESCAPED_UNICODE);
