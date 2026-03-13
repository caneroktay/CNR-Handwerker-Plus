<?php
// ============================================================
//  HandwerkerPro — RechnungPdf
//  Professionelle Rechnung mit Logo, Positionen, MwSt, Summen
// ============================================================
declare(strict_types=1);

namespace App\Pdf;

class RechnungPdf extends BasePdf
{
    protected string $docType = 'Rechnung';

    public function generate(array $rechnung, array $positionen, array $kunde): string
    {
        $this->SetTitle('Rechnung ' . ($rechnung['rechnungs_nr'] ?? $rechnung['rechnung_id']));
        $this->AddPage();

        $this->_absenderzeile();
        $this->_anschrift($kunde);
        $this->_rechnungskopf($rechnung);
        $this->_positionstabelle($positionen);
        $this->_summenblock($positionen);
        $this->_zahlungshinweis($rechnung);

        return $this->Output('', 'S'); // String zurückgeben
    }

    // ── Absenderzeile (klein, über Anschrift) ─────────────────
    private function _absenderzeile(): void
    {
        $this->SetFont('helvetica', '', 6.5);
        $this->SetTextColor(...self::COLOR_MUTED);
        $this->SetXY(18, 28);
        $this->Cell(80, 4,
            self::FIRMA['name'] . ' · ' . self::FIRMA['strasse'] . ' · ' . self::FIRMA['plz_ort'],
            'B', 0, 'L'
        );
        $this->SetTextColor(...self::COLOR_TEXT);
    }

    // ── Anschrift (Empfänger) ─────────────────────────────────
    private function _anschrift(array $kunde): void
    {
        $this->SetFont('helvetica', '', 10);
        $this->SetXY(18, 33);
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(80, 5, $kunde['kunden_name'] ?? '', 0, 2);
        $this->SetFont('helvetica', '', 10);
        $this->SetX(18);

        if (!empty($kunde['ansprechpartner'])) {
            $this->Cell(80, 5, 'z.Hd. ' . $kunde['ansprechpartner'], 0, 2);
            $this->SetX(18);
        }
        if (!empty($kunde['strasse'])) {
            $this->Cell(80, 5, ($kunde['strasse'] ?? '') . ' ' . ($kunde['hausnummer'] ?? ''), 0, 2);
            $this->SetX(18);
            $this->Cell(80, 5, ($kunde['plz'] ?? '') . ' ' . ($kunde['ort'] ?? ''), 0, 2);
        }
    }

    // ── Rechnungskopf (Datum, Nr, Kundennr.) ─────────────────
    private function _rechnungskopf(array $r): void
    {
        $pageW = $this->getPageWidth();

        // Info-Box rechts oben
        $this->SetFillColor(...self::COLOR_ACCENT);
        $this->SetDrawColor(...self::COLOR_BORDER);
        $this->SetLineWidth(0.3);
        $this->RoundedRect($pageW - 80, 28, 62, 30, 2, '1111', 'DF');

        $infoRows = [
            ['Rechnungsnr.',  $r['rechnungs_nr']   ?? 'R-' . $r['rechnung_id']],
            ['Rechnungsdatum', $this->formatDate($r['rechnungs_datum'] ?? '')],
            ['Fällig bis',    $this->formatDate($r['faellig_am']       ?? '')],
            ['Kundennr.',     'K-' . str_pad((string)($r['kunden_id'] ?? 0), 4, '0', STR_PAD_LEFT)],
        ];

        $iy = 30;
        foreach ($infoRows as [$label, $value]) {
            $this->SetFont('helvetica', '', 7.5);
            $this->SetTextColor(...self::COLOR_MUTED);
            $this->SetXY($pageW - 79, $iy);
            $this->Cell(32, 4.5, $label . ':', 0, 0, 'L');
            $this->SetFont('helvetica', 'B', 7.5);
            $this->SetTextColor(...self::COLOR_TEXT);
            $this->Cell(28, 4.5, $value, 0, 0, 'R');
            $iy += 4.8;
        }

        // Haupttitel
        $this->SetXY(18, 62);
        $this->SetFont('helvetica', 'B', 16);
        $this->SetTextColor(...self::COLOR_PRIMARY);
        $this->Cell(0, 10, 'Rechnung', 0, 2, 'L');

        // Betreffzeile
        $this->SetFont('helvetica', '', 10);
        $this->SetTextColor(...self::COLOR_TEXT);
        $this->SetX(18);
        $auftragNr = $r['auftrag_nr'] ?? '';
        $this->Cell(0, 6,
            'für Auftrag ' . $auftragNr . ' · ' . ($r['auftrag_titel'] ?? ''),
            0, 2, 'L'
        );

        // Anredetext
        $this->SetX(18);
        $this->SetFont('helvetica', '', 10);
        $this->MultiCell(130, 5,
            'sehr geehrte Damen und Herren,' . "\n" .
            'wir erlauben uns, die erbrachten Leistungen wie folgt in Rechnung zu stellen:',
            0, 'L'
        );
        $this->Ln(4);
    }

