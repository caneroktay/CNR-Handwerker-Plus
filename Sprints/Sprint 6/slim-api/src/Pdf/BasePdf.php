<?php
// ============================================================
//  HandwerkerPro — BasePdf
//  Basisklasse für alle PDFs: Header, Footer, Farben, Fonts
//  Erweitert TCPDF mit dem Firmen-Layout von Klaus Meier GmbH
// ============================================================
declare(strict_types=1);

namespace App\Pdf;

use TCPDF;

class BasePdf extends TCPDF
{
    // ── Firmenfarben ──────────────────────────────────────────
    protected const COLOR_PRIMARY   = [30,  64, 175];   // Dunkelblau
    protected const COLOR_SECONDARY = [71,  85, 105];   // Grau
    protected const COLOR_ACCENT    = [239, 246, 255];  // Hellblau (Hintergrund)
    protected const COLOR_TEXT      = [15,  23,  42];   // Fast-Schwarz
    protected const COLOR_MUTED     = [100, 116, 139];  // Grau-Muted
    protected const COLOR_WHITE     = [255, 255, 255];
    protected const COLOR_BORDER    = [203, 213, 225];  // Tabellenrahmen
    protected const COLOR_ROW_ALT   = [248, 250, 252];  // Wechselzeile

    // ── Firmendaten ───────────────────────────────────────────
    protected const FIRMA = [
        'name'       => 'Klaus Meier GmbH',
        'zusatz'     => 'Sanitär · Heizung · Klima',
        'strasse'    => 'Handwerkerstraße 42',
        'plz_ort'    => '45127 Essen',
        'telefon'    => '+49 201 123 456 0',
        'email'      => 'info@klausmeier-gmbh.de',
        'web'        => 'www.klausmeier-gmbh.de',
        'steuernr'   => 'Steuernr.: 112/5678/9012',
        'ust_id'     => 'USt-IdNr.: DE 123456789',
        'bank_name'  => 'Sparkasse Essen',
        'iban'       => 'DE89 3704 0044 0532 0130 00',
        'bic'        => 'COBADEFFXXX',
        'zahlungsfrist' => '14 Tage netto ohne Abzug',
    ];

    protected string $docType = 'Dokument';

    public function __construct(
        string $orientation = 'P',
        string $unit        = 'mm',
        string $format      = 'A4'
    ) {
        parent::__construct($orientation, $unit, $format, true, 'UTF-8', false);

        $this->SetCreator('HandwerkerPro v1.0');
        $this->SetAuthor(self::FIRMA['name']);
        $this->SetMargins(18, 48, 18);
        $this->SetHeaderMargin(8);
        $this->SetFooterMargin(16);
        $this->SetAutoPageBreak(true, 28);
        $this->setPrintHeader(true);
        $this->setPrintFooter(true);
        $this->SetFont('helvetica', '', 10);
    }

