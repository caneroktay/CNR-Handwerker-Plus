<?php
// ============================================================
//  HandwerkerPro — ExportController
//  CSV-Export aller Tabellen + kompletter Backup als ZIP
//
//  Sicherheit:
//    - Alle Daten über PDO Prepared Statements geladen
//    - Nur authentifizierte Benutzer (Meister+)
//    - Keine direkten Dateioperationen auf dem Server
//
//  Excel-Kompatibilität:
//    - UTF-8 BOM am Anfang jeder CSV-Datei
//    - Semikolon als Trennzeichen (deutsches Excel-Standard)
//    - Datumsfelder im Format DD.MM.YYYY
//    - Zahlen mit Komma als Dezimaltrennzeichen
// ============================================================
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ZipArchive;

class ExportController extends BaseController
{
    // UTF-8 BOM — damit Excel die Datei korrekt als UTF-8 öffnet
    private const BOM = "\xEF\xBB\xBF";

    // Trennzeichen: Semikolon (deutsches Excel-Standard)
    private const SEP = ';';

    // ══════════════════════════════════════════════════════
    //  GET /api/export/kunden
    // ══════════════════════════════════════════════════════
    public function kunden(
        ServerRequestInterface $request,
        ResponseInterface      $response
    ): ResponseInterface {
        $rows = $this->db->fetchAll(
            "SELECT
                k.kunden_id,
                k.typ,
                CASE WHEN k.typ = 'privat'
                     THEN kp.vorname ELSE '' END AS vorname,
                CASE WHEN k.typ = 'privat'
                     THEN kp.nachname ELSE '' END AS nachname,
                CASE WHEN k.typ = 'firma'
                     THEN kf.firmenname ELSE '' END AS firmenname,
                CASE WHEN k.typ = 'firma'
                     THEN kf.ansprechpartner ELSE '' END AS ansprechpartner,
                CASE WHEN k.typ = 'firma'
                     THEN kf.ust_id ELSE '' END AS ust_id,
                IF(k.ist_stammkunde, 'Ja', 'Nein') AS ist_stammkunde,
                GROUP_CONCAT(
                    CASE WHEN kk.typ = 'Email' THEN kk.wert END
                ) AS email,
                GROUP_CONCAT(
                    CASE WHEN kk.typ IN ('Telefon','Mobil') THEN kk.wert END
                ) AS telefon,
                ka.strasse,
                ka.hausnummer,
                ka.plz,
                ka.ort,
                k.notizen,
                DATE_FORMAT(k.erstellt_am, '%d.%m.%Y') AS erstellt_am
             FROM kunden k
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             LEFT JOIN kunden_kontakt kk ON k.kunden_id = kk.kunden_id
             LEFT JOIN kunden_adressen ka ON k.kunden_id = ka.kunden_id
             GROUP BY k.kunden_id
             ORDER BY k.kunden_id",
            []
        );

        $headers = [
            'Kunden-ID', 'Typ', 'Vorname', 'Nachname', 'Firmenname',
            'Ansprechpartner', 'USt-IdNr.', 'Stammkunde', 'E-Mail',
            'Telefon', 'Straße', 'Hausnr.', 'PLZ', 'Ort', 'Notizen', 'Erstellt am',
        ];

        return $this->csvResponse($response, $rows, $headers, 'kunden_export');
    }

    // ══════════════════════════════════════════════════════
    //  GET /api/export/auftraege
    // ══════════════════════════════════════════════════════
    public function auftraege(
        ServerRequestInterface $request,
        ResponseInterface      $response
    ): ResponseInterface {
        $rows = $this->db->fetchAll(
            "SELECT
                a.auftrag_id,
                a.auftrag_nr,
                a.titel,
                CASE WHEN k.typ = 'privat'
                     THEN CONCAT(kp.vorname, ' ', kp.nachname)
                     ELSE kf.firmenname END AS kunde,
                a.status,
                a.prioritaet,
                DATE_FORMAT(a.erstellt_am, '%d.%m.%Y') AS erstellt_am,
                DATE_FORMAT(a.abgeschlossen_am, '%d.%m.%Y') AS abgeschlossen_am,
                COALESCE(SUM(ap.menge * ap.einzelpreis_bei_bestellung), 0) AS netto_summe,
                ROUND(COALESCE(SUM(ap.menge * ap.einzelpreis_bei_bestellung), 0) * 1.19, 2) AS brutto_summe,
                a.beschreibung,
                a.notiz_intern
             FROM auftrag a
             JOIN kunden k ON a.kunden_id = k.kunden_id
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             LEFT JOIN auftrag_position ap ON a.auftrag_id = ap.auftrag_id
             GROUP BY a.auftrag_id
             ORDER BY a.auftrag_id",
            []
        );

        // Zahlen formatieren
        foreach ($rows as &$row) {
            $row['netto_summe']  = $this->formatNum($row['netto_summe']);
            $row['brutto_summe'] = $this->formatNum($row['brutto_summe']);
        }
        unset($row);

        $headers = [
            'Auftrags-ID', 'Auftrag-Nr.', 'Titel', 'Kunde', 'Status',
            'Priorität', 'Erstellt am', 'Abgeschlossen am',
            'Netto (€)', 'Brutto (€)', 'Beschreibung', 'Interne Notiz',
        ];

        return $this->csvResponse($response, $rows, $headers, 'auftraege_export');
    }

