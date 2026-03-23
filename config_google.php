<?php
require_once 'vendor/autoload.php'; // Memanggil library Google yang diinstall via Composer

// GANTI DENGAN DATA DARI LANGKAH 1
$clientID = '474218285282-69kma365eg99n3o3ie8a1g3ltqpm5k6s.apps.googleusercontent.com';
$clientSecret = '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';
// Sesuaikan URL ini persis seperti yang didaftarkan di Google Console
$redirectUri = 'https://familyhood.my.id/login.php';

// Membuat Client Request ke Google
$client = new Google_Client();
$client->setClientId($clientID);
$client->setClientSecret($clientSecret);
$client->setRedirectUri($redirectUri);
$client->addScope("email");
$client->addScope("profile");
?>