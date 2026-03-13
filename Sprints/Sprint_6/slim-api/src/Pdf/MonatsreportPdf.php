<?php
// ============================================================
//  HandwerkerPro — MonatsreportPdf
//  Management-Report: Umsatz, Aufträge, Kunden, Material
// ============================================================
declare(strict_types=1);

namespace App\Pdf;

class MonatsreportPdf extends BasePdf
{
    protected string $docType = 'Monatsreport';

    public function generate(
        int    $jahr,
        int    $monat,
        array  $kpis,
        array  $topKunden,
        array  $mitarbeiter,
        array  $offeneAuftraege,
        array  $offeneRechnungen,
        array  $topMaterial
    ): string {
        $monatsname = $this->_monatsname($monat);
        $this->SetTitle("Monatsreport {$monatsname} {$jahr}");

        $this->AddPage();
        $this->_deckblatt($jahr, $monat, $monatsname, $kpis);
        $this->_kpiGrid($kpis);
        $this->_topKunden($topKunden);
        $this->_mitarbeiterAuslastung($mitarbeiter);

        $this->AddPage();
        $this->_offeneAuftraege($offeneAuftraege);
        $this->_offeneRechnungen($offeneRechnungen);
        $this->_topMaterial($topMaterial);
        $this->_abschluss($jahr, $monat);

        return $this->Output('', 'S');
    }

    // ── Deckblatt / Seitenüberschrift ─────────────────────────
    private function _deckblatt(int $jahr, int $monat, string $monatsname, array $kpis): void
    {
        // Großer Titel-Bereich
        $this->SetFillColor(...self::COLOR_PRIMARY);
        $this->Rect(18, 26, $this->getPageWidth() - 36, 24, 'F');

        $this->SetTextColor(...self::COLOR_WHITE);
        $this->SetFont('helvetica', 'B', 18);
        $this->SetXY(22, 29);
        $this->Cell(0, 9, 'Monatsreport', 0, 2);
        $this->SetX(22);
        $this->SetFont('helvetica', '', 12);
        $this->Cell(0, 7, $monatsname . ' ' . $jahr . ' · ' . self::FIRMA['name'], 0, 0);

        $this->SetTextColor(...self::COLOR_TEXT);
        $this->SetY(56);
    }

    // ── KPI-Kacheln (2x3 Grid) ────────────────────────────────
    private function _kpiGrid(array $kpis): void
    {
        $this->sectionTitle('Kennzahlen auf einen Blick');

        $kpiDefs = [
            ['Gesamtumsatz (netto)',       $this->formatMoney((float)($kpis['gesamtumsatz']        ?? 0)), self::COLOR_PRIMARY],
            ['Abgeschlossene Aufträge',    (string)(int)($kpis['auftraege_abgeschlossen']          ?? 0), [16, 185, 129]],
            ['Offene Aufträge',            (string)(int)($kpis['auftraege_offen']                  ?? 0), [245, 158, 11]],
            ['Unbezahlte Rechnungen',      (string)(int)($kpis['rechnungen_offen']                 ?? 0), [239, 68, 68]],
            ['Offener Betrag',             $this->formatMoney((float)($kpis['offener_betrag']      ?? 0)), [239, 68, 68]],
            ['Neue Kunden',                (string)(int)($kpis['neue_kunden']                      ?? 0), [139, 92, 246]],
        ];

        $colW  = 57.5;
        $rowH  = 22;
        $startX = 18;
        $startY = $this->GetY();
        $gap   = 3;

        foreach ($kpiDefs as $idx => [$label, $value, $color]) {
            $col = $idx % 3;
            $row = intdiv($idx, 3);
            $x   = $startX + $col * ($colW + $gap);
            $y   = $startY + $row * ($rowH + $gap);
            $this->kpiBox($x, $y, $colW, $rowH, $label, $value, $color);
        }

        $this->SetY($startY + 2 * ($rowH + $gap) + 4);
    }