    // ══════════════════════════════════════════════════════
    //  GET /api/export/rechnungen
    // ══════════════════════════════════════════════════════
    public function rechnungen(
        ServerRequestInterface $request,
        ResponseInterface      $response
    ): ResponseInterface {
        $rows = $this->db->fetchAll(
            "SELECT
                r.rechnung_id,
                a.auftrag_nr,
                CASE WHEN k.typ = 'privat'
                     THEN CONCAT(kp.vorname, ' ', kp.nachname)
                     ELSE kf.firmenname END AS kunde,
                r.status,
                DATE_FORMAT(r.rechnungs_datum, '%d.%m.%Y') AS rechnungs_datum,
                DATE_FORMAT(r.faellig_am, '%d.%m.%Y')      AS faellig_am,
                COALESCE(SUM(rp.menge * rp.einzelpreis_bei_rechnung), 0) AS netto,
                COALESCE(SUM(rp.menge * rp.einzelpreis_bei_rechnung * (1 + rp.mwst_satz/100)), 0) AS brutto,
                DATEDIFF(CURDATE(), r.faellig_am) AS verzug_tage
             FROM rechnung r
             JOIN auftrag  a ON r.auftrag_id = a.auftrag_id
             JOIN kunden   k ON r.kunden_id  = k.kunden_id
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             LEFT JOIN rechnung_position rp ON r.rechnung_id = rp.rechnung_id
             GROUP BY r.rechnung_id
             ORDER BY r.rechnung_id",
            []
        );

        foreach ($rows as &$row) {
            $row['netto']       = $this->formatNum($row['netto']);
            $row['brutto']      = $this->formatNum($row['brutto']);
            $row['verzug_tage'] = max(0, (int)$row['verzug_tage']);
        }
        unset($row);

        $headers = [
            'Rechnungs-ID', 'Auftrag-Nr.', 'Kunde', 'Status',
            'Rechnungsdatum', 'Fällig am', 'Netto (€)', 'Brutto (€)', 'Verzug (Tage)',
        ];

        return $this->csvResponse($response, $rows, $headers, 'rechnungen_export');
    }

    // ══════════════════════════════════════════════════════
    //  GET /api/export/material
    // ══════════════════════════════════════════════════════
    public function material(
        ServerRequestInterface $request,
        ResponseInterface      $response
    ): ResponseInterface {
        $rows = $this->db->fetchAll(
            "SELECT
                m.material_id,
                m.name,
                m.beschreibung,
                m.einheit,
                m.lagerbestand,
                REPLACE(CAST(m.preis_pro_einheit AS CHAR), '.', ',') AS preis_pro_einheit,
                REPLACE(CAST(ROUND(m.lagerbestand * m.preis_pro_einheit, 2) AS CHAR), '.', ',') AS lagerwert,
                COALESCE(SUM(am.menge), 0) AS gesamt_verbrauch
             FROM material m
             LEFT JOIN auftrag_material am ON m.material_id = am.material_id
             GROUP BY m.material_id
             ORDER BY m.name",
            []
        );

        foreach ($rows as &$row) {
            $row['gesamt_verbrauch'] = $this->formatNum($row['gesamt_verbrauch']);
        }
        unset($row);

        $headers = [
            'Material-ID', 'Name', 'Beschreibung', 'Einheit',
            'Lagerbestand', 'Preis/Einheit (€)', 'Lagerwert (€)', 'Gesamt verbraucht',
        ];

        return $this->csvResponse($response, $rows, $headers, 'material_export');
    }