    // ── HEADER ────────────────────────────────────────────────
    public function Header(): void
    {
        $pageW = $this->getPageWidth();

        // Blauer Balken oben
        $this->SetFillColor(...self::COLOR_PRIMARY);
        $this->Rect(0, 0, $pageW, 14, 'F');

        // Firmenname im Balken
        $this->SetTextColor(...self::COLOR_WHITE);
        $this->SetFont('helvetica', 'B', 11);
        $this->SetXY(18, 3.5);
        $this->Cell(100, 7, self::FIRMA['name'], 0, 0, 'L');

        // Zusatz rechts im Balken
        $this->SetFont('helvetica', '', 8);
        $this->SetXY($pageW - 100, 3.5);
        $this->Cell(82, 7, self::FIRMA['zusatz'], 0, 0, 'R');

        // Trennlinie
        $this->SetDrawColor(...self::COLOR_BORDER);
        $this->SetLineWidth(0.3);
        $this->Line(18, 18, $pageW - 18, 18);

        // Dokument-Typ Badge oben rechts
        $this->SetFillColor(...self::COLOR_PRIMARY);
        $this->SetTextColor(...self::COLOR_WHITE);
        $this->SetFont('helvetica', 'B', 8);
        $this->SetXY($pageW - 50, 17);
        $this->Cell(32, 6, strtoupper($this->docType), 0, 0, 'R', false);

        // Seitenzahl
        $this->SetTextColor(...self::COLOR_MUTED);
        $this->SetFont('helvetica', '', 7.5);
        $this->SetXY(18, 17);
        $this->Cell(60, 6, 'Seite ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'L');

        // Farben zurücksetzen
        $this->SetTextColor(...self::COLOR_TEXT);
        $this->SetDrawColor(...self::COLOR_BORDER);
    }

    // ── FOOTER ────────────────────────────────────────────────
    public function Footer(): void
    {
        $pageW = $this->getPageWidth();
        $y     = $this->getPageHeight() - 22;

        // Trennlinie
        $this->SetDrawColor(...self::COLOR_BORDER);
        $this->SetLineWidth(0.3);
        $this->Line(18, $y, $pageW - 18, $y);

        // Footer-Hintergrund
        $this->SetFillColor(...self::COLOR_ACCENT);
        $this->Rect(18, $y + 1, $pageW - 36, 17, 'F');

        $this->SetTextColor(...self::COLOR_SECONDARY);
        $this->SetFont('helvetica', '', 7);

        // Spalte 1: Bankverbindung
        $this->SetXY(20, $y + 3);
        $this->SetFont('helvetica', 'B', 7);
        $this->Cell(55, 4, 'Bankverbindung', 0, 2);
        $this->SetFont('helvetica', '', 7);
        $this->Cell(55, 3.5, self::FIRMA['bank_name'], 0, 2);
        $this->Cell(55, 3.5, 'IBAN: ' . self::FIRMA['iban'], 0, 2);
        $this->Cell(55, 3.5, 'BIC: '  . self::FIRMA['bic'], 0, 0);

        // Spalte 2: Kontakt
        $this->SetXY(90, $y + 3);
        $this->SetFont('helvetica', 'B', 7);
        $this->Cell(55, 4, 'Kontakt', 0, 2);
        $this->SetFont('helvetica', '', 7);
        $this->SetX(90);
        $this->Cell(55, 3.5, 'Tel.: ' . self::FIRMA['telefon'], 0, 2);
        $this->SetX(90);
        $this->Cell(55, 3.5, self::FIRMA['email'], 0, 2);
        $this->SetX(90);
        $this->Cell(55, 3.5, self::FIRMA['web'], 0, 0);

        // Spalte 3: Steuerdaten
        $this->SetXY(160, $y + 3);
        $this->SetFont('helvetica', 'B', 7);
        $this->Cell(36, 4, 'Steuerdaten', 0, 2);
        $this->SetFont('helvetica', '', 7);
        $this->SetX(160);
        $this->Cell(36, 3.5, self::FIRMA['steuernr'], 0, 2);
        $this->SetX(160);
        $this->Cell(36, 3.5, self::FIRMA['ust_id'], 0, 2);
        $this->SetX(160);
        $this->Cell(36, 3.5, 'Zahlung: ' . self::FIRMA['zahlungsfrist'], 0, 0);

        $this->SetTextColor(...self::COLOR_TEXT);
    }

    // ── HILFSMETHODEN ─────────────────────────────────────────

    /** Abschnittsüberschrift mit blauer Hintergrundleiste */
    protected function sectionTitle(string $title): void
    {
        $this->Ln(4);
        $this->SetFillColor(...self::COLOR_PRIMARY);
        $this->SetTextColor(...self::COLOR_WHITE);
        $this->SetFont('helvetica', 'B', 9);
        $this->Cell(0, 7, '  ' . $title, 0, 1, 'L', true);
        $this->SetTextColor(...self::COLOR_TEXT);
        $this->Ln(2);
    }

    /** Tabellen-Header-Zeile */
    protected function tableHeader(array $cols): void
    {
        $this->SetFillColor(...self::COLOR_PRIMARY);
        $this->SetTextColor(...self::COLOR_WHITE);
        $this->SetFont('helvetica', 'B', 8.5);
        $this->SetLineWidth(0);

        foreach ($cols as [$label, $width, $align]) {
            $this->Cell($width, 7, $label, 0, 0, $align, true);
        }
        $this->Ln();
        $this->SetTextColor(...self::COLOR_TEXT);
        $this->SetLineWidth(0.2);
    }

    /** Tabellen-Datenzeile (alternierend) */
    protected function tableRow(array $cells, int $rowIndex = 0): void
    {
        $fill = ($rowIndex % 2 === 1);
        if ($fill) {
            $this->SetFillColor(...self::COLOR_ROW_ALT);
        }
        $this->SetFont('helvetica', '', 8.5);

        foreach ($cells as [$value, $width, $align]) {
            $this->Cell($width, 6.5, $value, 0, 0, $align, $fill);
        }
        $this->Ln();
    }

    /** KPI-Box: kleines Statistikfeld */
    protected function kpiBox(float $x, float $y, float $w, float $h, string $label, string $value, array $color = null): void
    {
        $color = $color ?? self::COLOR_PRIMARY;
        $this->SetFillColor(...self::COLOR_ACCENT);
        $this->SetDrawColor(...$color);
        $this->SetLineWidth(0.6);
        $this->RoundedRect($x, $y, $w, $h, 2, '1111', 'DF');

        // Farbbalken links
        $this->SetFillColor(...$color);
        $this->Rect($x, $y, 2.5, $h, 'F');

        // Label
        $this->SetTextColor(...self::COLOR_MUTED);
        $this->SetFont('helvetica', '', 7);
        $this->SetXY($x + 4, $y + 2);
        $this->Cell($w - 6, 4, $label, 0, 2);

        // Wert
        $this->SetTextColor(...$color);
        $this->SetFont('helvetica', 'B', 11);
        $this->SetX($x + 4);
        $this->Cell($w - 6, 6, $value, 0, 0);

        $this->SetTextColor(...self::COLOR_TEXT);
        $this->SetDrawColor(...self::COLOR_BORDER);
        $this->SetLineWidth(0.2);
    }

    /** Formatierung: Geldbetrag */
    protected function formatMoney(float $amount): string
    {
        return number_format($amount, 2, ',', '.') . ' €';
    }

    /** Formatierung: Datum */
    protected function formatDate(string $date): string
    {
        if (!$date) return '—';
        $d = \DateTime::createFromFormat('Y-m-d', substr($date, 0, 10));
        return $d ? $d->format('d.m.Y') : $date;
    }
}
