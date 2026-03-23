<?php
// Konfigurasi Durasi Login (detik)
// 30 Hari = 30 * 24 * 60 * 60 = 2592000 detik
$lifetime = 2592000; 

// 1. Tentukan folder penyimpanan session khusus
// Kita taruh di folder bernama 'sessions' di dalam direktori project
$savePath = __DIR__ . '/sessions';

// 2. Buat folder jika belum ada (otomatis)
if (!is_dir($savePath)) {
    mkdir($savePath, 0777, true);
}

// 3. Atur Konfigurasi PHP untuk Session ini
// Arahkan penyimpanan ke folder kita
session_save_path($savePath);

// Atur Garbage Collection (kapan session dianggap sampah) - Server Side
ini_set('session.gc_maxlifetime', $lifetime);

// Atur Cookie Lifetime (agar browser mengingat user meski diclose) - Client Side
session_set_cookie_params([
    'lifetime' => $lifetime,
    'path' => '/',
    'domain' => '', // Kosongkan agar otomatis detect domain (aman untuk perpindahan domain nanti)
    'secure' => isset($_SERVER['HTTPS']), // True jika HTTPS
    'httponly' => true, // Mencegah akses cookie via JS (XSS protection)
    'samesite' => 'Lax'
]);

// 4. Mulai Session
// Cek status session agar tidak double start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>