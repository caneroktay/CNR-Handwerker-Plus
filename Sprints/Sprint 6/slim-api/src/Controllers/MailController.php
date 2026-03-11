<?php
// ============================================================
//  HandwerkerPro — MailController
//  POST /api/rechnungen/{id}/email       → Rechnung versenden
//  POST /api/rechnungen/mahnungen        → Alle überfälligen mahnen
//  POST /api/notifications/auftrag/{id}  → Auftragsbenachrichtigung
//  POST /api/mail/test                   → SMTP-Test
// ============================================================
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Mail\Mailer;
use App\Mail\MailTemplates;
use App\Pdf\RechnungPdf;

class MailController extends BaseController
{
    private function getMailer(): Mailer
    {
        return new Mailer($this->settings['mail']);
    }

    // ══════════════════════════════════════════════════════
    //  POST /api/rechnungen/{id}/email
    //  Rechnung als PDF generieren und per E-Mail versenden
    // ══════════════════════════════════════════════════════
    public function sendRechnung(
        ServerRequestInterface $request,
        ResponseInterface      $response,
        array                  $args
    ): ResponseInterface {
        $id   = (int) $args['id'];
        $body = $this->sanitize($this->getBody($request));

        // Rechnung laden
        $rechnung = $this->db->fetchOne(
            "SELECT r.*,
                    a.titel AS auftrag_titel, a.auftrag_nr,
                    CASE WHEN k.typ='privat'
                         THEN CONCAT(kp.vorname,' ',kp.nachname)
                         ELSE kf.firmenname END AS kunden_name,
                    kf.ansprechpartner,
                    kk.wert AS kunden_email
             FROM rechnung r
             JOIN auftrag  a  ON r.auftrag_id  = a.auftrag_id
             JOIN kunden   k  ON r.kunden_id   = k.kunden_id
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             LEFT JOIN kunden_kontakt kk ON k.kunden_id = kk.kunden_id
               AND kk.typ = 'Email'
             WHERE r.rechnung_id = ?",
            [$id]
        );

        if (!$rechnung) {
            return $this->notFound($response, "Rechnung #{$id} nicht gefunden.");
        }

        // Empfänger bestimmen: aus Request-Body oder aus DB
        $toEmail = $this->sanitize($body['email'] ?? $rechnung['kunden_email'] ?? '');
        $toName  = $rechnung['kunden_name'] ?? '';

        if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->unprocessable($response,
                'Keine gültige E-Mail-Adresse. Entweder im Body {"email":"..."} angeben oder beim Kunden hinterlegen.'
            );
        }

        // Rechnungspositionen
        $positionen = $this->db->fetchAll(
            "SELECT rp.*, ap.bezeichnung, ap.typ
             FROM rechnung_position rp
             LEFT JOIN auftrag_position ap ON rp.position_id = ap.position_id
             WHERE rp.rechnung_id = ?",
            [$id]
        );

        // Rechnungsnummer generieren falls nicht vorhanden
        if (empty($rechnung['rechnungs_nr'])) {
            $rechnung['rechnungs_nr'] = 'R-' . date('Y') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
        }

        // PDF generieren
        try {
            $adresse  = $this->db->fetchOne(
                'SELECT * FROM kunden_adressen WHERE kunden_id = ? LIMIT 1',
                [$rechnung['kunden_id']]
            );
            $kundeData  = array_merge($rechnung, $adresse ?: []);
            $pdf        = new RechnungPdf();
            $pdfContent = $pdf->generate($rechnung, $positionen, $kundeData);
            $pdfName    = 'Rechnung_' . $rechnung['rechnungs_nr'] . '.pdf';
        } catch (\Throwable $e) {
            return $this->serverError($response, 'PDF-Generierung fehlgeschlagen: ' . $e->getMessage());
        }

        // E-Mail-Template
        $template = MailTemplates::rechnung($rechnung, $rechnung, $positionen);

        // Versenden
        try {
            $this->getMailer()->sendRechnung(
                $toEmail, $toName,
                $template['subject'],
                $template['html'],
                $pdfContent,
                $pdfName
            );
        } catch (\Throwable $e) {
            return $this->serverError($response,
                'E-Mail-Versand fehlgeschlagen: ' . $e->getMessage()
            );
        }

