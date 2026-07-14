<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = trim($_ENV['SMTP_HOST']);
        $mail->SMTPAuth   = true;
        $mail->Username   = trim($_ENV['SMTP_USER']);
        $mail->Password   = trim($_ENV['SMTP_PASS']);
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)trim($_ENV['SMTP_PORT']);

        $mail->setFrom(trim($_ENV['SMTP_USER']), trim($_ENV['SMTP_FROM_NAME']));
        $mail->addAddress($toEmail, $toName);
        $mail->CharSet  = PHPMailer::CHARSET_UTF8;
        $mail->isHTML(true);
        $mail->Subject  = $subject;
        $mail->Body     = $htmlBody;
        $mail->AltBody  = strip_tags($htmlBody);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mailer error: ' . $mail->ErrorInfo);
        return false;
    }
}
