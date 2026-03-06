<?php
require_once __DIR__ . '/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/koneksi.php';

/* =====================================================
   FILTER TANGGAL (dari GET param)
===================================================== */
$today     = date('Y-m-d');
$bulan_ini = date('Y-m-01');

$filter_dari   = trim($_GET['dari']   ?? '');
$filter_sampai = trim($_GET['sampai'] ?? '');

// Validasi format YYYY-MM-DD; default = bulan ini s/d hari ini
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_dari))   $filter_dari   = $bulan_ini;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_sampai)) $filter_sampai = $today;

// Pastikan dari <= sampai
if ($filter_dari > $filter_sampai) {
    [$filter_dari, $filter_sampai] = [$filter_sampai, $filter_dari];
}

$fd = $conn->real_escape_string($filter_dari);
$fs = $conn->real_escape_string($filter_sampai);
$wp = "WHERE tanggal BETWEEN '$fd' AND '$fs'";  // klausa untuk kedua tabel

// Label rentang untuk header
$is_semua = (isset($_GET['semua']) && $_GET['semua'] == '1');
function fmtTgl($y) {
    $p = explode('-', $y);
    return count($p) === 3 ? $p[2] . '/' . $p[1] . '/' . $p[0] : $y;
}
$label_range = $is_semua ? 'Semua Data' : fmtTgl($filter_dari) . ' – ' . fmtTgl($filter_sampai);

/* =====================================================
   QUERY DATA ANALITIK (semua pakai filter $wp)
===================================================== */

// Jika mode "semua data", hapus WHERE clause
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

// 3. Tren harian pemasukan & pengeluaran
$trenMap = [];
$wp_alias = $wp ? str_replace('WHERE', 'WHERE', $wp) : '';
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
    $per_user_masuk[] = [
        'user'  => $row['user'],
        'total' => (float)$row['total'],
        'cnt'   => (int)$row['cnt'],
    ];
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
    $per_user_keluar[] = [
        'user'  => $row['user'],
        'total' => (float)$row['total'],
        'cnt'   => (int)$row['cnt'],
    ];
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

// Rata-rata per transaksi
$rata_pemasukan   = $cnt_pemasukan   > 0 ? $total_pemasukan   / $cnt_pemasukan   : 0;
$rata_pengeluaran = $cnt_pengeluaran > 0 ? $total_pengeluaran / $cnt_pengeluaran : 0;