    // ── Top 5 Kunden ─────────────────────────────────────────
    private function _topKunden(array $topKunden): void
    {
        $this->sectionTitle('Top 5 Kunden nach Umsatz');

        $cols = [
            ['#',          10, 'C'],
            ['Kunde',      95, 'L'],
            ['Aufträge',   25, 'C'],
            ['Umsatz (netto)', 44, 'R'],
        ];
        $this->tableHeader($cols);

        foreach (array_slice($topKunden, 0, 5) as $i => $k) {
            $this->tableRow([
                [$i + 1,                                    10, 'C'],
                [$k['kunden_name'] ?? '—',                  95, 'L'],
                [(string)(int)($k['anzahl_auftraege'] ?? 0), 25, 'C'],
                [$this->formatMoney((float)($k['gesamt_umsatz'] ?? 0)), 44, 'R'],
            ], $i);
        }

        if (empty($topKunden)) {
            $this->SetFont('helvetica', 'I', 9);
            $this->SetTextColor(...self::COLOR_MUTED);
            $this->Cell(0, 8, '  Keine Daten für diesen Zeitraum.', 0, 1, 'L');
            $this->SetTextColor(...self::COLOR_TEXT);
        }
        $this->Ln(3);
    }

    // ── Mitarbeiter-Auslastung ────────────────────────────────
    private function _mitarbeiterAuslastung(array $mitarbeiter): void
    {
        $this->sectionTitle('Mitarbeiter-Auslastung');

        $cols = [
            ['Mitarbeiter',     65, 'L'],
            ['Rolle',           30, 'C'],
            ['Aufträge',        25, 'C'],
            ['Geplante Std.',   30, 'R'],
            ['Umsatz (netto)',  24, 'R'],
        ];
        $this->tableHeader($cols);

        foreach ($mitarbeiter as $i => $m) {
            $name = trim(($m['vorname'] ?? '') . ' ' . ($m['nachname'] ?? ''));
            $this->tableRow([
                [$name,                                          65, 'L'],
                [ucfirst($m['rolle'] ?? '—'),                   30, 'C'],
                [(string)(int)($m['anzahl_auftraege'] ?? 0),    25, 'C'],
                [number_format((float)($m['geplante_stunden'] ?? 0), 1, ',', '.') . ' h', 30, 'R'],
                [$this->formatMoney((float)($m['monats_umsatz'] ?? 0)), 24, 'R'],
            ], $i);
        }

        if (empty($mitarbeiter)) {
            $this->SetFont('helvetica', 'I', 9);
            $this->SetTextColor(...self::COLOR_MUTED);
            $this->Cell(0, 8, '  Keine Daten für diesen Zeitraum.', 0, 1, 'L');
            $this->SetTextColor(...self::COLOR_TEXT);
        }
        $this->Ln(3);
    }

    // ── Offene Aufträge ───────────────────────────────────────
    private function _offeneAuftraege(array $auftraege): void
    {
        $this->sectionTitle('Offene Aufträge');

        $anzahl = count($auftraege);
        $wert   = array_sum(array_column($auftraege, 'netto_summe'));

        // Zusammenfassung
        $this->SetFont('helvetica', '', 9);
        $this->SetTextColor(...self::COLOR_MUTED);
        $this->Cell(0, 6,
            "Insgesamt {$anzahl} offene Aufträge mit einem Gesamtwert von " . $this->formatMoney($wert),
            0, 1, 'L'
        );
        $this->SetTextColor(...self::COLOR_TEXT);
        $this->Ln(1);

        $cols = [
            ['Auftrag-Nr.',  22, 'L'],
            ['Titel',        55, 'L'],
            ['Status',       24, 'C'],
            ['Priorität',   22, 'C'],
            ['Erstellt am',  24, 'C'],
            ['Wert (netto)', 26, 'R'],
        ];
        $this->tableHeader($cols);

        foreach (array_slice($auftraege, 0, 22) as $i => $a) {
            $this->tableRow([
                [$a['auftrag_nr']  ?? '—',                    22, 'L'],
                [mb_substr($a['titel'] ?? '—', 0, 40),        55, 'L'],
                [ucfirst($a['status'] ?? '—'),                 24, 'C'],
                [ucfirst($a['prioritaet'] ?? '—'),             22, 'C'],
                [$this->formatDate($a['erstellt_am'] ?? ''),   24, 'C'],
                [$this->formatMoney((float)($a['netto_summe'] ?? 0)), 26, 'R'],
            ], $i);
        }

        if ($anzahl > 15) {
            $this->SetFont('helvetica', 'I', 8);
            $this->SetTextColor(...self::COLOR_MUTED);
            $this->Cell(0, 6, '  … und ' . ($anzahl - 22) . ' weitere Aufträge.', 0, 1);
            $this->SetTextColor(...self::COLOR_TEXT);
        }
        $this->Ln(3);
    }

