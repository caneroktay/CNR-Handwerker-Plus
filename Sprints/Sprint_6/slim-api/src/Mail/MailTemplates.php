<?php
// ============================================================
//  HandwerkerPro — MailTemplates
//  Professionelle HTML-E-Mail-Vorlagen
//  Alle Templates: responsiv, Inline-CSS, UTF-8
// ============================================================
declare(strict_types=1);

namespace App\Mail;

class MailTemplates
{
    // ── Firmenfarbe & Daten ───────────────────────────────────
    private const FARBE   = '#1e40af';  // Dunkelblau
    private const FIRMA   = 'Klaus Meier GmbH';
    private const ADRESSE = 'Handwerkerstraße 42, 45127 Essen';
    private const TEL     = '+49 201 123 456 0';
    private const EMAIL   = 'info@klausmeier-gmbh.de';
    private const WEB     = 'www.klausmeier-gmbh.de';

    // ════════════════════════════════════════════════════════
    //  RECHNUNG
    // ════════════════════════════════════════════════════════

    /**
     * HTML-Email für Rechnungsversand
     *
     * @param array $rechnung  Rechnungsdaten (rechnungs_nr, rechnungs_datum, faellig_am, gesamtbetrag)
     * @param array $kunde     Kundendaten (kunden_name, ansprechpartner)
     * @param array $positionen Rechnungspositionen
     */
    public static function rechnung(array $rechnung, array $kunde, array $positionen = []): array
    {
        $name        = $kunde['ansprechpartner'] ?? $kunde['kunden_name'] ?? 'Damen und Herren';
        $rechnungsNr = $rechnung['rechnungs_nr'] ?? ('R-' . $rechnung['rechnung_id']);
        $datum       = self::formatDate($rechnung['rechnungs_datum'] ?? '');
        $faellig     = self::formatDate($rechnung['faellig_am'] ?? '');
        $betrag      = self::formatMoney((float)($rechnung['gesamtbetrag'] ?? 0));

        $subject = "Rechnung {$rechnungsNr} von " . self::FIRMA;

        // Positionstabelle
        $posHtml = '';
        if (!empty($positionen)) {
            $posHtml = '
            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:16px 0;">
              <tr style="background:' . self::FARBE . ';color:#fff;">
                <td style="padding:8px 12px;font-size:13px;font-weight:bold;">Beschreibung</td>
                <td style="padding:8px 12px;font-size:13px;font-weight:bold;text-align:right;">Menge</td>
                <td style="padding:8px 12px;font-size:13px;font-weight:bold;text-align:right;">Einzelpreis</td>
                <td style="padding:8px 12px;font-size:13px;font-weight:bold;text-align:right;">Gesamt</td>
              </tr>';
            foreach ($positionen as $i => $pos) {
                $bg     = $i % 2 === 0 ? '#f8fafc' : '#fff';
                $menge  = (float)($pos['menge'] ?? 0);
                $preis  = (float)($pos['einzelpreis_bei_bestellung'] ?? $pos['einzelpreis_bei_rechnung'] ?? 0);
                $gesamt = $menge * $preis;
                $posHtml .= '
              <tr style="background:' . $bg . ';">
                <td style="padding:7px 12px;font-size:13px;border-bottom:1px solid #e2e8f0;">'
                    . htmlspecialchars($pos['bezeichnung'] ?? '—') . '</td>
                <td style="padding:7px 12px;font-size:13px;border-bottom:1px solid #e2e8f0;text-align:right;">'
                    . number_format($menge, 2, ',', '.') . '</td>
                <td style="padding:7px 12px;font-size:13px;border-bottom:1px solid #e2e8f0;text-align:right;">'
                    . self::formatMoney($preis) . '</td>
                <td style="padding:7px 12px;font-size:13px;border-bottom:1px solid #e2e8f0;text-align:right;">'
                    . self::formatMoney($gesamt) . '</td>
              </tr>';
            }
            $posHtml .= '</table>';
        }

        $html = self::wrap(
            $subject,
            "
            <p style='margin:0 0 16px;font-size:15px;'>Sehr geehrte/r {$name},</p>
            <p style='margin:0 0 20px;font-size:15px;line-height:1.6;'>
                vielen Dank für Ihren Auftrag. Im Anhang finden Sie Ihre Rechnung als PDF-Datei.
            </p>

            <!-- Rechnungsinfo Box -->
            <table width='100%' cellpadding='0' cellspacing='0'
                   style='background:#eff6ff;border-radius:8px;margin:0 0 24px;border:1px solid #bfdbfe;'>
              <tr>
                <td style='padding:20px 24px;'>
                  <table width='100%'>
                    <tr>
                      <td style='font-size:13px;color:#64748b;padding-bottom:6px;'>Rechnungsnummer</td>
                      <td style='font-size:13px;font-weight:bold;color:#1e293b;text-align:right;padding-bottom:6px;'>{$rechnungsNr}</td>
                    </tr>
                    <tr>
                      <td style='font-size:13px;color:#64748b;padding-bottom:6px;'>Rechnungsdatum</td>
                      <td style='font-size:13px;color:#1e293b;text-align:right;padding-bottom:6px;'>{$datum}</td>
                    </tr>
                    <tr>
                      <td style='font-size:13px;color:#64748b;padding-bottom:6px;'>Zahlungsfrist</td>
                      <td style='font-size:13px;color:#1e293b;text-align:right;padding-bottom:6px;'>{$faellig}</td>
                    </tr>
                    <tr style='border-top:2px solid #bfdbfe;'>
                      <td style='font-size:15px;font-weight:bold;color:#1e40af;padding-top:10px;'>Gesamtbetrag</td>
                      <td style='font-size:18px;font-weight:bold;color:#1e40af;text-align:right;padding-top:10px;'>{$betrag}</td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

            {$posHtml}

            <p style='margin:0 0 12px;font-size:14px;line-height:1.6;'>
                Bitte überweisen Sie den Betrag bis zum <strong>{$faellig}</strong> auf unser Konto.<br>
                Die Bankverbindung entnehmen Sie bitte der beigefügten PDF-Rechnung.
            </p>
            <p style='margin:0 0 24px;font-size:14px;line-height:1.6;'>
                Bei Fragen stehen wir Ihnen jederzeit gerne zur Verfügung.
            </p>
            <p style='margin:0;font-size:15px;'>Mit freundlichen Grüßen,<br>
            <strong>" . self::FIRMA . "</strong></p>
            "
        );

        return ['subject' => $subject, 'html' => $html];
    }

