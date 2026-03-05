<?php
/**
 * generate_users.php
 * ─────────────────────────────────────────
 * Jalankan file ini SEKALI via browser untuk membuat user.json
 * dengan 2 akun yang sudah ter-hash (bcrypt).
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

$USER_FILE = __DIR__ . '/user.json';

$users = [];
foreach ($accounts as $acc) {
    $users[] = [
        'username'      => $acc['username'],
        'nama'          => $acc['nama'],
        // bcrypt cost 10 — cukup aman, tidak terlalu lambat
        'password_hash' => password_hash($acc['password'], PASSWORD_BCRYPT, ['cost' => 10]),
    ];
}

$json = json_encode(['users' => $users], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$ok   = file_put_contents($USER_FILE, $json);
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

  <?php if ($ok !== false): ?>
    <p class="ok">&#10003; <strong>user.json berhasil dibuat!</strong></p>

    <table>
      <tr><th>Username</th><th>Password</th><th>Nama</th></tr>
      <?php foreach ($accounts as $acc): ?>
        <tr>
          <td><?= htmlspecialchars($acc['username']) ?></td>
          <td><?= htmlspecialchars($acc['password']) ?></td>
          <td><?= htmlspecialchars($acc['nama']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <div class="note">
      &#9888; Password di atas hanya tampil di sini.<br>
      Di <code>user.json</code> sudah tersimpan dalam bentuk <strong>hash bcrypt</strong> (tidak bisa dibaca balik).<br><br>
      <strong>Hapus file <code>generate_users.php</code> sekarang!</strong><br>
      Lalu buka &rarr; <a href="login.php">login.php</a>
    </div>

  <?php else: ?>
    <p class="err">&#10007; Gagal menulis user.json. Periksa izin tulis pada folder.</p>
  <?php endif; ?>
</div>
</body>
</html>