// Pass semua data ke JS
$js_data = [
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
    'nama_user'         => $_SESSION['nama'] ?? $_SESSION['username'] ?? '',
    'filter_dari'       => $filter_dari,
    'filter_sampai'     => $filter_sampai,
    'is_semua'          => $is_semua,
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Analisa Keuangan</title>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

  <style>
    /* ===== RESET & BASE ===== */
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Segoe UI', Arial, sans-serif;
      font-size: 16px;
      background: #f0f4f8;
      color: #333;
      padding: 16px 16px 40px;
    }

    .container { max-width: 660px; margin: 0 auto; }

    /* ===== HEADER ===== */
    .app-header {
      background: #2c3e50;
      color: #fff;
      border-radius: 14px;
      padding: 18px 20px;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 4px 16px rgba(44,62,80,0.25);
    }

    .header-left { display: flex; align-items: center; gap: 10px; }

    .header-icon { font-size: 1.6rem; }

    .header-title { font-size: 1.2rem; font-weight: 700; }
    .header-sub   { font-size: 0.75rem; opacity: 0.65; margin-top: 1px; }

    .btn-back {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 7px 14px;
      font-size: 0.78rem;
      font-weight: 700;
      background: rgba(255,255,255,0.12);
      color: #fff;
      border: 1px solid rgba(255,255,255,0.2);
      border-radius: 20px;
      text-decoration: none;
      transition: background 0.2s;
      flex-shrink: 0;
    }
    .btn-back:hover { background: rgba(255,255,255,0.22); }

    /* ===== SECTION TITLE ===== */
    .section-title {
      font-size: 0.78rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: #999;
      margin: 20px 0 10px;
    }

    /* ===== KPI GRID ===== */
    .kpi-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin-bottom: 6px;
    }

    .kpi-card {
      background: #fff;
      border-radius: 12px;
      padding: 16px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.07);
      border: 1.5px solid transparent;
    }

    .kpi-card.saldo    { border-color: #2c3e50; }
    .kpi-card.masuk    { border-color: #27ae60; }
    .kpi-card.keluar   { border-color: #e74c3c; }
    .kpi-card.netral   { border-color: #3498db; }

    .kpi-label {
      font-size: 0.7rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.6px;
      color: #999;
      margin-bottom: 5px;
    }

    .kpi-value {
      font-size: 1.15rem;
      font-weight: 700;
      line-height: 1.2;
    }

    .kpi-card.saldo  .kpi-value { color: #2c3e50; }
    .kpi-card.masuk  .kpi-value { color: #27ae60; }
    .kpi-card.keluar .kpi-value { color: #e74c3c; }
    .kpi-card.netral .kpi-value { color: #3498db; }

    .kpi-value.negatif { color: #e74c3c !important; }

    .kpi-sub {
      font-size: 0.72rem;
      color: #bbb;
      margin-top: 3px;
    }

    /* ===== CHART CARD ===== */
    .chart-card {
      background: #fff;
      border-radius: 12px;
      padding: 18px;
      margin-bottom: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.07);
    }

    .chart-title {
      font-size: 0.88rem;
      font-weight: 700;
      color: #2c3e50;
      margin-bottom: 14px;
      padding-bottom: 10px;
      border-bottom: 2px solid #f0f4f8;
      display: flex;
      align-items: center;
      gap: 7px;
    }

    .chart-title .ct-icon { font-size: 1rem; }

    .chart-wrap {
      position: relative;
      width: 100%;
    }

    /* ===== DONUT ROW (dalam satu card) ===== */
    .donut-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
      align-items: start;
    }

    .donut-item { display: flex; flex-direction: column; align-items: center; }

    .donut-item-label {
      font-size: 0.72rem;
      font-weight: 700;
      color: #999;
      text-transform: uppercase;
      letter-spacing: 0.4px;
      margin-bottom: 8px;
      text-align: center;
    }

    .donut-item .chart-wrap {
      width: 100%;
      max-height: 200px;
    }

    @media (max-width: 480px) {
      .donut-row {
        grid-template-columns: 1fr;
        gap: 24px;
      }
      .donut-item .chart-wrap { max-height: 240px; }
    }

    /* ===== TABEL ===== */
    .table-wrapper { overflow-x: auto; -webkit-overflow-scrolling: touch; }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.83rem;
      min-width: 340px;
    }

    thead th {
      background: #f0f4f8;
      color: #666;
      font-weight: 700;
      text-align: left;
      padding: 9px 11px;
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.4px;
    }

    tbody tr { border-bottom: 1px solid #f4f4f4; transition: background 0.12s; }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: #f8fafc; }
    tbody td { padding: 10px 11px; vertical-align: middle; }

    .td-rank {
      width: 28px;
      text-align: center;
      font-weight: 700;
      color: #bbb;
    }
    .rank-1 { color: #f39c12; }
    .rank-2 { color: #95a5a6; }
    .rank-3 { color: #cd7f32; }

    .td-jumlah-masuk  { font-weight: 700; color: #27ae60; white-space: nowrap; }
    .td-jumlah-keluar { font-weight: 700; color: #e74c3c; white-space: nowrap; }

    .tag-cat {
      display: inline-block;
      padding: 2px 8px;
      background: #eaf4fb;
      color: #1a5276;
      border-radius: 20px;
      font-size: 0.7rem;
      font-weight: 600;
    }

    .tag-user {
      display: inline-block;
      padding: 2px 8px;
      background: #f3eafb;
      color: #6c3483;
      border-radius: 20px;
      font-size: 0.7rem;
      font-weight: 600;
    }

    /* ===== INSIGHT BOX ===== */
    .insight-box {
      background: #eaf4fb;
      border-left: 4px solid #3498db;
      border-radius: 0 8px 8px 0;
      padding: 12px 14px;
      font-size: 0.82rem;
      color: #1a5276;
      margin-top: 14px;
      line-height: 1.6;
    }

    .insight-box strong { color: #154360; }

    /* ===== FILTER BAR ===== */
    .filter-card {
      background: #fff;
      border-radius: 12px;
      padding: 16px;
      margin-bottom: 16px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.07);
      border: 1.5px solid #3498db;
    }

    .filter-card-title {
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.6px;
      color: #3498db;
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .filter-inputs {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin-bottom: 10px;
    }

    .filter-field label {
      display: block;
      font-size: 0.72rem;
      font-weight: 700;
      color: #888;
      margin-bottom: 4px;
    }

    .filter-field input[type=date] {
      width: 100%;
      padding: 9px 11px;
      font-size: 0.9rem;
      border: 1.5px solid #e0e0e0;
      border-radius: 8px;
      background: #fafafa;
      color: #333;
      outline: none;
      transition: border-color 0.2s;
      -webkit-appearance: none;
      appearance: none;
    }

    .filter-field input[type=date]:focus { border-color: #3498db; background: #fff; }

    .preset-pills {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      margin-bottom: 10px;
    }

    .preset-pill {
      padding: 6px 12px;
      font-size: 0.75rem;
      font-weight: 700;
      border: 1.5px solid #e0e0e0;
      border-radius: 20px;
      background: #fff;
      color: #888;
      cursor: pointer;
      transition: all 0.15s;
    }

    .preset-pill:hover        { border-color: #3498db; color: #3498db; background: #eaf4fb; }
    .preset-pill.pill-active  { background: #3498db; color: #fff; border-color: #3498db; }

    .filter-actions { display: flex; gap: 8px; }

    .btn-filter {
      flex: 1;
      padding: 10px;
      font-size: 0.85rem;
      font-weight: 700;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      transition: background 0.2s;
    }

    .btn-filter-apply  { background: #3498db; color: #fff; }
    .btn-filter-apply:hover  { background: #2980b9; }

    .btn-filter-reset  { background: #f0f4f8; color: #666; }
    .btn-filter-reset:hover  { background: #e0e7ef; }

    .filter-badge {
      display: inline-block;
      margin-left: 6px;
      padding: 2px 9px;
      background: #eaf4fb;
      color: #2980b9;
      border-radius: 20px;
      font-size: 0.68rem;
      font-weight: 700;
      vertical-align: middle;
    }

    /* ===== HORIZONTAL SCROLL ROW ===== */
    .hscroll-row {
      display: flex;
      gap: 12px;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      padding-bottom: 6px;
      margin-bottom: 12px;
      scrollbar-width: thin;
      scrollbar-color: #d0d7e2 transparent;
    }
    .hscroll-row::-webkit-scrollbar { height: 5px; }
    .hscroll-row::-webkit-scrollbar-thumb { background: #d0d7e2; border-radius: 4px; }
    .hscroll-row .chart-card {
      flex: 0 0 auto;
      width: 280px;
      margin-bottom: 0;
    }

    /* ===== NO DATA ===== */
    .no-data {
      text-align: center;
      color: #ccc;
      font-style: italic;
      padding: 30px 0;
      font-size: 0.9rem;
    }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 420px) {
      .kpi-value { font-size: 1rem; }
    }

    @media (max-width: 320px) {
      .kpi-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<div class="container">

  <!-- HEADER -->
  <div class="app-header">
    <div class="header-left">
      <span class="header-icon">&#128202;</span>
      <div>
        <div class="header-title">Analisa Keuangan</div>
        <div class="header-sub"><?= htmlspecialchars($label_range) ?></div>
      </div>
    </div>
    <a href="index.php" class="btn-back">&#8592; Kembali</a>
  </div>

  <!-- ===== FILTER TANGGAL ===== -->
  <div class="filter-card">
    <div class="filter-card-title">&#128197; Filter Rentang Tanggal</div>

    <form method="GET" action="analisa.php" id="form-filter">
      <!-- Preset cepat -->
      <div class="preset-pills">
        <button type="button" class="preset-pill" data-preset="hari-ini">Hari Ini</button>
        <button type="button" class="preset-pill" data-preset="minggu-ini">Minggu Ini</button>
        <button type="button" class="preset-pill" data-preset="bulan-ini">Bulan Ini</button>
        <button type="button" class="preset-pill" data-preset="bulan-lalu">Bulan Lalu</button>
        <button type="button" class="preset-pill" data-preset="semua">Semua Data</button>
      </div>

      <!-- Input tanggal manual -->
      <div class="filter-inputs">
        <div class="filter-field">
          <label for="f-dari">Dari Tanggal</label>
          <input type="date" id="f-dari" name="dari"
                 value="<?= htmlspecialchars($filter_dari) ?>">
        </div>
        <div class="filter-field">
          <label for="f-sampai">Sampai Tanggal</label>
          <input type="date" id="f-sampai" name="sampai"
                 value="<?= htmlspecialchars($filter_sampai) ?>">
        </div>
      </div>
      <input type="hidden" name="semua" id="f-semua" value="<?= $is_semua ? '1' : '0' ?>">

      <div class="filter-actions">
        <button type="submit" class="btn-filter btn-filter-apply">&#128269; Terapkan Filter</button>
        <button type="button" class="btn-filter btn-filter-reset" id="btn-reset-filter">&#8635; Reset</button>
      </div>
    </form>
  </div>

  <!-- ===== KPI CARDS ===== -->
  <div class="section-title">
    Ringkasan Keseluruhan
    <span class="filter-badge">&#128197; <?= htmlspecialchars($label_range) ?></span>
  </div>

  <div class="kpi-grid">
    <div class="kpi-card saldo">
      <div class="kpi-label">Saldo Bersih</div>
      <div class="kpi-value" id="kpi-saldo">—</div>
      <div class="kpi-sub" id="kpi-saldo-sub"></div>
    </div>
    <div class="kpi-card netral">
      <div class="kpi-label">Total Transaksi</div>
      <div class="kpi-value" id="kpi-total-trx">—</div>
      <div class="kpi-sub" id="kpi-total-trx-sub"></div>
    </div>
    <div class="kpi-card masuk">
      <div class="kpi-label">Total Pemasukan</div>
      <div class="kpi-value" id="kpi-pemasukan">—</div>
      <div class="kpi-sub" id="kpi-pemasukan-sub"></div>
    </div>
    <div class="kpi-card keluar">
      <div class="kpi-label">Total Pengeluaran</div>
      <div class="kpi-value" id="kpi-pengeluaran">—</div>
      <div class="kpi-sub" id="kpi-pengeluaran-sub"></div>
    </div>
  </div>

  <!-- ===== DONUT + KATEGORI (scroll samping) ===== -->
  <div class="section-title">Komposisi &amp; Kategori</div>

  <div class="hscroll-row">
    <!-- Donut: rasio -->
    <div class="chart-card">
      <div class="chart-title"><span class="ct-icon">&#11096;</span> Rasio</div>
      <div class="donut-item-label" style="font-size:0.68rem;font-weight:700;color:#999;text-transform:uppercase;letter-spacing:0.4px;text-align:center;margin-bottom:8px;">Pemasukan vs Pengeluaran</div>
      <div class="chart-wrap">
        <canvas id="chart-donut"></canvas>
      </div>
    </div>

    <!-- Donut: per kategori -->
    <div class="chart-card">
      <div class="chart-title"><span class="ct-icon">&#11096;</span> Per Kategori</div>
      <div class="chart-wrap">
        <canvas id="chart-kategori-donut"></canvas>
      </div>
    </div>

  </div>

  <!-- Bar: pengeluaran per kategori -->
  <div class="chart-card">
    <div class="chart-title"><span class="ct-icon">&#128201;</span> Pengeluaran per Kategori</div>
    <div class="chart-wrap">
      <canvas id="chart-kategori-bar"></canvas>
    </div>
  </div>

  <!-- ===== TREN HARIAN ===== -->
  <div class="section-title">Tren Harian</div>

  <div class="chart-card">
    <div class="chart-title"><span class="ct-icon">&#128200;</span> Pemasukan vs Pengeluaran per Hari</div>
    <div class="chart-wrap">
      <canvas id="chart-tren"></canvas>
    </div>
  </div>

  <div class="chart-card">
    <div class="chart-title"><span class="ct-icon">&#128176;</span> Saldo Kumulatif</div>
    <div class="chart-wrap">
      <canvas id="chart-saldo-kumulatif"></canvas>
    </div>
  </div>

  <!-- ===== KONTRIBUSI USER ===== -->
  <div class="section-title">Kontribusi Per Pengguna</div>

  <div class="chart-card">
    <div class="chart-title"><span class="ct-icon">&#128101;</span> Pemasukan &amp; Pengeluaran per Pengguna</div>
    <div class="chart-wrap">
      <canvas id="chart-user"></canvas>
    </div>
    <div class="insight-box" id="insight-user"></div>
  </div>

  <!-- ===== TOP TRANSAKSI ===== -->
  <div class="section-title">Top Transaksi</div>

  <div class="chart-card">
    <div class="chart-title"><span class="ct-icon">&#128201;</span> 5 Pengeluaran Terbesar</div>
    <div class="table-wrapper">
      <table id="tbl-top-keluar">
        <thead>
          <tr>
            <th class="td-rank">#</th>
            <th>Tanggal</th>
            <th>Kategori</th>
            <th>Jumlah</th>
            <th>Catatan</th>
            <th>Oleh</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>

  <div class="chart-card">
    <div class="chart-title"><span class="ct-icon">&#128200;</span> 5 Pemasukan Terbesar</div>
    <div class="table-wrapper">
      <table id="tbl-top-masuk">
        <thead>
          <tr>
            <th class="td-rank">#</th>
            <th>Tanggal</th>
            <th>Jumlah</th>
            <th>Catatan</th>
            <th>Oleh</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>

</div><!-- /container -->

<script>
/* ============================================================
   FILTER PRESET BUTTONS
============================================================ */
(function () {
  const dari   = document.getElementById('f-dari');
  const sampai = document.getElementById('f-sampai');
  const fSemua = document.getElementById('f-semua');

  function pad(n) { return String(n).padStart(2, '0'); }
  function fmt(d) { return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()); }

  const presets = {
    'hari-ini': function() {
      const t = new Date();
      return [fmt(t), fmt(t)];
    },
    'minggu-ini': function() {
      const t = new Date();
      const day = t.getDay() || 7;          // Senin = 1
      const mon = new Date(t);
      mon.setDate(t.getDate() - day + 1);
      return [fmt(mon), fmt(t)];
    },
    'bulan-ini': function() {
      const t = new Date();
      const awal = new Date(t.getFullYear(), t.getMonth(), 1);
      return [fmt(awal), fmt(t)];
    },
    'bulan-lalu': function() {
      const t = new Date();
      const awal = new Date(t.getFullYear(), t.getMonth() - 1, 1);
      const akhir = new Date(t.getFullYear(), t.getMonth(), 0);
      return [fmt(awal), fmt(akhir)];
    },
    'semua': function() { return [null, null]; }
  };

  // Tandai preset yang sedang aktif
  const curDari   = dari.value;
  const curSampai = sampai.value;
  const isSemua   = fSemua.value === '1';

  document.querySelectorAll('.preset-pill').forEach(function(btn) {
    const key = btn.dataset.preset;
    if (key === 'semua' && isSemua) {
      btn.classList.add('pill-active');
      return;
    }
    if (!isSemua && presets[key]) {
      const [d, s] = presets[key]();
      if (d && s && d === curDari && s === curSampai) {
        btn.classList.add('pill-active');
      }
    }

    btn.addEventListener('click', function() {
      document.querySelectorAll('.preset-pill').forEach(p => p.classList.remove('pill-active'));
      this.classList.add('pill-active');

      if (key === 'semua') {
        fSemua.value = '1';
        // Set nilai input ke seluruh rentang agar form tetap valid
        dari.value   = '2000-01-01';
        sampai.value = new Date().toISOString().split('T')[0];
      } else {
        fSemua.value = '0';
        const [d, s] = presets[key]();
        dari.value   = d;
        sampai.value = s;
      }
    });
  });

  // Reset ke default (bulan ini)
  document.getElementById('btn-reset-filter').addEventListener('click', function() {
    const t = new Date();
    const awal = new Date(t.getFullYear(), t.getMonth(), 1);
    dari.value   = fmt(awal);
    sampai.value = fmt(t);
    fSemua.value = '0';
    document.getElementById('form-filter').submit();
  });

  // Auto-submit saat input tanggal berubah langsung
  [dari, sampai].forEach(function(el) {
    el.addEventListener('change', function() {
      fSemua.value = '0';
      document.querySelectorAll('.preset-pill').forEach(p => p.classList.remove('pill-active'));
    });
  });
})();

/* ============================================================
   DATA DARI PHP
============================================================ */
const DATA = <?= json_encode($js_data, JSON_UNESCAPED_UNICODE) ?>;

/* ============================================================
   UTILITAS
============================================================ */
function rp(angka) {
  if (Math.abs(angka) >= 1_000_000) {
    return 'Rp ' + (Math.abs(angka) / 1_000_000).toFixed(1).replace('.', ',') + ' jt';
  }
  return 'Rp ' + Math.abs(angka).toLocaleString('id-ID');
}

function rpFull(angka) {
  return 'Rp ' + Math.abs(angka).toLocaleString('id-ID');
}

function tgl(str) {
  if (!str) return '-';
  const p = str.split('-');
  return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : str;
}

function rankClass(i) {
  return i === 0 ? 'rank-1' : i === 1 ? 'rank-2' : i === 2 ? 'rank-3' : '';
}

function escHtml(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ============================================================
   KPI CARDS
============================================================ */
(function () {
  const saldo = DATA.saldo;
  const elSaldo = document.getElementById('kpi-saldo');
  elSaldo.textContent = (saldo < 0 ? '−' : '') + rpFull(saldo);
  if (saldo < 0) elSaldo.classList.add('negatif');

  const pct = DATA.total_pemasukan > 0
    ? ((saldo / DATA.total_pemasukan) * 100).toFixed(1)
    : 0;
  document.getElementById('kpi-saldo-sub').textContent = pct + '% dari pemasukan';

  document.getElementById('kpi-total-trx').textContent = DATA.total_transaksi + ' transaksi';
  document.getElementById('kpi-total-trx-sub').textContent =
    DATA.cnt_pemasukan + ' masuk · ' + DATA.cnt_pengeluaran + ' keluar';

  document.getElementById('kpi-pemasukan').textContent = rp(DATA.total_pemasukan);
  document.getElementById('kpi-pemasukan-sub').textContent =
    'Rata-rata ' + rp(DATA.rata_pemasukan) + '/trx';

  document.getElementById('kpi-pengeluaran').textContent = rp(DATA.total_pengeluaran);
  document.getElementById('kpi-pengeluaran-sub').textContent =
    'Rata-rata ' + rp(DATA.rata_pengeluaran) + '/trx';
})();

/* ============================================================
   WARNA PALETTE
============================================================ */
const PALETTE = [
  '#3498db','#e67e22','#9b59b6','#1abc9c',
  '#e74c3c','#f39c12','#2ecc71','#16a085',
  '#d35400','#8e44ad','#2980b9','#27ae60',
];

const CHART_DEFAULTS = {
  plugins: {
    legend: {
      labels: { font: { family: "'Segoe UI', Arial, sans-serif", size: 11 }, boxWidth: 12, padding: 10 }
    },
    tooltip: {
      callbacks: {
        label: ctx => ' ' + rpFull(ctx.raw)
      }
    }
  }
};

/* ============================================================
   CHART 1 — DONUT: Rasio Pemasukan vs Pengeluaran
============================================================ */
new Chart(document.getElementById('chart-donut'), {
  type: 'doughnut',
  data: {
    labels: ['Pemasukan', 'Pengeluaran'],
    datasets: [{
      data: [DATA.total_pemasukan, DATA.total_pengeluaran],
      backgroundColor: ['#27ae60', '#e74c3c'],
      borderWidth: 2,
      borderColor: '#fff',
    }]
  },
  options: {
    cutout: '62%',
    maintainAspectRatio: true,
    plugins: {
      legend: {
        position: 'bottom',
        labels: { font: { size: 10 }, boxWidth: 10, padding: 6, usePointStyle: true }
      },
      tooltip: { callbacks: { label: ctx => ' ' + rp(ctx.raw) } }
    }
  }
});

/* ============================================================
   CHART 2 — DONUT: Pengeluaran per Kategori
============================================================ */
(function () {
  const labels = DATA.per_kategori.map(d => d.kategori);
  const values = DATA.per_kategori.map(d => d.total);
  const colors = labels.map((_, i) => PALETTE[i % PALETTE.length]);

  if (labels.length === 0) {
    document.getElementById('chart-kategori-donut').parentElement.innerHTML =
      '<div class="no-data">Belum ada data pengeluaran</div>';
    return;
  }

  new Chart(document.getElementById('chart-kategori-donut'), {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{ data: values, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }]
    },
    options: {
      cutout: '58%',
      maintainAspectRatio: true,
      plugins: {
        legend: {
          position: 'bottom',
          labels: { font: { size: 10 }, boxWidth: 10, padding: 4, usePointStyle: true }
        },
        tooltip: { callbacks: { label: ctx => ' ' + rp(ctx.raw) } }
      }
    }
  });
})();

/* ============================================================
   CHART 3 — BAR HORIZONTAL: Pengeluaran per Kategori
============================================================ */
(function () {
  const labels = DATA.per_kategori.map(d => d.kategori);
  const values = DATA.per_kategori.map(d => d.total);
  const colors = labels.map((_, i) => PALETTE[i % PALETTE.length]);

  if (labels.length === 0) {
    document.getElementById('chart-kategori-bar').parentElement.innerHTML =
      '<div class="no-data">Belum ada data kategori</div>';
    return;
  }

  new Chart(document.getElementById('chart-kategori-bar'), {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Pengeluaran',
        data: values,
        backgroundColor: colors.map(c => c + 'cc'),
        borderColor: colors,
        borderWidth: 1.5,
        borderRadius: 6,
      }]
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: ctx => ' ' + rpFull(ctx.raw) } }
      },
      scales: {
        x: {
          ticks: { callback: v => rp(v), font: { size: 10 } },
          grid: { color: '#f0f4f8' }
        },
        y: { ticks: { font: { size: 11 } }, grid: { display: false } }
      }
    }
  });
})();

/* ============================================================
   CHART 4 — LINE: Tren Harian
============================================================ */
(function () {
  if (DATA.tren_labels.length === 0) {
    document.getElementById('chart-tren').parentElement.innerHTML =
      '<div class="no-data">Belum ada data transaksi</div>';
    return;
  }

  new Chart(document.getElementById('chart-tren'), {
    type: 'bar',
    data: {
      labels: DATA.tren_labels,
      datasets: [
        {
          label: 'Pemasukan',
          data: DATA.tren_masuk,
          backgroundColor: '#27ae6066',
          borderColor: '#27ae60',
          borderWidth: 2,
          borderRadius: 5,
          order: 2,
        },
        {
          label: 'Pengeluaran',
          data: DATA.tren_keluar,
          backgroundColor: '#e74c3c66',
          borderColor: '#e74c3c',
          borderWidth: 2,
          borderRadius: 5,
          order: 1,
        }
      ]
    },
    options: {
      responsive: true,
      plugins: {
        legend: {
          position: 'top',
          labels: { font: { size: 11 }, boxWidth: 12, padding: 10 }
        },
        tooltip: {
          callbacks: {
            label: ctx => ' ' + ctx.dataset.label + ': ' + rpFull(ctx.raw)
          }
        }
      },
      scales: {
        x: { ticks: { font: { size: 10 } }, grid: { display: false } },
        y: {
          ticks: { callback: v => rp(v), font: { size: 10 } },
          grid: { color: '#f0f4f8' }
        }
      }
    }
  });
})();

/* ============================================================
   CHART 5 — LINE: Saldo Kumulatif
============================================================ */
(function () {
  if (DATA.tren_labels.length === 0) {
    document.getElementById('chart-saldo-kumulatif').parentElement.innerHTML =
      '<div class="no-data">Belum ada data</div>';
    return;
  }

  const finalSaldo = DATA.saldo_kumulatif[DATA.saldo_kumulatif.length - 1] || 0;
  const borderColor = finalSaldo >= 0 ? '#2c3e50' : '#e74c3c';
  const bgColor     = finalSaldo >= 0 ? '#2c3e5015' : '#e74c3c15';

  new Chart(document.getElementById('chart-saldo-kumulatif'), {
    type: 'line',
    data: {
      labels: DATA.tren_labels,
      datasets: [{
        label: 'Saldo',
        data: DATA.saldo_kumulatif,
        borderColor: borderColor,
        backgroundColor: bgColor,
        borderWidth: 2.5,
        pointRadius: 4,
        pointBackgroundColor: borderColor,
        fill: true,
        tension: 0.35,
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => ' Saldo: ' + (ctx.raw < 0 ? '−' : '') + rpFull(ctx.raw)
          }
        }
      },
      scales: {
        x: { ticks: { font: { size: 10 } }, grid: { display: false } },
        y: {
          ticks: { callback: v => rp(v), font: { size: 10 } },
          grid: { color: '#f0f4f8' }
        }
      }
    }
  });
})();

/* ============================================================
   CHART 6 — BAR GROUPED: Kontribusi per User
============================================================ */
(function () {
  const users = DATA.all_users;
  if (users.length === 0) {
    document.getElementById('chart-user').parentElement.innerHTML =
      '<div class="no-data">Belum ada data pengguna</div>';
    return;
  }

  new Chart(document.getElementById('chart-user'), {
    type: 'bar',
    data: {
      labels: users,
      datasets: [
        {
          label: 'Pemasukan',
          data: DATA.user_masuk,
          backgroundColor: '#27ae6099',
          borderColor: '#27ae60',
          borderWidth: 1.5,
          borderRadius: 5,
        },
        {
          label: 'Pengeluaran',
          data: DATA.user_keluar,
          backgroundColor: '#e74c3c99',
          borderColor: '#e74c3c',
          borderWidth: 1.5,
          borderRadius: 5,
        }
      ]
    },
    options: {
      responsive: true,
      plugins: {
        legend: {
          position: 'top',
          labels: { font: { size: 11 }, boxWidth: 12, padding: 10 }
        },
        tooltip: {
          callbacks: {
            label: ctx => ' ' + ctx.dataset.label + ': ' + rpFull(ctx.raw)
          }
        }
      },
      scales: {
        x: { ticks: { font: { size: 11 } }, grid: { display: false } },
        y: {
          ticks: { callback: v => rp(v), font: { size: 10 } },
          grid: { color: '#f0f4f8' }
        }
      }
    }
  });

  // Insight teks
  const topMasukIdx  = DATA.user_masuk.indexOf(Math.max(...DATA.user_masuk));
  const topKeluarIdx = DATA.user_keluar.indexOf(Math.max(...DATA.user_keluar));
  const insight = [];
  if (users[topMasukIdx]) {
    insight.push('<strong>' + escHtml(users[topMasukIdx]) + '</strong> memasukkan pemasukan terbesar ('
      + rpFull(DATA.user_masuk[topMasukIdx]) + ').');
  }
  if (users[topKeluarIdx]) {
    insight.push('<strong>' + escHtml(users[topKeluarIdx]) + '</strong> mencatat pengeluaran terbanyak ('
      + rpFull(DATA.user_keluar[topKeluarIdx]) + ').');
  }
  document.getElementById('insight-user').innerHTML = insight.join(' ') || 'Tidak ada data pengguna.';
})();

/* ============================================================
   TABEL: Top 5 Pengeluaran Terbesar
============================================================ */
(function () {
  const tbody = document.querySelector('#tbl-top-keluar tbody');
  if (!DATA.top_pengeluaran.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="no-data">Belum ada pengeluaran</td></tr>';
    return;
  }
  DATA.top_pengeluaran.forEach((t, i) => {
    const userHtml = t.diinput_oleh
      ? '<span class="tag-user">' + escHtml(t.diinput_oleh) + '</span>'
      : '<em style="color:#ccc">-</em>';
    const catHtml = t.kategori_nama && t.kategori_nama !== '-'
      ? '<span class="tag-cat">' + escHtml(t.kategori_nama) + '</span>'
      : '<em style="color:#ccc">-</em>';
    const catatan = t.catatan ? escHtml(t.catatan.substring(0, 20)) + (t.catatan.length > 20 ? '…' : '') : '-';
    tbody.innerHTML +=
      '<tr>' +
        '<td class="td-rank ' + rankClass(i) + '">' + (i + 1) + '</td>' +
        '<td>' + tgl(t.tanggal) + '</td>' +
        '<td>' + catHtml + '</td>' +
        '<td class="td-jumlah-keluar">' + rpFull(t.jumlah) + '</td>' +
        '<td style="color:#666;font-size:0.8rem">' + catatan + '</td>' +
        '<td>' + userHtml + '</td>' +
      '</tr>';
  });
})();

/* ============================================================
   TABEL: Top 5 Pemasukan Terbesar
============================================================ */
(function () {
  const tbody = document.querySelector('#tbl-top-masuk tbody');
  if (!DATA.top_pemasukan.length) {
    tbody.innerHTML = '<tr><td colspan="5" class="no-data">Belum ada pemasukan</td></tr>';
    return;
  }
  DATA.top_pemasukan.forEach((t, i) => {
    const userHtml = t.diinput_oleh
      ? '<span class="tag-user">' + escHtml(t.diinput_oleh) + '</span>'
      : '<em style="color:#ccc">-</em>';
    const catatan = t.catatan ? escHtml(t.catatan.substring(0, 25)) + (t.catatan.length > 25 ? '…' : '') : '-';
    tbody.innerHTML +=
      '<tr>' +
        '<td class="td-rank ' + rankClass(i) + '">' + (i + 1) + '</td>' +
        '<td>' + tgl(t.tanggal) + '</td>' +
        '<td class="td-jumlah-masuk">' + rpFull(t.jumlah) + '</td>' +
        '<td style="color:#666;font-size:0.8rem">' + catatan + '</td>' +
        '<td>' + userHtml + '</td>' +
      '</tr>';
  });
})();
</script>

</body>
</html>
