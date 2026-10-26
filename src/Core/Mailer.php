<?php declare(strict_types=1);

namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class Mailer
{
    /**
     * Envía un email HTML. Devuelve true si se entrega al servidor SMTP.
     * Las excepciones de PHPMailer se capturan y se devuelven como false
     * para no reventar flujos (el dominio decide si considerar error crítico).
     */
    public static function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
           // $mail->SMTPDebug = 2; // Para desarrollo, en producción usar 0 o eliminar
            $mail->Host       = SMTP_HOST;
            $mail->Port       = (int) SMTP_PORT;
            $mail->SMTPAuth   = SMTP_USER !== '';
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom(SMTP_FROM, SMTP_FROMNAME);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody !== '' ? $textBody : strip_tags($htmlBody);

            return $mail->send();
        } catch (PHPMailerException $e) {
       //     var_export($e->getMessage());
            error_log('Mailer failed: ' . $e->getMessage());
            return false;
        }
    }
}
