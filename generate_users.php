<?php
/**
 * generate_users.php
 * ─────────────────────────────────────────
 * Jalankan file ini SEKALI via browser untuk membuat akun user
 * di tabel MySQL.
 *
 * Setelah berhasil → HAPUS file ini!
 * ─────────────────────────────────────────
 */

// ── Daftar akun statis ──────────────────────────────────────────
// Ubah username/password di sini sebelum dijalankan jika perlu.
$accounts = [
    [
        'username' => 'Ade',
        'nama'     => 'Istri',
        'password' => 'Ade12345',
    ],
    [
        'username' => 'Feri',
        'nama'     => 'Suami',
        'password' => 'Feri12345',
    ],
];
// ────────────────────────────────────────────────────────────────

require_once __DIR__ . '/koneksi.php';

$results = [];

foreach ($accounts as $acc) {
    $hash = password_hash($acc['password'], PASSWORD_BCRYPT, ['cost' => 10]);

    $stmt = $conn->prepare(
        "INSERT INTO users (username, nama, password_hash) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE nama = VALUES(nama), password_hash = VALUES(password_hash)"
    );
    $stmt->bind_param('sss', $acc['username'], $acc['nama'], $hash);
    $ok = $stmt->execute();
    $stmt->close();

    $results[] = ['acc' => $acc, 'ok' => $ok];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Generate Users</title>
  <style>
    body { font-family: monospace; background:#1e1e2e; color:#cdd6f4; padding:30px; }
    .box { max-width:480px; margin:auto; background:#313244; border-radius:10px; padding:24px; }
    h2   { color:#89b4fa; margin-bottom:16px; }
    .ok  { color:#a6e3a1; }
    .err { color:#f38ba8; }
    table { width:100%; border-collapse:collapse; margin-top:14px; }
    th, td { padding:8px 12px; text-align:left; border-bottom:1px solid #45475a; }
    th { color:#89dceb; font-size:0.85rem; }
    td { font-size:0.9rem; }
    .note { margin-top:18px; background:#45475a; border-radius:6px; padding:12px; font-size:0.82rem; color:#f9e2af; line-height:1.6; }
    a { color:#89b4fa; }
  </style>
</head>
<body>
<div class="box">
  <h2>&#128274; Generate Users</h2>

  <table>
    <tr><th>Username</th><th>Nama</th><th>Status</th></tr>
    <?php foreach ($results as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['acc']['username']) ?></td>
        <td><?= htmlspecialchars($r['acc']['nama']) ?></td>
        <td class="<?= $r['ok'] ? 'ok' : 'err' ?>">
          <?= $r['ok'] ? '&#10003; Berhasil' : '&#10007; Gagal' ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

  <div class="note">
    &#9888; Password disimpan dalam bentuk <strong>hash bcrypt</strong> di database.<br><br>
    <strong>Hapus file <code>generate_users.php</code> sekarang!</strong><br>
    Lalu buka &rarr; <a href="login.php">login.php</a>
  </div>
</div>
</body>
</html>
