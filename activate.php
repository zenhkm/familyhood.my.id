<?php
// File: activate.php
require_once 'init_session.php';

$msg = '';
$type = 'error';

if (isset($_GET['email']) && isset($_GET['code'])) {
    $email = $_GET['email'];
    $code  = $_GET['code'];

    $mysqli = new mysqli('localhost', 'quic1934_zenhkm', '03Maret1990', 'quic1934_familyhood');
    if ($mysqli->connect_error) die("DB Error");

    // Cari user dengan email dan kode tersebut yang belum aktif
    $stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ? AND activation_code = ? AND is_active = 0");
    $stmt->bind_param("ss", $email, $code);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        // Ketemu! Aktifkan User
        $stmtUpd = $mysqli->prepare("UPDATE users SET is_active = 1, activation_code = NULL WHERE email = ?");
        $stmtUpd->bind_param("s", $email);
        
        if ($stmtUpd->execute()) {
            $type = 'success';
            $msg = "Selamat! Akun Anda telah aktif. Silakan login.";
        } else {
            $msg = "Terjadi kesalahan saat mengaktifkan akun.";
        }
    } else {
        $msg = "Link aktivasi tidak valid atau akun sudah aktif.";
    }
} else {
    $msg = "Permintaan tidak valid.";
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Aktivasi Akun</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-body">
    <div class="card">
        <?php if ($type == 'success'): ?>
            <h1 class="success-text">🎉 Aktivasi Berhasil</h1>
            <p><?= $msg ?></p>
            <a href="login.php" class="btn">Login Sekarang</a>
        <?php else: ?>
            <h1 class="error-text">⚠️ Gagal</h1>
            <p><?= $msg ?></p>
            <a href="index.php" class="btn">Kembali ke Home</a>
        <?php endif; ?>
    </div>
</body>
</html>