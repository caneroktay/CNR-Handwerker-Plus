<?php
// ============================================================
//  HandwerkerPro — PdfController
//  GET /api/rechnungen/{id}/pdf
//  GET /api/angebote/{id}/pdf
//  GET /api/reports/monat/{jahr}/{monat}
// ============================================================
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Pdf\RechnungPdf;
use App\Pdf\AngebotPdf;
use App\Pdf\MonatsreportPdf;

class PdfController extends BaseController
{
    // ── Rechnung PDF ──────────────────────────────────────────

    /**
     * GET /api/rechnungen/{id}/pdf
     */
    public function rechnung(
        ServerRequestInterface $request,
        ResponseInterface      $response,
        array                  $args
    ): ResponseInterface {
        $id = (int) $args['id'];

        // Rechnung laden
        $rechnung = $this->db->fetchOne(
            "SELECT r.*,
                    a.titel AS auftrag_titel, a.auftrag_nr,
                    CASE WHEN k.typ = 'privat'
                         THEN CONCAT(kp.vorname, ' ', kp.nachname)
                         ELSE kf.firmenname END AS kunden_name,
                    kf.ansprechpartner
             FROM rechnung r
             JOIN auftrag a ON r.auftrag_id = a.auftrag_id
             JOIN kunden  k ON r.kunden_id  = k.kunden_id
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             WHERE r.rechnung_id = ?",
            [$id]
        );

        if (!$rechnung) {
            return $this->notFound($response, "Rechnung #{$id} nicht gefunden.");
        }

        // Positionen laden
        $positionen = $this->db->fetchAll(
            "SELECT rp.*, ap.bezeichnung, ap.typ
             FROM rechnung_position rp
             LEFT JOIN auftrag_position ap ON rp.position_id = ap.position_id
             WHERE rp.rechnung_id = ?
             ORDER BY rp.rpos_id",
            [$id]
        );

        // Kundenadresse
        $adresse = $this->db->fetchOne(
            "SELECT * FROM kunden_adressen WHERE kunden_id = ? LIMIT 1",
            [$rechnung['kunden_id']]
        );

        $kundeData = array_merge($rechnung, $adresse ?: []);

        // Rechnungsnummer generieren falls nicht vorhanden
        if (empty($rechnung['rechnungs_nr'])) {
            $rechnung['rechnungs_nr'] = 'R-' . date('Y') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
        }

        try {
            $pdf    = new RechnungPdf();
            $output = $pdf->generate($rechnung, $positionen, $kundeData);

            $filename = 'Rechnung_' . ($rechnung['rechnungs_nr'] ?? $id) . '.pdf';

            return $this->pdfResponse($response, $output, $filename);
        } catch (\Throwable $e) {
            return $this->serverError($response, 'PDF-Generierung fehlgeschlagen: ' . $e->getMessage());
        }
    }

    // ── Angebot PDF ───────────────────────────────────────────

    /**
     * GET /api/angebote/{id}/pdf
     */
    public function angebot(
        ServerRequestInterface $request,
        ResponseInterface      $response,
        array                  $args
    ): ResponseInterface {
        $id = (int) $args['id'];

        $angebot = $this->db->fetchOne(
            "SELECT a.*,
                    CASE WHEN k.typ = 'privat'
                         THEN CONCAT(kp.vorname, ' ', kp.nachname)
                         ELSE kf.firmenname END AS kunden_name,
                    kf.ansprechpartner
             FROM angebot a
             JOIN kunden  k ON a.kunden_id = k.kunden_id
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             WHERE a.angebot_id = ?",
            [$id]
        );

        if (!$angebot) {
            return $this->notFound($response, "Angebot #{$id} nicht gefunden.");
        }

        // Angebotspositionen
        $positionen = $this->db->fetchAll(
            "SELECT ap.*
             FROM auftrag_position ap
             JOIN auftrag au ON ap.auftrag_id = au.auftrag_id
             WHERE au.angebot_id = ?
             ORDER BY ap.position_id",
            [$id]
        );

        $adresse = $this->db->fetchOne(
            "SELECT * FROM kunden_adressen WHERE kunden_id = ? LIMIT 1",
            [$angebot['kunden_id']]
        );

        // Angebotsnummer generieren
        if (empty($angebot['angebot_nr'])) {
            $angebot['angebot_nr'] = 'ANG-' . date('Y') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
        }

        $kundeData = array_merge($angebot, $adresse ?: []);

        try {
            $pdf      = new AngebotPdf();
            $output   = $pdf->generate($angebot, $positionen, $kundeData);
            $filename = 'Angebot_' . $angebot['angebot_nr'] . '.pdf';

            return $this->pdfResponse($response, $output, $filename);
        } catch (\Throwable $e) {
            return $this->serverError($response, 'PDF-Generierung fehlgeschlagen: ' . $e->getMessage());
        }
    }

