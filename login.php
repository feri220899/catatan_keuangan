<?php
require_once __DIR__ . '/auth.php';

// Sudah login → langsung ke app
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

// ── Proses form POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        require_once __DIR__ . '/koneksi.php';

        $stmt = $conn->prepare("SELECT username, nama, password_hash FROM users WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $stmt->bind_result($db_username, $db_nama, $db_hash);
        $found = $stmt->fetch();
        $stmt->close();

        if ($found && password_verify($password, $db_hash)) {
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['username']  = $db_username;
            $_SESSION['nama']      = $db_nama ?: $db_username;
            header('Location: index.php');
            exit;
        } else {
            $error = 'Username atau password salah.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — Catatan Keuangan</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Segoe UI', Arial, sans-serif;
      background: #f0f4f8;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .wrap { width: 100%; max-width: 360px; }

    .header { text-align: center; margin-bottom: 22px; }
    .header .icon { font-size: 2.6rem; }
    .header h1 { font-size: 1.3rem; font-weight: 700; color: #2c3e50; margin-top: 8px; }
    .header p  { font-size: 0.8rem; color: #aaa; margin-top: 3px; }

    .card {
      background: #fff;
      border-radius: 14px;
      padding: 26px 22px;
      box-shadow: 0 4px 18px rgba(0,0,0,0.09);
    }

    .error-box {
      background: #f8d7da;
      color: #721c24;
      border-radius: 8px;
      padding: 10px 13px;
      font-size: 0.85rem;
      font-weight: 600;
      margin-bottom: 14px;
    }

    .form-group { margin-bottom: 14px; }

    .form-group label {
      display: block;
      font-size: 0.8rem;
      font-weight: 700;
      color: #555;
      margin-bottom: 5px;
    }

    .form-group input {
      width: 100%;
      padding: 11px 13px;
      font-size: 0.95rem;
      border: 1.5px solid #e0e0e0;
      border-radius: 8px;
      background: #fafafa;
      color: #333;
      outline: none;
      transition: border-color 0.2s;
      -webkit-appearance: none;
    }

    .form-group input:focus { border-color: #2c3e50; background: #fff; }

    .btn-masuk {
      width: 100%;
      padding: 12px;
      font-size: 1rem;
      font-weight: 700;
      background: #2c3e50;
      color: #fff;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      transition: background 0.2s;
      margin-top: 4px;
    }

    .btn-masuk:hover { background: #3d5166; }
  </style>
</head>
<body>

<div class="wrap">
  <div class="header">
    <div class="icon">&#128181;</div>
    <h1>Catatan Keuangan</h1>
    <p>Masuk untuk melanjutkan</p>
  </div>

  <div class="card">
    <?php if ($error): ?>
      <div class="error-box">&#10007; <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
               placeholder="Masukkan username" required autofocus>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               placeholder="Masukkan password" required>
      </div>

      <button type="submit" class="btn-masuk">Masuk</button>
    </form>
  </div>
</div>

</body>
</html>