    // ════════════════════════════════════════════════════════
    //  MAHNUNG
    // ════════════════════════════════════════════════════════

    /**
     * HTML-Email für Zahlungserinnerung / Mahnung
     *
     * @param array $rechnung  Rechnungsdaten
     * @param array $kunde     Kundendaten
     * @param int   $stufe     Mahnstufe (1 = Erinnerung, 2 = 1. Mahnung, 3 = 2. Mahnung)
     */
    public static function mahnung(array $rechnung, array $kunde, int $stufe = 1): array
    {
        $name        = $kunde['ansprechpartner'] ?? $kunde['kunden_name'] ?? 'Damen und Herren';
        $rechnungsNr = $rechnung['rechnungs_nr'] ?? ('R-' . $rechnung['rechnung_id']);
        $faellig     = self::formatDate($rechnung['faellig_am'] ?? '');
        $betrag      = self::formatMoney((float)($rechnung['gesamtbetrag'] ?? 0));
        $verzug      = (int)($rechnung['tage_verzug'] ?? 0);

        $stufeTitel = match($stufe) {
            1 => 'Zahlungserinnerung',
            2 => '1. Mahnung',
            3 => '2. Mahnung — Letzte Aufforderung',
            default => 'Mahnung',
        };

        $subject = "{$stufeTitel} — Rechnung {$rechnungsNr}";

        // Farbe nach Mahnstufe
        $stufefarbe = match($stufe) {
            1 => '#d97706',  // Orange
            2 => '#dc2626',  // Rot
            3 => '#991b1b',  // Dunkelrot
            default => '#d97706',
        };

        $stufeText = match($stufe) {
            1 => 'Diese E-Mail dient als freundliche Erinnerung, dass folgende Rechnung noch offen ist.',
            2 => 'Trotz unserer Zahlungserinnerung haben wir bisher keinen Zahlungseingang feststellen können. Wir bitten Sie dringend, den ausstehenden Betrag umgehend zu begleichen.',
            3 => 'Da wir trotz mehrfacher Aufforderung keine Zahlung erhalten haben, sind wir gezwungen, rechtliche Schritte einzuleiten, sofern der Betrag nicht innerhalb von 7 Tagen eingeht.',
            default => '',
        };

        $html = self::wrap(
            $subject,
            "
            <!-- Mahnstufen-Banner -->
            <div style='background:{$stufefarbe};border-radius:8px;padding:14px 20px;margin:0 0 24px;'>
              <p style='margin:0;color:#fff;font-size:15px;font-weight:bold;'>{$stufeTitel}</p>
              <p style='margin:4px 0 0;color:rgba(255,255,255,.85);font-size:13px;'>
                Rechnung {$rechnungsNr} · {$verzug} Tage überfällig
              </p>
            </div>

            <p style='margin:0 0 16px;font-size:15px;'>Sehr geehrte/r {$name},</p>
            <p style='margin:0 0 20px;font-size:15px;line-height:1.6;'>{$stufeText}</p>

            <!-- Rechnungsdetails -->
            <table width='100%' cellpadding='0' cellspacing='0'
                   style='background:#fef2f2;border-radius:8px;margin:0 0 24px;border:1px solid #fecaca;'>
              <tr>
                <td style='padding:20px 24px;'>
                  <table width='100%'>
                    <tr>
                      <td style='font-size:13px;color:#64748b;padding-bottom:6px;'>Rechnungsnummer</td>
                      <td style='font-size:13px;font-weight:bold;color:#1e293b;text-align:right;padding-bottom:6px;'>{$rechnungsNr}</td>
                    </tr>
                    <tr>
                      <td style='font-size:13px;color:#64748b;padding-bottom:6px;'>Fällig seit</td>
                      <td style='font-size:13px;color:#dc2626;font-weight:bold;text-align:right;padding-bottom:6px;'>{$faellig} ({$verzug} Tage)</td>
                    </tr>
                    <tr style='border-top:2px solid #fecaca;'>
                      <td style='font-size:15px;font-weight:bold;color:#dc2626;padding-top:10px;'>Offener Betrag</td>
                      <td style='font-size:18px;font-weight:bold;color:#dc2626;text-align:right;padding-top:10px;'>{$betrag}</td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

            <p style='margin:0 0 12px;font-size:14px;line-height:1.6;'>
                Bitte überweisen Sie den ausstehenden Betrag <strong>umgehend</strong> auf unser Konto.
                Bei bereits erfolgter Zahlung bitten wir, dieses Schreiben als gegenstandslos zu betrachten.
            </p>

            <p style='margin:0 0 24px;font-size:14px;line-height:1.6;'>
                Bei Fragen oder Zahlungsschwierigkeiten stehen wir Ihnen gerne für ein Gespräch zur Verfügung.<br>
                Telefon: <strong>" . self::TEL . "</strong> · E-Mail: <strong>" . self::EMAIL . "</strong>
            </p>

            <p style='margin:0;font-size:15px;'>Mit freundlichen Grüßen,<br>
            <strong>" . self::FIRMA . "</strong></p>
            "
        );

        return ['subject' => $subject, 'html' => $html];
    }

