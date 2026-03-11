<?php
// ============================================================
//  HandwerkerPro — Mailer
//  PHPMailer Wrapper mit SMTP-Konfiguration aus .env
//  Unterstützt: HTML-Emails, PDF-Anhänge, Fehlerbehandlung
// ============================================================
declare(strict_types=1);

namespace App\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

class Mailer
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * PHPMailer-Instanz mit SMTP-Konfiguration erstellen
     */
    private function createMailer(): PHPMailer
    {
        $mail = new PHPMailer(true); // Exceptions aktivieren

     // ── SMTP-Konfiguration aus .env ──────────────────────
        $mail->isSMTP();
        $mail->Host       = $this->config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $this->config['smtp_user'];
        $mail->Password   = $this->config['smtp_pass'];
        $mail->SMTPSecure = $this->config['smtp_secure'] === 'ssl'
                            ? PHPMailer::ENCRYPTION_SMTPS
                            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) $this->config['smtp_port'];
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'base64';

        // ── Für LOCAL  ────────────────
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        // Debug-Modus nur in Entwicklung
        $mail->SMTPDebug   = $this->config['debug'] ? SMTP::DEBUG_SERVER : SMTP::DEBUG_OFF;

        // Absender
        $mail->setFrom(
            $this->config['from_email'],
            $this->config['from_name']
        );

        return $mail;
    }

    /**
     * Rechnung per E-Mail versenden (mit PDF-Anhang)
     *
     * @param string $toEmail     Empfänger E-Mail
     * @param string $toName      Empfänger Name
     * @param string $subject     Betreff
     * @param string $htmlBody    HTML-Inhalt
     * @param string $pdfContent  PDF als Binär-String
     * @param string $pdfFilename Dateiname des PDFs
     */
    public function sendRechnung(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $pdfContent,
        string $pdfFilename
    ): void {
        $mail = $this->createMailer();

        $mail->addAddress($toEmail, $toName);
        $mail->Subject  = $subject;
        $mail->isHTML(true);
        $mail->Body     = $htmlBody;
        $mail->AltBody  = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

        // PDF als Anhang (aus Speicher, kein temp-File nötig)
        $mail->addStringAttachment($pdfContent, $pdfFilename, 'base64', 'application/pdf');

        $mail->send();
    }

    /**
     * Mahnungs-Email (ohne Anhang oder mit Anhang)
     */
    public function sendMahnung(
        string  $toEmail,
        string  $toName,
        string  $subject,
        string  $htmlBody,
        ?string $pdfContent  = null,
        ?string $pdfFilename = null
    ): void {
        $mail = $this->createMailer();

        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

        if ($pdfContent && $pdfFilename) {
            $mail->addStringAttachment($pdfContent, $pdfFilename, 'base64', 'application/pdf');
        }

        $mail->send();
    }

    /**
     * Allgemeine Benachrichtigung (Auftragsstatus, etc.)
     */
    public function sendNotification(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody
    ): void {
        $mail = $this->createMailer();

        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

        $mail->send();
    }

    /**
     * SMTP-Verbindung testen
     * Gibt true zurück wenn erfolgreich, sonst Exception
     */
    public function testConnection(): bool
    {
        $mail = $this->createMailer();
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
        $mail->smtpConnect();
        $mail->smtpClose();
        return true;
    }
}