    // ── Offene Rechnungen ─────────────────────────────────────
    private function _offeneRechnungen(array $rechnungen): void
    {
        $this->sectionTitle('Unbezahlte Rechnungen');

        $anzahl = count($rechnungen);
        $wert   = array_sum(array_map(fn($r) => (float)($r['brutto_betrag'] ?? 0), $rechnungen));

        $this->SetFont('helvetica', '', 9);
        $this->SetTextColor(...self::COLOR_MUTED);
        $this->Cell(0, 6,
            "Insgesamt {$anzahl} unbezahlte Rechnungen mit einem Gesamtwert von " . $this->formatMoney($wert),
            0, 1, 'L'
        );
        $this->SetTextColor(...self::COLOR_TEXT);
        $this->Ln(1);

        $cols = [
            ['Rechnungs-Nr.', 30, 'L'],
            ['Kunde',          60, 'L'],
            ['Fällig am',     28, 'C'],
            ['Verzug (Tage)', 26, 'C'],
            ['Betrag (brutto)', 30, 'R'],
        ];
        $this->tableHeader($cols);

        foreach (array_slice($rechnungen, 0, 12) as $i => $r) {
            $verzug = (int)($r['tage_verzug'] ?? 0);
            // Überfällige rot hervorheben
            if ($verzug > 0) {
                $this->SetTextColor(185, 28, 28);
            }
            $this->tableRow([
                [$r['rechnungs_nr']  ?? 'R-' . ($r['rechnung_id'] ?? ''), 30, 'L'],
                [mb_substr($r['kunden_name'] ?? '—', 0, 35),               60, 'L'],
                [$this->formatDate($r['faellig_am'] ?? ''),                 28, 'C'],
                [$verzug > 0 ? '+' . $verzug . ' Tage' : '—',              26, 'C'],
                [$this->formatMoney((float)($r['brutto_betrag'] ?? 0)),    30, 'R'],
            ], $i);
            $this->SetTextColor(...self::COLOR_TEXT);
        }

        if (empty($rechnungen)) {
            $this->SetFont('helvetica', 'I', 9);
            $this->SetTextColor(16, 185, 129);
            $this->Cell(0, 8, '  ✓ Alle Rechnungen wurden beglichen.', 0, 1, 'L');
            $this->SetTextColor(...self::COLOR_TEXT);
        }
        $this->Ln(3);
    }

    // ── Top 10 Materialverbrauch ──────────────────────────────
    private function _topMaterial(array $material): void
    {
        $this->sectionTitle('Materialverbrauch Top 10');

        $cols = [
            ['#',            10, 'C'],
            ['Material',     90, 'L'],
            ['Einheit',      22, 'C'],
            ['Verbrauch',    26, 'R'],
            ['Wert (netto)', 26, 'R'],
        ];
        $this->tableHeader($cols);

        foreach (array_slice($material, 0, 10) as $i => $m) {
            $this->tableRow([
                [$i + 1,                                               10, 'C'],
                [$m['name'] ?? '—',                                    90, 'L'],
                [$m['einheit'] ?? 'Stk.',                              22, 'C'],
                [number_format((float)($m['gesamt_verbrauch'] ?? 0), 2, ',', '.'), 26, 'R'],
                [$this->formatMoney((float)($m['gesamt_wert'] ?? 0)), 26, 'R'],
            ], $i);
        }

        if (empty($material)) {
            $this->SetFont('helvetica', 'I', 9);
            $this->SetTextColor(...self::COLOR_MUTED);
            $this->Cell(0, 8, '  Kein Materialverbrauch in diesem Zeitraum.', 0, 1, 'L');
            $this->SetTextColor(...self::COLOR_TEXT);
        }
        $this->Ln(3);
    }

    // ── Abschlussseite ────────────────────────────────────────
    private function _abschluss(int $jahr, int $monat): void
    {
        $this->Ln(6);
        $this->SetFont('helvetica', '', 8.5);
        $this->SetTextColor(...self::COLOR_MUTED);
        $this->Cell(0, 5,
            'Bericht erstellt am ' . date('d.m.Y H:i') . ' Uhr · HandwerkerPro v1.0 · ' . self::FIRMA['name'],
            0, 1, 'C'
        );
        $this->SetTextColor(...self::COLOR_TEXT);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────
    private function _monatsname(int $monat): string
    {
        return [
            1 => 'Januar',    2 => 'Februar',   3 => 'März',
            4 => 'April',     5 => 'Mai',        6 => 'Juni',
            7 => 'Juli',      8 => 'August',     9 => 'September',
            10 => 'Oktober', 11 => 'November',  12 => 'Dezember',
        ][$monat] ?? 'Monat ' . $monat;
    }
}
