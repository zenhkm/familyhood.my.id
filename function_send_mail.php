<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/vendor/autoload.php';

function send_activation_email($toEmail, $toName, $activationLink) {
    $mail = new PHPMailer(true);

    try {
        // --- KONFIGURASI SERVER ---
        $mail->isSMTP();
        $mail->Host       = 'mail.familyhood.my.id'; // Biasanya mail.namadomain
        $mail->SMTPAuth   = true;
        $mail->Username   = 'admin@familyhood.my.id';
        $mail->Password   = 'a60SH@*As{R.kh.B'; // <--- GANTI INI
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Gunakan SSL
        $mail->Port       = 465; // Port SSL biasanya 465

        // --- PENGIRIM & PENERIMA ---
        $mail->setFrom('admin@familyhood.my.id', 'Admin FamilyHood');
        $mail->setFrom('admin@familyhood.my.id', 'Admin FamilyHood');
        $mail->addReplyTo('admin@familyhood.my.id', 'Admin FamilyHood'); // Tambahkan ini
        $mail->addAddress($toEmail, $toName);

        // --- KONTEN EMAIL ---
        $mail->isHTML(true);
        $mail->Subject = 'Aktivasi Akun FamilyHood';
        
        $bodyContent = "
        <div style='font-family: sans-serif; padding: 20px; background: #f3f4f6;'>
            <div style='background: #fff; padding: 20px; border-radius: 8px; max-width: 500px; margin: 0 auto;'>
                <h2 style='color: #4f46e5;'>Halo, $toName!</h2>
                <p>Terima kasih telah mendaftar di FamilyHood. Untuk mengaktifkan akun Anda, silakan klik tombol di bawah ini:</p>
                <p style='text-align: center;'>
                    <a href='$activationLink' style='background: #4f46e5; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;'>Aktifkan Akun Saya</a>
                </p>
                <p>Atau copy link berikut: <br><small>$activationLink</small></p>
                <hr>
                <small style='color: #888;'>Jika Anda tidak merasa mendaftar, abaikan email ini.</small>
            </div>
        </div>
        ";

        $mail->Body = $bodyContent;
        $mail->AltBody = "Silakan kunjungi link ini untuk aktivasi: $activationLink";

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Untuk debugging, bisa uncomment baris bawah:
        // echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        return false;
    }
}
?>