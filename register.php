<?php
// File: register.php
require_once 'init_session.php';
require_once 'config.php';
require_once 'function_send_mail.php'; // <--- LOAD HELPER EMAIL

if (isset($_SESSION['is_logged_in']) && $_SESSION['is_logged_in'] === true) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($mysqli->connect_error) die("Koneksi Gagal");

    $name  = trim($_POST['name']);
    $email = trim($_POST['email']);
    $pass  = $_POST['password'];
    $conf  = $_POST['confirm_password'];

    if (empty($name) || empty($email) || empty($pass)) {
        $error = "Semua kolom wajib diisi.";
    } elseif ($pass !== $conf) {
        $error = "Konfirmasi password tidak cocok.";
    } else {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error = "Email sudah terdaftar.";
        } else {
            // --- MODIFIKASI DIMULAI DISINI ---
            $hashedPass = password_hash($pass, PASSWORD_DEFAULT);
            $role = 'user';
            
            // 1. Generate Kode Aktivasi
            $activationCode = bin2hex(random_bytes(16)); // 32 karakter random
            $isActive = 0; // Belum aktif

            // 2. Simpan ke DB (is_active=0)
            $stmtIns = $mysqli->prepare("INSERT INTO users (name, email, password, role, is_active, activation_code) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtIns->bind_param("ssssis", $name, $email, $hashedPass, $role, $isActive, $activationCode);

            if ($stmtIns->execute()) {
                // 3. Kirim Email
                // Sesuaikan domain Anda jika di localhost/hosting berbeda
                $base_url = "https://familyhood.my.id"; // Pastikan tidak pakai slash di akhir
                $link = $base_url . "/activate.php?email=" . urlencode($email) . "&code=" . $activationCode;
                
                if (send_activation_email($email, $name, $link)) {
                    $success = "Pendaftaran berhasil! Cek inbox/spam email Anda ($email) untuk aktivasi akun.";
                } else {
                    $error = "Pendaftaran sukses, tapi gagal mengirim email. Hubungi admin.";
                }
            } else {
                $error = "Gagal mendaftar: " . $stmtIns->error;
            }
            $stmtIns->close();
        }
        $stmt->close();
    }
    $mysqli->close();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Daftar Akun - FamilyHood</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-body">
    <div class="login-card">
        <h1>Daftar Akun</h1>
        <p>Bergabung dengan FamilyHood</p>

        <?php if ($error): ?> <div class="alert alert-error"><?= htmlspecialchars($error) ?></div> <?php endif; ?>
        <?php if ($success): ?> <div class="alert alert-success"><?= htmlspecialchars($success) ?></div> <?php endif; ?>

        <?php if (!$success): ?>
        <form method="post">
            <label>Nama Lengkap</label>
            <input type="text" name="name" required placeholder="Nama Anda" value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '' ?>">
            
            <label>Email</label>
            <input type="email" name="email" required placeholder="email@contoh.com" value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
            
            <label>Password</label>
            <input type="password" name="password" required placeholder="******">
            
            <label>Konfirmasi Password</label>
            <input type="password" name="confirm_password" required placeholder="******">
            
            <button type="submit" class="btn btn-primary">Daftar Sekarang</button>
        </form>
        <?php endif; ?>

        <div class="link-text">
            Sudah punya akun? <a href="login.php">Login di sini</a>
        </div>
    </div>
</body>
</html>