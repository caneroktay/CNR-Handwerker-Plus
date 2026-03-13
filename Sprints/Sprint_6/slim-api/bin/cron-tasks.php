<?php
// ============================================================
//  HandwerkerPro — Automatisierungsscript (Cronjob CLI)
//  Aufgaben: Rechnungsstatus aktualisieren & Terminerinnerungen
// ============================================================
declare(strict_types=1);

// 1. Abhängigkeiten und Datenbank laden
require __DIR__ . '/../vendor/autoload.php';

$dbConfig = require __DIR__ . '/../config/database.php';
$settings = require __DIR__ . '/../config/settings.php';

use App\Models\Database;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Datenbank-Instanz erstellen
$db = new Database($dbConfig);

echo "[" . date('Y-m-d H:i:s') . "] Automatisierung gestartet...\n";

try {
    // --- AUFGABE 1: Überfällige Rechnungen markieren ---
    // Sucht Rechnungen mit Status 'gesendet', deren Fälligkeitsdatum abgelaufen ist
    $sqlStatus = "
        UPDATE rechnung 
        SET status = 'überfällig' 
        WHERE status = 'gesendet' 
          AND faelligkeitsdatum < CURDATE()
    ";
    $stmt = $db->execute($sqlStatus);
    echo ">> " . $stmt->rowCount() . " Rechnung(en) als 'überfällig' markiert.\n";


    // --- AUFGABE 2: Terminerinnerungen für morgen versenden ---
    // Holt alle Termine für den nächsten Tag inkl. Kundendaten
    $sqlTermine = "
        SELECT t.start_zeit, k.email, k.vorname, k.nachname, a.titel 
        FROM termin t
        JOIN auftrag a ON t.auftrag_id = a.auftrag_id
        JOIN kunde k ON a.kunde_id = k.kunde_id
        WHERE DATE(t.start_zeit) = CURDATE() + INTERVAL 1 DAY
    ";
    $termine = $db->fetchAll($sqlTermine);

    if (empty($termine)) {
        echo ">> Keine Termine für morgen zur Erinnerung gefunden.\n";
    } else {
        foreach ($termine as $t) {
            echo ">> Versende Erinnerung an: " . $t['email'] . "\n";
            
            // Aufruf der PHPMailer-Funktion
            sendReminderEmail($t, $settings['mail']);
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] Automatisierung erfolgreich beendet.\n";

} catch (\Exception $e) {
    // Fehlerprotokollierung
    echo "!!! FEHLER: " . $e->getMessage() . "\n";
}

/**
 * Hilfsfunktion zum Versenden der Erinnerungs-E-Mail via PHPMailer
 */
function sendReminderEmail(array $data, array $mailSettings): void 
{
    $mail = new PHPMailer(true);
    try {
        // SMTP-Konfiguration (aus settings.php)
        $mail->isSMTP();
        $mail->Host       = $mailSettings['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $mailSettings['username'];
        $mail->Password   = $mailSettings['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $mailSettings['port'];
        $mail->CharSet    = 'UTF-8';

        // Absender und Empfänger
        $mail->setFrom($mailSettings['from_email'], 'HandwerkerPro Service');
        $mail->addAddress($data['email']);

        // E-Mail Inhalt
        $mail->isHTML(true);
        $mail->Subject = 'Terminerinnerung: ' . $data['titel'];
        $mail->Body    = "
            <h3>Sehr geehrte(r) {$data['vorname']} {$data['nachname']},</h3>
            <p>dies ist eine freundliche Erinnerung an Ihren Termin morgen.</p>
            <p><strong>Zeitpunkt:</strong> " . date('d.m.Y H:i', strtotime($data['start_zeit'])) . " Uhr</p>
            <p>Wir freuen uns auf Sie.</p>
            <br>
            <p>Mit freundlichen Grüßen,<br>Ihr HandwerkerPro Team</p>
        ";

        $mail->send();
    } catch (Exception $e) {
        echo "E-Mail Fehler ({$data['email']}): {$mail->ErrorInfo}\n";
    }
}