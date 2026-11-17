<?php
// ── Liberty Foodmart — Send email (PHPMailer + Gmail SMTP) ──
//   require_once 'mailer.php';
//   send_mail($to, $subject, $html, $plain_text);   // true / false
// ------------------------------------------------------------

require_once __DIR__ . '/mail-config.php';
require_once __DIR__ . '/lib/PHPMailer/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

function send_mail(string $to, string $subject, string $html, string $text): bool {
    // Local testing: don't send, just log it
    if (MAIL_DEV_LOG_ONLY) {
        error_log("[MAIL-DEV] To: $to | Subject: $subject | $text");
        return true;
    }

    // Settings not filled in yet
    if (MAIL_USERNAME === 'your-shop@gmail.com' || MAIL_PASSWORD === 'xxxx xxxx xxxx xxxx') {
        error_log('[MAIL] mail-config.php has not been set up with the shop\'s Gmail + app password.');
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->Port       = MAIL_PORT;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout    = 15;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;

        $mail->send();
        return true;
    } catch (MailException $e) {
        error_log('[MAIL] send failed: ' . $mail->ErrorInfo);
        return false;
    }
}