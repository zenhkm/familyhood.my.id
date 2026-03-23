<?php
// HAPUS INI: session_start();
// GANTI JADI:
require_once 'init_session.php';

require_once 'config_google.php';

// Koneksi Database (Copy manual dari index.php agar mandiri)
$DB_HOST = 'localhost';
$DB_USER = 'quic1934_zenhkm'; // Sesuaikan user DB Anda
$DB_PASS = '03Maret1990';     // Sesuaikan pass DB Anda
$DB_NAME = 'quic1934_familyhood';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if (isset($_GET['code'])) {
    // 1. Tukar kode dari Google dengan Token
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    
    if (!isset($token['error'])) {
        $client->setAccessToken($token['access_token']);

        // 2. Ambil data profil User dari Google
        $google_oauth = new Google_Service_Oauth2($client);
        $google_account_info = $google_oauth->userinfo->get();
        
        $email = $google_account_info->email;
        $name  = $google_account_info->name;

        // 3. Cek apakah email ini TERDAFTAR di tabel 'allowed_users'
        $stmt = $mysqli->prepare("SELECT id, role FROM allowed_users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            // -- SUKSES LOGIN --
            $_SESSION['user_email'] = $email;
            $_SESSION['user_name'] = $name;
            $_SESSION['role'] = $row['role'];
            $_SESSION['is_logged_in'] = true;
            
            header("Location: index.php"); // Lempar ke halaman utama
            exit;
        } else {
            // -- GAGAL: Email valid, tapi tidak terdaftar di database kita --
            echo "<h3 style='color:red; text-align:center; margin-top:50px;'>Maaf, email <b>$email</b> tidak memiliki izin akses aplikasi ini.<br>Hubungi Admin.</h3>";
            echo "<p style='text-align:center;'><a href='login.php'>Kembali</a></p>";
            exit;
        }
    }
}

// Jika akses langsung tanpa kode google, kembalikan ke login
header("Location: login.php");
exit;
?>