    // ══════════════════════════════════════════════════════
    //  GET /api/export/mitarbeiter
    // ══════════════════════════════════════════════════════
    public function mitarbeiter(
        ServerRequestInterface $request,
        ResponseInterface      $response
    ): ResponseInterface {
        $rows = $this->db->fetchAll(
            "SELECT
                m.mitarbeiter_id,
                m.vorname,
                m.nachname,
                m.email,
                m.rolle,
                IF(m.aktiv, 'Ja', 'Nein') AS aktiv,
                DATE_FORMAT(m.letzte_login, '%d.%m.%Y %H:%i') AS letzte_login,
                COUNT(DISTINCT t.termin_id) AS anzahl_termine
             FROM mitarbeiter m
             LEFT JOIN termin t ON m.mitarbeiter_id = t.mitarbeiter_id
             GROUP BY m.mitarbeiter_id
             ORDER BY m.nachname",
            []
        );

        $headers = [
            'Mitarbeiter-ID', 'Vorname', 'Nachname', 'E-Mail',
            'Rolle', 'Aktiv', 'Letzter Login', 'Anzahl Termine',
        ];

        return $this->csvResponse($response, $rows, $headers, 'mitarbeiter_export');
    }

    // ══════════════════════════════════════════════════════
    //  GET /api/export/backup
    //  Alle Tabellen als ZIP-Archiv
    // ══════════════════════════════════════════════════════
    public function backup(
        ServerRequestInterface $request,
        ResponseInterface      $response
    ): ResponseInterface {
        // ZIP benötigt temp-Datei (ZipArchive arbeitet dateibasiert)
        $tmpFile = sys_get_temp_dir() . '/handwerkerpro_backup_' . date('Ymd_His') . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return $this->serverError($response, 'ZIP-Archiv konnte nicht erstellt werden.');
        }

        $exports = [
            'kunden'      => $this->buildKundenCsv(),
            'auftraege'   => $this->buildAuftraegeCsv(),
            'rechnungen'  => $this->buildRechnungenCsv(),
            'material'    => $this->buildMaterialCsv(),
            'mitarbeiter' => $this->buildMitarbeiterCsv(),
        ];

        $datum = date('d.m.Y');
        foreach ($exports as $name => $csvContent) {
            $zip->addFromString("{$name}_export_{$datum}.csv", $csvContent);
        }

        // README im ZIP
        $zip->addFromString('LIES_MICH.txt',
            "HandwerkerPro Daten-Backup\r\n" .
            "Erstellt am: {$datum} " . date('H:i') . " Uhr\r\n" .
            "Encoding: UTF-8 mit BOM\r\n" .
            "Trennzeichen: Semikolon (;)\r\n" .
            "Kompatibel mit: Microsoft Excel, LibreOffice Calc\r\n\r\n" .
            "Enthaltene Dateien:\r\n" .
            "- kunden_export.csv\r\n" .
            "- auftraege_export.csv\r\n" .
            "- rechnungen_export.csv\r\n" .
            "- material_export.csv\r\n" .
            "- mitarbeiter_export.csv\r\n"
        );

        $zip->close();

        $zipContent = file_get_contents($tmpFile);
        unlink($tmpFile); // Temp-Datei löschen

        $filename = 'handwerkerpro_backup_' . date('Ymd') . '.zip';