        // Log: E-Mail-Versand dokumentieren
        $this->db->execute(
            "INSERT INTO status_log (typ, referenz_id, alter_status, neuer_status, notiz, geaendert_am)
             VALUES ('rechnung', ?, 'email_pending', 'email_sent', ?, NOW())
             ON DUPLICATE KEY UPDATE neuer_status = 'email_sent'",
            [$id, "Rechnung per E-Mail an {$toEmail} versendet"]
        );

        return $this->ok($response, [
            'rechnung_id'  => $id,
            'empfaenger'   => $toEmail,
            'betreff'      => $template['subject'],
            'pdf_angehängt' => $pdfName,
            'gesendet_am'  => date('c'),
        ], "Rechnung #{$id} erfolgreich an {$toEmail} versendet.");
    }

    // ══════════════════════════════════════════════════════
    //  POST /api/rechnungen/mahnungen
    //  Alle überfälligen Rechnungen mahnen
    // ══════════════════════════════════════════════════════
    public function sendMahnungen(
        ServerRequestInterface $request,
        ResponseInterface      $response
    ): ResponseInterface {
        $body     = $this->sanitize($this->getBody($request));
        $minTage  = (int)($body['min_tage_verzug'] ?? 1);   // Standard: ab 1 Tag
        $maxAnzahl = (int)($body['max_anzahl'] ?? 50);       // Sicherheitslimit
        $stufe    = (int)($body['mahnstufe'] ?? 1);

        if ($stufe < 1 || $stufe > 3) {
            return $this->unprocessable($response, 'Ungültige Mahnstufe. Erlaubt: 1, 2, 3');
        }

        // Überfällige Rechnungen mit Kundenkontakt laden
        $rechnungen = $this->db->fetchAll(
            "SELECT r.*,
                    CASE WHEN k.typ='privat'
                         THEN CONCAT(kp.vorname,' ',kp.nachname)
                         ELSE kf.firmenname END AS kunden_name,
                    kf.ansprechpartner,
                    kk.wert AS kunden_email,
                    DATEDIFF(CURDATE(), r.faellig_am) AS tage_verzug
             FROM rechnung r
             JOIN kunden   k  ON r.kunden_id   = k.kunden_id
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             LEFT JOIN kunden_kontakt kk ON k.kunden_id = kk.kunden_id
               AND kk.typ = 'Email'
             WHERE r.status IN ('gesendet','überfällig')
               AND r.faellig_am < CURDATE()
               AND DATEDIFF(CURDATE(), r.faellig_am) >= ?
             ORDER BY tage_verzug DESC
             LIMIT ?",
            [$minTage, $maxAnzahl]
        );

        if (empty($rechnungen)) {
            return $this->ok($response, ['gesendet' => 0, 'details' => []],
                'Keine überfälligen Rechnungen gefunden.'
            );
        }

        $ergebnisse = ['gesendet' => 0, 'fehler' => 0, 'details' => []];

        foreach ($rechnungen as $rechnung) {
            $email = $rechnung['kunden_email'] ?? '';

            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $ergebnisse['fehler']++;
                $ergebnisse['details'][] = [
                    'rechnung_id' => $rechnung['rechnung_id'],
                    'status'      => 'fehler',
                    'grund'       => 'Keine E-Mail-Adresse beim Kunden hinterlegt.',
                ];
                continue;
            }

            $template = MailTemplates::mahnung($rechnung, $rechnung, $stufe);

            try {
                $this->getMailer()->sendMahnung(
                    $email,
                    $rechnung['kunden_name'],
                    $template['subject'],
                    $template['html']
                );

                // Status auf überfällig setzen
                $this->db->execute(
                    "UPDATE rechnung SET status = 'überfällig' WHERE rechnung_id = ?",
                    [$rechnung['rechnung_id']]
                );

                $ergebnisse['gesendet']++;
                $ergebnisse['details'][] = [
                    'rechnung_id' => $rechnung['rechnung_id'],
                    'empfaenger'  => $email,
                    'tage_verzug' => $rechnung['tage_verzug'],
                    'mahnstufe'   => $stufe,
                    'status'      => 'gesendet',
                ];
            } catch (\Throwable $e) {
                $ergebnisse['fehler']++;
                $ergebnisse['details'][] = [
                    'rechnung_id' => $rechnung['rechnung_id'],
                    'empfaenger'  => $email,
                    'status'      => 'fehler',
                    'grund'       => $e->getMessage(),
                ];
            }
        }

        $msg = "Mahnungen versendet: {$ergebnisse['gesendet']}, Fehler: {$ergebnisse['fehler']}.";
        return $this->ok($response, $ergebnisse, $msg);
    }

    // ══════════════════════════════════════════════════════
    //  POST /api/notifications/auftrag/{id}
    //  Kunde über Auftragsstatus benachrichtigen
    // ══════════════════════════════════════════════════════
    public function auftragNotification(
        ServerRequestInterface $request,
        ResponseInterface      $response,
        array                  $args
    ): ResponseInterface {
        $id = (int) $args['id'];

        $auftrag = $this->db->fetchOne(
            "SELECT a.*,
                    CASE WHEN k.typ='privat'
                         THEN CONCAT(kp.vorname,' ',kp.nachname)
                         ELSE kf.firmenname END AS kunden_name,
                    kf.ansprechpartner,
                    kk.wert AS kunden_email
             FROM auftrag  a
             JOIN kunden   k  ON a.kunden_id  = k.kunden_id
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             LEFT JOIN kunden_kontakt kk ON k.kunden_id = kk.kunden_id
               AND kk.typ = 'Email'
             WHERE a.auftrag_id = ?",
            [$id]
        );

        if (!$auftrag) {
            return $this->notFound($response, "Auftrag #{$id} nicht gefunden.");
        }

        $body    = $this->sanitize($this->getBody($request));
        $toEmail = $this->sanitize($body['email'] ?? $auftrag['kunden_email'] ?? '');

        if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->unprocessable($response,
                'Keine gültige E-Mail-Adresse. Im Body {"email":"..."} angeben oder beim Kunden hinterlegen.'
            );
        }

        $template = MailTemplates::auftragBenachrichtigung($auftrag, $auftrag);

        try {
            $this->getMailer()->sendNotification(
                $toEmail,
                $auftrag['kunden_name'],
                $template['subject'],
                $template['html']
            );
        } catch (\Throwable $e) {
            return $this->serverError($response, 'E-Mail-Versand fehlgeschlagen: ' . $e->getMessage());
        }

        return $this->ok($response, [
            'auftrag_id'  => $id,
            'auftrag_nr'  => $auftrag['auftrag_nr'],
            'status'      => $auftrag['status'],
            'empfaenger'  => $toEmail,
            'gesendet_am' => date('c'),
        ], "Benachrichtigung für Auftrag #{$id} erfolgreich versendet.");
    }

    // ══════════════════════════════════════════════════════
    //  POST /api/mail/test
    //  SMTP-Konfiguration testen
    // ══════════════════════════════════════════════════════
    public function testSmtp(
        ServerRequestInterface $request,
        ResponseInterface      $response
    ): ResponseInterface {
        $body = $this->getBody($request);

        // Prüfen, ob der Body überhaupt vorhanden ist und ein gültiges JSON darstellt.
        if ($body === null) {
            return $this->unprocessable($response, 'Der Request-Body ist leer oder kein gültiges JSON. Ein JSON-Objekt mit dem Feld "email" wird erwartet.');
        }

        $body    = $this->sanitize($body);
        $missing = $this->validateRequired($body, ['email']);
        if ($missing) {
            return $this->unprocessable($response, 'Pflichtfeld fehlt.', ['fehlende_felder' => $missing]);
        }

        if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->unprocessable($response, 'Ungültige E-Mail-Adresse.');
        }

        $template = MailTemplates::testEmail();

        try {
            $this->getMailer()->sendNotification(
                $body['email'],
                'Test-Empfänger',
                $template['subject'],
                $template['html']
            );
        } catch (\Throwable $e) {
            return $this->serverError($response,
                'SMTP-Test fehlgeschlagen: ' . $e->getMessage()
            );
        }

        return $this->ok($response, [
            'smtp_host'   => $this->settings['mail']['smtp_host'],
            'smtp_port'   => $this->settings['mail']['smtp_port'],
            'smtp_user'   => $this->settings['mail']['smtp_user'],
            'empfaenger'  => $body['email'],
            'gesendet_am' => date('c'),
        ], 'Test-E-Mail erfolgreich versendet. SMTP-Konfiguration ist korrekt.');
    }
}