    // ── Positionstabelle ──────────────────────────────────────
    private function _positionstabelle(array $positionen): void
    {
        $this->sectionTitle('Leistungsübersicht');

        $cols = [
            ['Pos.',         10, 'C'],
            ['Beschreibung', 70, 'L'],
            ['Typ',          20, 'C'],
            ['Menge',        18, 'R'],
            ['Einzelpreis',  28, 'R'],
            ['Gesamtpreis',  28, 'R'],
        ];
        $this->tableHeader($cols);

        $i = 0;
        foreach ($positionen as $pos) {
            $menge    = (float)($pos['menge']                        ?? 0);
            $preis    = (float)($pos['einzelpreis_bei_bestellung']   ?? $pos['einzelpreis_bei_rechnung'] ?? 0);
            $gesamt   = $menge * $preis;
            $typ      = $pos['typ'] ?? 'Leistung';
            $einheit  = match($typ) {
                'arbeitszeit' => 'Std.',
                'material'    => 'Stk.',
                default       => 'Pos.',
            };

            $this->tableRow([
                [$i + 1,                              10, 'C'],
                [$pos['bezeichnung'] ?? '—',          70, 'L'],
                [ucfirst($typ),                       20, 'C'],
                [number_format($menge, 2, ',', '.') . ' ' . $einheit, 18, 'R'],
                [$this->formatMoney($preis),          28, 'R'],
                [$this->formatMoney($gesamt),         28, 'R'],
            ], $i);
            $i++;
        }

        // Abschluss-Linie
        $this->SetDrawColor(...self::COLOR_PRIMARY);
        $this->SetLineWidth(0.5);
        $this->Line(18, $this->GetY(), $this->getPageWidth() - 18, $this->GetY());
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(...self::COLOR_BORDER);
        $this->Ln(3);
    }

