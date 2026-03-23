<?php
// File: login.php
require_once 'init_session.php';
require_once 'config.php';
require_once 'config_google.php';

$login_error = '';

// --- FUNGSI BANTUAN LOGIN SUKSES ---
function do_login_session($userRow) {
    $_SESSION['is_logged_in'] = true;
    $_SESSION['user_id']    = $userRow['id'];
    $_SESSION['user_email'] = $userRow['email'];
    $_SESSION['user_name']  = $userRow['name'];
    $_SESSION['role']       = $userRow['role'];
    header("Location: index.php");
    exit;
}

// ---------------------------------------------------
// 1. LOGIKA LOGIN MANUAL (EMAIL & PASSWORD)
// ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($mysqli->connect_error) die("DB Error");

    // Ambil user berdasarkan email
    $stmt = $mysqli->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user) {
        // --- TAMBAHAN: CEK AKTIVASI ---
        if ($user['is_active'] == 0) {
            $login_error = "Akun belum aktif. Silakan cek email Anda untuk aktivasi.";
        } 
        // Verifikasi Password Hash
        elseif (password_verify($password, $user['password'])) {
            do_login_session($user);
        } else {
            $login_error = "Password salah.";
        }
    } else {
        $login_error = "Email tidak ditemukan.";
    }
    $stmt->close();
    $mysqli->close();
}

// ---------------------------------------------------
// 2. LOGIKA LOGIN GOOGLE (OAUTH)
// ---------------------------------------------------
if (isset($_GET['code'])) {
    try {
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        if (isset($token['error'])) throw new Exception("Error Token: " . $token['error']);
        
        $client->setAccessToken($token);
        $google_oauth = new Google_Service_Oauth2($client);
        $google_account_info = $google_oauth->userinfo->get();
        
        $email = $google_account_info->email;
        $name  = $google_account_info->name;
        $googleId = $google_account_info->id;
        $picture  = $google_account_info->picture;

        $mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
        
        // Cek User
        $stmt = $mysqli->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user) {
            // User lama: Update Google ID & Foto
            $upd = $mysqli->prepare("UPDATE users SET google_id = ?, photo_url = ?, updated_at = NOW() WHERE id = ?");
            $upd->bind_param("ssi", $googleId, $picture, $user['id']);
            $upd->execute();
            do_login_session($user);
        } else {
            // User baru (via Google): Buat akun dengan dummy password
            $dummyPass = password_hash("google_" . time() . bin2hex(random_bytes(5)), PASSWORD_DEFAULT);
           // INSERT User Baru Google (Otomatis Aktif / is_active = 1)
            $ins = $mysqli->prepare("INSERT INTO users (email, name, role, password, google_id, photo_url, is_active) VALUES (?, ?, 'user', ?, ?, ?, 1)");
            // Perhatikan jumlah 's' dan parameter bind-nya nambah satu int (1) tapi karena hardcode di query jadi aman.
            // bind_param nya tetap 5 string:
            $ins->bind_param("sssss", $email, $name, $dummyPass, $googleId, $picture);
            
            if ($ins->execute()) {
                $newId = $ins->insert_id;
                // Ambil data user yang baru dibuat agar lengkap
                $resNew = $mysqli->query("SELECT * FROM users WHERE id=$newId");
                do_login_session($resNew->fetch_assoc());
            } else {
                die("Gagal registrasi Google: " . $ins->error);
            }
        }
    } catch (Exception $e) {
        $login_error = "Google Login Error: " . $e->getMessage();
    }
}

// Jika sudah login, lempar ke index
if (isset($_SESSION['is_logged_in']) && $_SESSION['is_logged_in'] === true) {
    header("Location: index.php");
    exit;
}

$login_url = $client->createAuthUrl();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Login - FamilyHood</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { display: flex; justify-content: center; align-items: center; min-height: 100vh; background: #f3f4f6; font-family: sans-serif; margin: 0; }
        .login-card { background: white; padding: 40px; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; max-width: 400px; text-align: center; }
        
        h1 { color: #4f46e5; margin: 10px 0; }
        p { color: #6b7280; font-size: 0.9rem; margin-bottom: 25px; }
        
        /* Form Styling */
        form { text-align: left; margin-bottom: 20px; }
        label { display: block; font-size: 0.85rem; color: #374151; font-weight: 600; margin-bottom: 5px; }
        input { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #d1d5db; border-radius: 8px; box-sizing: border-box; }
        
        .btn-primary { width: 100%; background: #4f46e5; color: white; padding: 12px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.2s; }
        .btn-primary:hover { background: #4338ca; }

        .divider { display: flex; align-items: center; margin: 20px 0; color: #9ca3af; font-size: 0.8rem; }
        .divider::before, .divider::after { content: ""; flex: 1; height: 1px; background: #e5e7eb; }
        .divider::before { margin-right: 10px; }
        .divider::after { margin-left: 10px; }

        .btn-google {
            display: flex; align-items: center; justify-content: center; gap: 10px;
            background: #ffffff; color: #555; border: 1px solid #d1d5db;
            padding: 12px; text-decoration: none; border-radius: 8px; 
            font-weight: 600; transition: 0.2s; width: 100%; box-sizing: border-box;
        }
        .btn-google:hover { background: #f9fafb; border-color: #9ca3af; }
        
        .alert-error { background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9rem; text-align: left;}
        .register-link { font-size: 0.9rem; margin-top: 20px; }
        .register-link a { color: #4f46e5; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="login-card">
        <svg width="50" height="50" viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect width="512" height="512" rx="100" fill="#4f46e5"/>
            <path d="M256 150V250" stroke="white" stroke-width="32" stroke-linecap="round"/>
            <path d="M256 250L150 340" stroke="white" stroke-width="32" stroke-linecap="round"/>
            <path d="M256 250L362 340" stroke="white" stroke-width="32" stroke-linecap="round"/>
            <circle cx="256" cy="140" r="60" fill="white"/>
        </svg>

        <h1>FamilyHood</h1>
        <p>Masuk untuk mengelola silsilah keluarga.</p>

        <?php if ($login_error): ?>
            <div class="alert-error"><?= htmlspecialchars($login_error) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="manual_login" value="1">
            <label>Email</label>
            <input type="email" name="email" required placeholder="email@contoh.com">
            
            <label>Password</label>
            <input type="password" name="password" required placeholder="******">
            
            <button type="submit" class="btn btn-primary">Masuk</button>
        </form>

        <div class="divider">ATAU</div>

        <a href="<?= $login_url ?>" class="btn-google">
            <img src="https://www.svgrepo.com/show/475656/google-color.svg" width="20" height="20">
            Masuk dengan Google
        </a>

        <div class="register-link">
            Belum punya akun? <a href="register.php">Daftar di sini</a>
        </div>
    </div>
</body>
</html>