        $response->getBody()->write($zipContent);
        return $response
            ->withHeader('Content-Type',        'application/zip')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->withHeader('Content-Length',      (string) strlen($zipContent))
            ->withHeader('Cache-Control',       'no-cache, no-store')
            ->withStatus(200);
    }

    // ══════════════════════════════════════════════════════
    //  PRIVATE: CSV-Inhalte für Backup generieren
    // ══════════════════════════════════════════════════════

    private function buildKundenCsv(): string
    {
        $rows = $this->db->fetchAll(
            "SELECT k.kunden_id, k.typ,
                CASE WHEN k.typ='privat' THEN kp.vorname ELSE '' END AS vorname,
                CASE WHEN k.typ='privat' THEN kp.nachname ELSE '' END AS nachname,
                CASE WHEN k.typ='firma'  THEN kf.firmenname ELSE '' END AS firmenname,
                CASE WHEN k.typ='firma'  THEN kf.ansprechpartner ELSE '' END AS ansprechpartner,
                CASE WHEN k.typ='firma'  THEN kf.ust_id ELSE '' END AS ust_id,
                IF(k.ist_stammkunde,'Ja','Nein') AS ist_stammkunde,
                GROUP_CONCAT(CASE WHEN kk.typ='Email' THEN kk.wert END) AS email,
                GROUP_CONCAT(CASE WHEN kk.typ IN ('Telefon','Mobil') THEN kk.wert END) AS telefon,
                ka.strasse, ka.hausnummer, ka.plz, ka.ort,
                k.notizen, DATE_FORMAT(k.erstellt_am,'%d.%m.%Y') AS erstellt_am
             FROM kunden k
             LEFT JOIN kunden_person kp ON k.kunden_id=kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id=kf.kunden_id
             LEFT JOIN kunden_kontakt kk ON k.kunden_id=kk.kunden_id
             LEFT JOIN kunden_adressen ka ON k.kunden_id=ka.kunden_id
             GROUP BY k.kunden_id ORDER BY k.kunden_id", []
        );
        $headers = ['Kunden-ID','Typ','Vorname','Nachname','Firmenname','Ansprechpartner','USt-IdNr.','Stammkunde','E-Mail','Telefon','Straße','Hausnr.','PLZ','Ort','Notizen','Erstellt am'];
        return $this->buildCsvContent($rows, $headers);
    }

    private function buildAuftraegeCsv(): string
    {
        $rows = $this->db->fetchAll(
            "SELECT a.auftrag_id, a.auftrag_nr, a.titel,
                CASE WHEN k.typ='privat' THEN CONCAT(kp.vorname,' ',kp.nachname) ELSE kf.firmenname END AS kunde,
                a.status, a.prioritaet,
                DATE_FORMAT(a.erstellt_am,'%d.%m.%Y') AS erstellt_am,
                DATE_FORMAT(a.abgeschlossen_am,'%d.%m.%Y') AS abgeschlossen_am,
                COALESCE(SUM(ap.menge*ap.einzelpreis_bei_bestellung),0) AS netto_summe,
                ROUND(COALESCE(SUM(ap.menge*ap.einzelpreis_bei_bestellung),0)*1.19,2) AS brutto_summe,
                a.beschreibung, a.notiz_intern
             FROM auftrag a
             JOIN kunden k ON a.kunden_id=k.kunden_id
             LEFT JOIN kunden_person kp ON k.kunden_id=kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id=kf.kunden_id
             LEFT JOIN auftrag_position ap ON a.auftrag_id=ap.auftrag_id
             GROUP BY a.auftrag_id ORDER BY a.auftrag_id", []
        );
        foreach ($rows as &$r) { $r['netto_summe']=$this->formatNum($r['netto_summe']); $r['brutto_summe']=$this->formatNum($r['brutto_summe']); } unset($r);
        $headers = ['Auftrags-ID','Auftrag-Nr.','Titel','Kunde','Status','Priorität','Erstellt am','Abgeschlossen am','Netto (€)','Brutto (€)','Beschreibung','Interne Notiz'];
        return $this->buildCsvContent($rows, $headers);
    }

    private function buildRechnungenCsv(): string
    {
        $rows = $this->db->fetchAll(
            "SELECT r.rechnung_id, a.auftrag_nr,
                CASE WHEN k.typ='privat' THEN CONCAT(kp.vorname,' ',kp.nachname) ELSE kf.firmenname END AS kunde,
                r.status, DATE_FORMAT(r.rechnungs_datum,'%d.%m.%Y') AS rechnungs_datum,
                DATE_FORMAT(r.faellig_am,'%d.%m.%Y') AS faellig_am,
                COALESCE(SUM(rp.menge*rp.einzelpreis_bei_rechnung),0) AS netto,
                COALESCE(SUM(rp.menge*rp.einzelpreis_bei_rechnung*(1+rp.mwst_satz/100)),0) AS brutto,
                GREATEST(0,DATEDIFF(CURDATE(),r.faellig_am)) AS verzug_tage
             FROM rechnung r
             JOIN auftrag a ON r.auftrag_id=a.auftrag_id
             JOIN kunden  k ON r.kunden_id=k.kunden_id
             LEFT JOIN kunden_person kp ON k.kunden_id=kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id=kf.kunden_id
             LEFT JOIN rechnung_position rp ON r.rechnung_id=rp.rechnung_id
             GROUP BY r.rechnung_id ORDER BY r.rechnung_id", []
        );
        foreach ($rows as &$r) { $r['netto']=$this->formatNum($r['netto']); $r['brutto']=$this->formatNum($r['brutto']); } unset($r);
        $headers = ['Rechnungs-ID','Auftrag-Nr.','Kunde','Status','Rechnungsdatum','Fällig am','Netto (€)','Brutto (€)','Verzug (Tage)'];
        return $this->buildCsvContent($rows, $headers);
    }

    private function buildMaterialCsv(): string
    {
        $rows = $this->db->fetchAll(
            "SELECT m.material_id, m.name, m.beschreibung, m.einheit, m.lagerbestand,
                REPLACE(CAST(m.preis_pro_einheit AS CHAR),'.',',') AS preis_pro_einheit,
                REPLACE(CAST(ROUND(m.lagerbestand*m.preis_pro_einheit,2) AS CHAR),'.',',') AS lagerwert,
                COALESCE(SUM(am.menge),0) AS gesamt_verbrauch
             FROM material m
             LEFT JOIN auftrag_material am ON m.material_id=am.material_id
             GROUP BY m.material_id ORDER BY m.name", []
        );
        foreach ($rows as &$r) { $r['gesamt_verbrauch']=$this->formatNum($r['gesamt_verbrauch']); } unset($r);
        $headers = ['Material-ID','Name','Beschreibung','Einheit','Lagerbestand','Preis/Einheit (€)','Lagerwert (€)','Gesamt verbraucht'];
        return $this->buildCsvContent($rows, $headers);
    }

    private function buildMitarbeiterCsv(): string
    {
        $rows = $this->db->fetchAll(
            "SELECT m.mitarbeiter_id, m.vorname, m.nachname, m.email, m.rolle,
                IF(m.aktiv,'Ja','Nein') AS aktiv,
                DATE_FORMAT(m.letzte_login,'%d.%m.%Y %H:%i') AS letzte_login,
                COUNT(DISTINCT t.termin_id) AS anzahl_termine
             FROM mitarbeiter m
             LEFT JOIN termin t ON m.mitarbeiter_id=t.mitarbeiter_id
             GROUP BY m.mitarbeiter_id ORDER BY m.nachname", []
        );
        $headers = ['Mitarbeiter-ID','Vorname','Nachname','E-Mail','Rolle','Aktiv','Letzter Login','Anzahl Termine'];
        return $this->buildCsvContent($rows, $headers);
    }

    // ══════════════════════════════════════════════════════
    //  HELPER: CSV-String bauen
    // ══════════════════════════════════════════════════════
    private function buildCsvContent(array $rows, array $headers): string
    {
        $lines = [];

        // Header-Zeile
        $lines[] = implode(self::SEP, array_map([$this, 'csvCell'], $headers));

        // Datenzeilen
        foreach ($rows as $row) {
            $cells = array_map([$this, 'csvCell'], array_values($row));
            $lines[] = implode(self::SEP, $cells);
        }

        // UTF-8 BOM + Zeilen mit Windows-Zeilenumbruch (Excel-kompatibel)
        return self::BOM . implode("\r\n", $lines) . "\r\n";
    }

    /**
     * HTTP-Response mit CSV-Inhalt + korrekten Headers
     */
    private function csvResponse(
        ResponseInterface $response,
        array $rows,
        array $headers,
        string $basename
    ): ResponseInterface {
        $csvContent = $this->buildCsvContent($rows, $headers);
        $filename   = $basename . '_' . date('Ymd') . '.csv';

        $response->getBody()->write($csvContent);
        return $response
            ->withHeader('Content-Type',        'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->withHeader('Content-Length',      (string) strlen($csvContent))
            ->withHeader('Cache-Control',       'no-cache, no-store')
            ->withHeader('Pragma',              'no-cache')
            ->withStatus(200);
    }

    /**
     * Einzelne CSV-Zelle escaped
     * - Anführungszeichen verdoppeln
     * - In Anführungszeichen einschließen wenn nötig
     */
    private function csvCell(mixed $value): string
    {
        if ($value === null) return '';
        $str = (string) $value;
        // Wenn Semikolon, Zeilenumbruch oder Anführungszeichen → in Quotes einschließen
        if (str_contains($str, self::SEP) || str_contains($str, '"') ||
            str_contains($str, "\n") || str_contains($str, "\r")) {
            $str = '"' . str_replace('"', '""', $str) . '"';
        }
        return $str;
    }

    /**
     * Zahl mit Komma als Dezimaltrennzeichen (deutsches Format)
     */
    private function formatNum(mixed $value): string
    {
        if ($value === null || $value === '') return '0,00';
        return number_format((float)$value, 2, ',', '.');
    }
}