    // ── Summenblock (Netto, MwSt, Brutto) ────────────────────
    private function _summenblock(array $positionen): void
    {
        // Netto nach Typ aufteilen
        $summeArbeit    = 0.0;
        $summeMaterial  = 0.0;
        $summeSonstige  = 0.0;

        foreach ($positionen as $pos) {
            $betrag = (float)($pos['menge'] ?? 0)
                    * (float)($pos['einzelpreis_bei_bestellung'] ?? $pos['einzelpreis_bei_rechnung'] ?? 0);
            $typ    = $pos['typ'] ?? '';
            if ($typ === 'arbeitszeit')   $summeArbeit   += $betrag;
            elseif ($typ === 'material')  $summeMaterial += $betrag;
            else                          $summeSonstige += $betrag;
        }

        $mwstSatz = 0.19;
        $netto    = $summeArbeit + $summeMaterial + $summeSonstige;
        $mwst     = $netto * $mwstSatz;
        $brutto   = $netto + $mwst;

        $pageW   = $this->getPageWidth();
        $colLeft = $pageW - 100;

        // Zwischenzeilen
        $rows = [];
        if ($summeArbeit   > 0) $rows[] = ['Arbeitszeit-Kosten',  $summeArbeit,  false];
        if ($summeMaterial > 0) $rows[] = ['Material-Kosten',     $summeMaterial, false];
        if ($summeSonstige > 0) $rows[] = ['Sonstige Leistungen', $summeSonstige, false];
        if (count($rows) > 1) {
            $rows[] = ['Zwischensumme (netto)', $netto, 'between'];
        }
        $rows[] = ['Mehrwertsteuer 19 %', $mwst,   'tax'];
        $rows[] = ['Gesamtbetrag (brutto)', $brutto, 'total'];

        foreach ($rows as [$label, $amount, $style]) {
            $this->SetXY($colLeft, $this->GetY());

            if ($style === 'total') {
                // Gesamtbetrag-Box
                $this->SetFillColor(...self::COLOR_PRIMARY);
                $this->SetTextColor(...self::COLOR_WHITE);
                $this->SetFont('helvetica', 'B', 10);
                $this->Cell(55, 8, $label,                   0, 0, 'L', true);
                $this->Cell(27, 8, $this->formatMoney($amount), 0, 1, 'R', true);
                $this->SetTextColor(...self::COLOR_TEXT);
            } elseif ($style === 'between') {
                $this->SetFont('helvetica', 'B', 9);
                $this->SetDrawColor(...self::COLOR_BORDER);
                $this->SetLineWidth(0.3);
                $this->Cell(55, 6.5, $label,                     'T', 0, 'L');
                $this->Cell(27, 6.5, $this->formatMoney($amount), 'T', 1, 'R');
            } elseif ($style === 'tax') {
                $this->SetFont('helvetica', 'I', 9);
                $this->SetTextColor(...self::COLOR_MUTED);
                $this->Cell(55, 6, $label,                     0, 0, 'L');
                $this->Cell(27, 6, $this->formatMoney($amount), 0, 1, 'R');
                $this->SetTextColor(...self::COLOR_TEXT);
            } else {
                $this->SetFont('helvetica', '', 9);
                $this->Cell(55, 6, $label,                     0, 0, 'L');
                $this->Cell(27, 6, $this->formatMoney($amount), 0, 1, 'R');
            }
        }
        $this->Ln(6);
    }

    // ── Zahlungshinweis ───────────────────────────────────────
    private function _zahlungshinweis(array $r): void
    {
        $faelligAm = $this->formatDate($r['faellig_am'] ?? '');

        $this->SetFillColor(...self::COLOR_ACCENT);
        $this->SetDrawColor(...self::COLOR_BORDER);
        $this->SetLineWidth(0.3);
        $this->RoundedRect(18, $this->GetY(), $this->getPageWidth() - 36, 20, 2, '1111', 'DF');

        $this->SetXY(22, $this->GetY() + 2);
        $this->SetFont('helvetica', 'B', 9);
        $this->SetTextColor(...self::COLOR_PRIMARY);
        $this->Cell(0, 5, 'Zahlungshinweis', 0, 2);
        $this->SetFont('helvetica', '', 9);
        $this->SetTextColor(...self::COLOR_TEXT);
        $this->SetX(22);
        $this->MultiCell(0, 4.5,
            'Bitte überweisen Sie den Gesamtbetrag bis zum ' . $faelligAm . ' unter Angabe der Rechnungsnummer auf unser Konto.' . "\n" .
            'IBAN: ' . self::FIRMA['iban'] . '  ·  BIC: ' . self::FIRMA['bic'] . '  ·  ' . self::FIRMA['bank_name'],
            0, 'L'
        );

        $this->Ln(8);
        $this->SetFont('helvetica', '', 10);
        $this->SetX(18);
        $this->MultiCell(0, 5,
            'Vielen Dank für Ihr Vertrauen in unsere Arbeit.' . "\n" .
            'Bei Fragen stehen wir Ihnen jederzeit gerne zur Verfügung.' . "\n\n" .
            'Mit freundlichen Grüßen,' . "\n" .
            self::FIRMA['name'],
            0, 'L'
        );
    }
}