    // ── Monatsreport PDF ──────────────────────────────────────

    /**
     * GET /api/reports/monat/{jahr}/{monat}
     */
    public function monatsreport(
        ServerRequestInterface $request,
        ResponseInterface      $response,
        array                  $args
    ): ResponseInterface {
        $jahr  = (int) $args['jahr'];
        $monat = (int) $args['monat'];

        // Validierung
        if ($jahr < 2020 || $jahr > 2099) {
            return $this->unprocessable($response, 'Ungültiges Jahr. Erwartet: 2020–2099');
        }
        if ($monat < 1 || $monat > 12) {
            return $this->unprocessable($response, 'Ungültiger Monat. Erwartet: 1–12');
        }

        $von = sprintf('%04d-%02d-01', $jahr, $monat);
        $bis = date('Y-m-t', strtotime($von)); // Letzter Tag des Monats

        // ── KPIs ──
        // Gesamtumsatz: alle abgeschlossenen/abgerechneten Aufträge des Monats
        // Datum-Filter auf erstellt_am (zuverlässiger als abgeschlossen_am)
        $kpis = $this->db->fetchOne(
            "SELECT
                COALESCE((
                    SELECT SUM(ap.menge * ap.einzelpreis_bei_bestellung)
                    FROM auftrag a2
                    JOIN auftrag_position ap ON a2.auftrag_id = ap.auftrag_id
                    WHERE a2.status IN ('abgeschlossen','abgerechnet')
                      AND DATE(a2.erstellt_am) BETWEEN ? AND ?
                ), 0) AS gesamtumsatz,
                (SELECT COUNT(*)
                 FROM auftrag a2
                 WHERE a2.status IN ('abgeschlossen','abgerechnet')
                   AND DATE(a2.erstellt_am) BETWEEN ? AND ?) AS auftraege_abgeschlossen,
                (SELECT COUNT(*)
                 FROM auftrag a2
                 WHERE a2.status NOT IN ('abgeschlossen','abgerechnet','storniert')) AS auftraege_offen,
                (SELECT COUNT(*)
                 FROM rechnung r2
                 WHERE r2.status != 'bezahlt') AS rechnungen_offen,
                (SELECT COALESCE(SUM(rp2.menge * rp2.einzelpreis_bei_rechnung * (1 + rp2.mwst_satz/100)), 0)
                 FROM rechnung r2
                 JOIN rechnung_position rp2 ON r2.rechnung_id = rp2.rechnung_id
                 WHERE r2.status != 'bezahlt') AS offener_betrag,
                (SELECT COUNT(*)
                 FROM kunden
                 WHERE DATE(erstellt_am) BETWEEN ? AND ?) AS neue_kunden",
            [$von, $bis, $von, $bis, $von, $bis]
        );

        // ── Top 5 Kunden ──
        $topKunden = $this->db->fetchAll(
            "SELECT
                CASE WHEN k.typ = 'privat'
                     THEN CONCAT(kp.vorname, ' ', kp.nachname)
                     ELSE kf.firmenname END AS kunden_name,
                COUNT(DISTINCT a.auftrag_id) AS anzahl_auftraege,
                COALESCE(SUM(ap.menge * ap.einzelpreis_bei_bestellung), 0) AS gesamt_umsatz
             FROM kunden k
             JOIN auftrag a ON k.kunden_id = a.kunden_id
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             LEFT JOIN auftrag_position ap ON a.auftrag_id = ap.auftrag_id
             WHERE a.status IN ('abgeschlossen','abgerechnet')
               AND DATE(a.erstellt_am) BETWEEN ? AND ?
             GROUP BY k.kunden_id
             ORDER BY gesamt_umsatz DESC
             LIMIT 5",
            [$von, $bis]
        );

        // ── Mitarbeiter-Auslastung ──
        $mitarbeiter = $this->db->fetchAll(
            "SELECT
                m.vorname, m.nachname, m.rolle,
                COUNT(DISTINCT t.auftrag_id) AS anzahl_auftraege,
                COALESCE(SUM(TIMESTAMPDIFF(MINUTE, t.start_datetime, t.end_datetime)) / 60, 0) AS geplante_stunden,
                COALESCE((
                    SELECT SUM(rp2.menge * rp2.einzelpreis_bei_rechnung)
                    FROM termin t2
                    JOIN rechnung r2 ON t2.auftrag_id = r2.auftrag_id
                    JOIN rechnung_position rp2 ON r2.rechnung_id = rp2.rechnung_id
                    WHERE t2.mitarbeiter_id = m.mitarbeiter_id
                      AND DATE(t2.start_datetime) BETWEEN ? AND ?
                ), 0) AS monats_umsatz
             FROM mitarbeiter m
             LEFT JOIN termin t ON m.mitarbeiter_id = t.mitarbeiter_id
               AND DATE(t.start_datetime) BETWEEN ? AND ?
             WHERE m.aktiv = 1
             GROUP BY m.mitarbeiter_id
             ORDER BY geplante_stunden DESC",
            [$von, $bis, $von, $bis]
        );

        // ── Offene Aufträge ──
        $offeneAuftraege = $this->db->fetchAll(
            "SELECT a.auftrag_id, a.auftrag_nr, a.titel, a.status, a.prioritaet, a.erstellt_am,
                    COALESCE(SUM(ap.menge * ap.einzelpreis_bei_bestellung), 0) AS netto_summe
             FROM auftrag a
             LEFT JOIN auftrag_position ap ON a.auftrag_id = ap.auftrag_id
             WHERE a.status NOT IN ('abgeschlossen','abgerechnet','storniert')
             GROUP BY a.auftrag_id
             ORDER BY FIELD(a.prioritaet,'notfall','dringend','normal','niedrig'), a.erstellt_am",
            []
        );

        // ── Offene Rechnungen ──
        $offeneRechnungen = $this->db->fetchAll(
            "SELECT r.rechnung_id, r.faellig_am, r.status,
                    CASE WHEN k.typ = 'privat'
                         THEN CONCAT(kp.vorname, ' ', kp.nachname)
                         ELSE kf.firmenname END AS kunden_name,
                    DATEDIFF(CURDATE(), r.faellig_am) AS tage_verzug,
                    COALESCE(SUM(rp.menge * rp.einzelpreis_bei_rechnung * (1 + rp.mwst_satz/100)), 0) AS brutto_betrag
             FROM rechnung r
             JOIN kunden k ON r.kunden_id = k.kunden_id
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             LEFT JOIN rechnung_position rp ON r.rechnung_id = rp.rechnung_id
             WHERE r.status != 'bezahlt'
             GROUP BY r.rechnung_id
             ORDER BY tage_verzug DESC",
            []
        );

        // ── Top 10 Material ──
        $topMaterial = $this->db->fetchAll(
            "SELECT m.name, m.einheit,
                    SUM(am.menge) AS gesamt_verbrauch,
                    SUM(am.menge * m.preis_pro_einheit) AS gesamt_wert
             FROM auftrag_material am
             JOIN material m ON am.material_id = m.material_id
             JOIN auftrag  a ON am.auftrag_id  = a.auftrag_id
             WHERE DATE(a.erstellt_am) BETWEEN ? AND ?
             GROUP BY m.material_id
             ORDER BY gesamt_verbrauch DESC
             LIMIT 10",
            [$von, $bis]
        );

        try {
            $pdf    = new MonatsreportPdf();
            $output = $pdf->generate(
                $jahr, $monat, $kpis ?? [],
                $topKunden, $mitarbeiter,
                $offeneAuftraege, $offeneRechnungen, $topMaterial
            );

            $filename = sprintf('Monatsreport_%04d_%02d.pdf', $jahr, $monat);
            return $this->pdfResponse($response, $output, $filename);
        } catch (\Throwable $e) {
            return $this->serverError($response, 'PDF-Generierung fehlgeschlagen: ' . $e->getMessage());
        }
    }

    // ── Hilfsmethode: PDF-Response ────────────────────────────
    private function pdfResponse(ResponseInterface $response, string $pdfContent, string $filename): ResponseInterface
    {
        $params  = request_params_from_globals();
        $inline  = isset($_GET['inline']);  // ?inline → im Browser anzeigen statt Download

        $disposition = $inline
            ? 'inline; filename="' . $filename . '"'
            : 'attachment; filename="' . $filename . '"';

        $response->getBody()->write($pdfContent);
        return $response
            ->withHeader('Content-Type',        'application/pdf')
            ->withHeader('Content-Disposition', $disposition)
            ->withHeader('Content-Length',      (string) strlen($pdfContent))
            ->withHeader('Cache-Control',       'no-cache, no-store')
            ->withStatus(200);
    }
}

// Globale Hilfsfunktion für Query-Parameter (SAPI-Kompatibilität)
if (!function_exists('request_params_from_globals')) {
    function request_params_from_globals(): array {
        return $_GET ?? [];
    }
}
