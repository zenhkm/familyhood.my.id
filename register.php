<?php
// File: register.php
require_once 'init_session.php';
require_once 'function_send_mail.php'; // <--- LOAD HELPER EMAIL

if (isset($_SESSION['is_logged_in']) && $_SESSION['is_logged_in'] === true) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mysqli = new mysqli('localhost', 'quic1934_zenhkm', '03Maret1990', 'quic1934_familyhood');
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
    <style>
        /* Styling disamakan dengan login.php Anda agar konsisten */
        body { display: flex; justify-content: center; align-items: center; min-height: 100vh; background: #f3f4f6; font-family: sans-serif; margin: 0; }
        .login-card { background: white; padding: 40px; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        h1 { color: #4f46e5; margin-bottom: 5px; text-align: center; }
        p { color: #6b7280; font-size: 0.9rem; text-align: center; margin-bottom: 20px; }
        
        form label { display: block; font-size: 0.9rem; margin-bottom: 5px; color: #374151; font-weight: 600; }
        form input { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #d1d5db; border-radius: 8px; box-sizing: border-box; }
        
        .btn-primary { width: 100%; background: #4f46e5; color: white; padding: 12px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.2s; }
        .btn-primary:hover { background: #4338ca; }
        
        .alert { padding: 10px; border-radius: 5px; margin-bottom: 15px; font-size: 0.9rem; text-align: center; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .alert-success { background: #d1fae5; color: #065f46; }
        
        .link-text { text-align: center; margin-top: 15px; font-size: 0.9rem; }
        .link-text a { color: #4f46e5; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
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