    // ════════════════════════════════════════════════════════
    //  AUFTRAG-BENACHRICHTIGUNG
    // ════════════════════════════════════════════════════════

    /**
     * HTML-Email für Auftragsstatusänderung
     */
    public static function auftragBenachrichtigung(array $auftrag, array $kunde): array
    {
        $name      = $kunde['ansprechpartner'] ?? $kunde['kunden_name'] ?? 'Damen und Herren';
        $auftragNr = $auftrag['auftrag_nr'] ?? '';
        $titel     = $auftrag['titel']      ?? '';
        $status    = $auftrag['status']     ?? '';

        $statusInfo = match($status) {
            'geplant'        => ['text' => 'Ihr Auftrag wurde erfasst und wird zeitnah bearbeitet.', 'farbe' => '#0ea5e9', 'label' => 'Geplant'],
            'aktiv'          => ['text' => 'Wir haben mit der Bearbeitung Ihres Auftrags begonnen.', 'farbe' => '#8b5cf6', 'label' => 'In Bearbeitung'],
            'abgeschlossen'  => ['text' => 'Ihr Auftrag wurde erfolgreich abgeschlossen. Vielen Dank für Ihr Vertrauen!', 'farbe' => '#10b981', 'label' => 'Abgeschlossen'],
            'storniert'      => ['text' => 'Ihr Auftrag wurde storniert. Bitte kontaktieren Sie uns bei Fragen.', 'farbe' => '#6b7280', 'label' => 'Storniert'],
            default          => ['text' => 'Der Status Ihres Auftrags hat sich geändert.', 'farbe' => '#64748b', 'label' => ucfirst($status)],
        };

        $subject = "Auftrag {$auftragNr} — Status: {$statusInfo['label']}";

        $html = self::wrap(
            $subject,
            "
            <!-- Status-Banner -->
            <div style='background:{$statusInfo['farbe']};border-radius:8px;padding:14px 20px;margin:0 0 24px;'>
              <p style='margin:0;color:#fff;font-size:15px;font-weight:bold;'>Status: {$statusInfo['label']}</p>
              <p style='margin:4px 0 0;color:rgba(255,255,255,.85);font-size:13px;'>Auftrag {$auftragNr}</p>
            </div>

            <p style='margin:0 0 16px;font-size:15px;'>Sehr geehrte/r {$name},</p>
            <p style='margin:0 0 20px;font-size:15px;line-height:1.6;'>
                wir möchten Sie über eine Änderung Ihres Auftrags informieren.
            </p>

            <!-- Auftragsdetails -->
            <table width='100%' cellpadding='0' cellspacing='0'
                   style='background:#f8fafc;border-radius:8px;margin:0 0 20px;border:1px solid #e2e8f0;'>
              <tr>
                <td style='padding:20px 24px;'>
                  <p style='margin:0 0 6px;font-size:13px;color:#64748b;'>Auftragsnummer</p>
                  <p style='margin:0 0 14px;font-size:15px;font-weight:bold;color:#1e293b;'>{$auftragNr}</p>
                  <p style='margin:0 0 6px;font-size:13px;color:#64748b;'>Beschreibung</p>
                  <p style='margin:0 0 14px;font-size:15px;color:#1e293b;'>" . htmlspecialchars($titel) . "</p>
                  <p style='margin:0 0 6px;font-size:13px;color:#64748b;'>Aktueller Status</p>
                  <span style='display:inline-block;background:{$statusInfo['farbe']};color:#fff;
                               padding:4px 12px;border-radius:20px;font-size:13px;font-weight:bold;'>
                    {$statusInfo['label']}
                  </span>
                </td>
              </tr>
            </table>

            <p style='margin:0 0 24px;font-size:15px;line-height:1.6;'>{$statusInfo['text']}</p>
            <p style='margin:0 0 24px;font-size:14px;line-height:1.6;'>
                Bei Fragen erreichen Sie uns unter <strong>" . self::TEL . "</strong>
                oder per E-Mail: <strong>" . self::EMAIL . "</strong>
            </p>
            <p style='margin:0;font-size:15px;'>Mit freundlichen Grüßen,<br>
            <strong>" . self::FIRMA . "</strong></p>
            "
        );

        return ['subject' => $subject, 'html' => $html];
    }

