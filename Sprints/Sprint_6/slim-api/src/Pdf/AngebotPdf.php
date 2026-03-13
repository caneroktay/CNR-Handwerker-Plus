<?php
// ============================================================
//  HandwerkerPro — AngebotPdf
//  Professionelles Angebot mit Positionen, Gültigkeitsdatum
// ============================================================
declare(strict_types=1);

namespace App\Pdf;

class AngebotPdf extends BasePdf
{
    protected string $docType = 'Angebot';

    public function generate(array $angebot, array $positionen, array $kunde): string
    {
        $this->SetTitle('Angebot ' . ($angebot['angebot_nr'] ?? $angebot['angebot_id']));
        $this->AddPage();

        $this->_absenderzeile();
        $this->_anschrift($kunde);
        $this->_angebotskopf($angebot);
        $this->_positionstabelle($positionen);
        $this->_summenblock($positionen);
        $this->_angebotsabschluss($angebot);

        return $this->Output('', 'S');
    }

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

    private function _anschrift(array $kunde): void
    {
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

    private function _angebotskopf(array $a): void
    {
        $pageW = $this->getPageWidth();

        // Info-Box rechts
        $this->SetFillColor(...self::COLOR_ACCENT);
        $this->SetDrawColor(...self::COLOR_BORDER);
        $this->SetLineWidth(0.3);
        $this->RoundedRect($pageW - 80, 28, 62, 25, 2, '1111', 'DF');

        $infoRows = [
            ['Angebotsnr.',     $a['angebot_nr']    ?? 'ANG-' . $a['angebot_id']],
            ['Datum',           $this->formatDate(date('Y-m-d'))],
            ['Gültig bis',      $this->formatDate($a['gueltig_bis'] ?? date('Y-m-d', strtotime('+30 days')))],
            ['Kundennr.',       'K-' . str_pad((string)($a['kunden_id'] ?? 0), 4, '0', STR_PAD_LEFT)],
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
            $iy += 4.6;
        }

        // Haupttitel
        $this->SetXY(18, 62);
        $this->SetFont('helvetica', 'B', 16);
        $this->SetTextColor(...self::COLOR_PRIMARY);
        $this->Cell(0, 10, 'Angebot', 0, 2, 'L');

        $this->SetFont('helvetica', '', 10);
        $this->SetTextColor(...self::COLOR_TEXT);
        $this->SetX(18);
        $this->Cell(0, 6, $a['betreff'] ?? 'Kostenvoranschlag für Ihre Anfrage', 0, 2, 'L');
        $this->SetX(18);
        $this->MultiCell(130, 5,
            'sehr geehrte Damen und Herren,' . "\n" .
            'vielen Dank für Ihre Anfrage. Gerne unterbreiten wir Ihnen folgendes Angebot:',
            0, 'L'
        );
        $this->Ln(4);
    }

    private function _positionstabelle(array $positionen): void
    {
        $this->sectionTitle('Angebotspositionen');

        $cols = [
            ['Pos.',         10, 'C'],
            ['Beschreibung', 72, 'L'],
            ['Typ',          20, 'C'],
            ['Menge',        18, 'R'],
            ['Einzelpreis',  28, 'R'],
            ['Gesamtpreis',  26, 'R'],
        ];
        $this->tableHeader($cols);

        $i = 0;
        foreach ($positionen as $pos) {
            $menge  = (float)($pos['menge']  ?? 0);
            $preis  = (float)($pos['einzelpreis_bei_bestellung'] ?? 0);
            $gesamt = $menge * $preis;
            $typ    = $pos['typ'] ?? 'Leistung';
            $einheit = match($typ) {
                'arbeitszeit' => 'Std.',
                'material'    => 'Stk.',
                default       => 'Pos.',
            };

            $this->tableRow([
                [$i + 1,                              10, 'C'],
                [$pos['bezeichnung'] ?? '—',          72, 'L'],
                [ucfirst($typ),                       20, 'C'],
                [number_format($menge, 2, ',', '.') . ' ' . $einheit, 18, 'R'],
                [$this->formatMoney($preis),          28, 'R'],
                [$this->formatMoney($gesamt),         26, 'R'],
            ], $i);
            $i++;
        }

        $this->SetDrawColor(...self::COLOR_PRIMARY);
        $this->SetLineWidth(0.5);
        $this->Line(18, $this->GetY(), $this->getPageWidth() - 18, $this->GetY());
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(...self::COLOR_BORDER);
        $this->Ln(3);
    }

    private function _summenblock(array $positionen): void
    {
        $netto  = array_sum(array_map(
            fn($p) => (float)($p['menge'] ?? 0) * (float)($p['einzelpreis_bei_bestellung'] ?? 0),
            $positionen
        ));
        $mwst   = $netto * 0.19;
        $brutto = $netto + $mwst;

        $pageW   = $this->getPageWidth();
        $colLeft = $pageW - 96;

        $rows = [
            ['Nettobetrag',           $netto,  false],
            ['Mehrwertsteuer 19 %',   $mwst,   'tax'],
            ['Angebotssumme (brutto)', $brutto, 'total'],
        ];

        foreach ($rows as [$label, $amount, $style]) {
            $this->SetXY($colLeft, $this->GetY());
            if ($style === 'total') {
                $this->SetFillColor(...self::COLOR_PRIMARY);
                $this->SetTextColor(...self::COLOR_WHITE);
                $this->SetFont('helvetica', 'B', 10);
                $this->Cell(55, 8, $label,                    0, 0, 'L', true);
                $this->Cell(23, 8, $this->formatMoney($amount), 0, 1, 'R', true);
                $this->SetTextColor(...self::COLOR_TEXT);
            } elseif ($style === 'tax') {
                $this->SetFont('helvetica', 'I', 9);
                $this->SetTextColor(...self::COLOR_MUTED);
                $this->Cell(55, 6, $label,                    0, 0, 'L');
                $this->Cell(23, 6, $this->formatMoney($amount), 0, 1, 'R');
                $this->SetTextColor(...self::COLOR_TEXT);
            } else {
                $this->SetFont('helvetica', '', 9);
                $this->Cell(55, 6, $label,                    0, 0, 'L');
                $this->Cell(23, 6, $this->formatMoney($amount), 0, 1, 'R');
            }
        }
        $this->Ln(6);
    }

    private function _angebotsabschluss(array $a): void
    {
        $gueltigBis = $this->formatDate(
            $a['gueltig_bis'] ?? date('Y-m-d', strtotime('+30 days'))
        );

        // Hinweisbox
        $this->SetFillColor(...self::COLOR_ACCENT);
        $this->SetDrawColor(...self::COLOR_BORDER);
        $this->SetLineWidth(0.3);
        $this->RoundedRect(18, $this->GetY(), $this->getPageWidth() - 36, 16, 2, '1111', 'DF');
        $this->SetXY(22, $this->GetY() + 2);
        $this->SetFont('helvetica', 'B', 9);
        $this->SetTextColor(...self::COLOR_PRIMARY);
        $this->Cell(0, 5, 'Angebotsgültigkeit & Hinweise', 0, 2);
        $this->SetFont('helvetica', '', 9);
        $this->SetTextColor(...self::COLOR_TEXT);
        $this->SetX(22);
        $this->MultiCell(0, 4.5,
            'Dieses Angebot ist gültig bis ' . $gueltigBis . '. Alle Preise verstehen sich inkl. Anfahrt.' . "\n" .
            'Änderungen und Ergänzungen bedürfen der Schriftform. Es gelten unsere Allgemeinen Geschäftsbedingungen.',
            0, 'L'
        );

        $this->Ln(8);
        $this->SetX(18);
        $this->SetFont('helvetica', '', 10);
        $this->MultiCell(0, 5,
            'Wir würden uns freuen, diesen Auftrag für Sie ausführen zu dürfen.' . "\n" .
            'Für Rückfragen stehen wir Ihnen unter ' . self::FIRMA['telefon'] . ' gerne zur Verfügung.' . "\n\n" .
            'Mit freundlichen Grüßen,' . "\n" .
            self::FIRMA['name'],
            0, 'L'
        );
    }
}