    // ════════════════════════════════════════════════════════
    //  TEST-EMAIL
    // ════════════════════════════════════════════════════════

    public static function testEmail(): array
    {
        $subject = 'HandwerkerPro — SMTP Test erfolgreich';
        $html    = self::wrap($subject, "
            <div style='background:#f0fdf4;border-radius:8px;padding:20px 24px;border:1px solid #bbf7d0;margin:0 0 24px;'>
              <p style='margin:0;font-size:22px;'>✅</p>
              <p style='margin:8px 0 0;font-size:16px;font-weight:bold;color:#166534;'>SMTP-Verbindung erfolgreich!</p>
            </div>
            <p style='font-size:15px;line-height:1.6;'>
                Die E-Mail-Konfiguration von HandwerkerPro funktioniert korrekt.<br>
                E-Mails können erfolgreich versendet werden.
            </p>
            <p style='font-size:13px;color:#64748b;margin-top:20px;'>
                Gesendet am: " . date('d.m.Y H:i:s') . "
            </p>
        ");
        return ['subject' => $subject, 'html' => $html];
    }

    // ════════════════════════════════════════════════════════
    //  LAYOUT WRAPPER
    // ════════════════════════════════════════════════════════

    /**
     * Gemeinsames HTML-Layout für alle E-Mails
     */
    private static function wrap(string $title, string $content): string
    {
        $firma   = self::FIRMA;
        $adresse = self::ADRESSE;
        $tel     = self::TEL;
        $email   = self::EMAIL;
        $web     = self::WEB;
        $farbe   = self::FARBE;
        $year    = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$title}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 16px;">
  <tr>
    <td align="center">
      <table width="600" cellpadding="0" cellspacing="0"
             style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden;
                    box-shadow:0 4px 24px rgba(0,0,0,.08);">

        <!-- HEADER -->
        <tr>
          <td style="background:{$farbe};padding:24px 32px;">
            <table width="100%">
              <tr>
                <td>
                  <p style="margin:0;color:#fff;font-size:20px;font-weight:bold;">{$firma}</p>
                  <p style="margin:4px 0 0;color:rgba(255,255,255,.75);font-size:13px;">
                    Sanitär · Heizung · Klima
                  </p>
                </td>
                <td align="right">
                  <p style="margin:0;color:rgba(255,255,255,.6);font-size:11px;">{$adresse}</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- CONTENT -->
        <tr>
          <td style="padding:32px;">
            {$content}
          </td>
        </tr>

        <!-- FOOTER -->
        <tr>
          <td style="background:#f8fafc;padding:20px 32px;border-top:1px solid #e2e8f0;">
            <table width="100%">
              <tr>
                <td style="font-size:12px;color:#94a3b8;line-height:1.6;">
                  <strong style="color:#64748b;">{$firma}</strong><br>
                  {$adresse}<br>
                  Tel.: {$tel} · {$email} · {$web}
                </td>
                <td align="right" style="font-size:11px;color:#cbd5e1;">
                  © {$year} {$firma}
                </td>
              </tr>
            </table>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>

</body>
</html>
HTML;
    }

    // ── Hilfsmethoden ────────────────────────────────────────

    private static function formatMoney(float $amount): string
    {
        return number_format($amount, 2, ',', '.') . ' €';
    }

    private static function formatDate(string $date): string
    {
        if (!$date) return '—';
        $d = \DateTime::createFromFormat('Y-m-d', substr($date, 0, 10));
        return $d ? $d->format('d.m.Y') : $date;
    }
}
