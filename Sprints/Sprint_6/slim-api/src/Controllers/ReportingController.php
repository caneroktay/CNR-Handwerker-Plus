<?php
// ============================================================
//  HandwerkerPro — ReportingController
//  Statistiken & Kennzahlen für das Dashboard
//
//  GET /api/reporting/kennzahlen
//  GET /api/reporting/umsatz
//  GET /api/reporting/mitarbeiter/auslastung
//  GET /api/reporting/top-kunden
// ============================================================
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ReportingController extends BaseController
{
    // ══════════════════════════════════════════════════════
    //  GET /api/reporting/kennzahlen
    //  Allgemeine KPI-Übersicht (aktueller Monat + Gesamt)
    // ══════════════════════════════════════════════════════
    public function kennzahlen(
        ServerRequestInterface $request,
        ResponseInterface      $response
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $jahr   = (int)($params['jahr']  ?? date('Y'));
        $monat  = (int)($params['monat'] ?? date('n'));

        $von = sprintf('%04d-%02d-01', $jahr, $monat);
        $bis = date('Y-m-t', strtotime($von));

        // ── Monatswerte ──────────────────────────────────
        $monatKpi = $this->db->fetchOne(
            "SELECT
                COALESCE((
                    SELECT SUM(ap.menge * ap.einzelpreis_bei_bestellung)
                    FROM auftrag a2
                    JOIN auftrag_position ap ON a2.auftrag_id = ap.auftrag_id
                    WHERE a2.status IN ('abgeschlossen','abgerechnet')
                      AND DATE(a2.erstellt_am) BETWEEN ? AND ?
                ), 0) AS monat_umsatz,
                (SELECT COUNT(*) FROM auftrag
                 WHERE status IN ('abgeschlossen','abgerechnet')
                   AND DATE(erstellt_am) BETWEEN ? AND ?) AS monat_auftraege_abgeschlossen,
                (SELECT COUNT(*) FROM auftrag
                 WHERE status NOT IN ('abgeschlossen','abgerechnet','storniert')) AS offene_auftraege,
                (SELECT COUNT(*) FROM rechnung WHERE status != 'bezahlt') AS offene_rechnungen,
                (SELECT COALESCE(SUM(rp.menge * rp.einzelpreis_bei_rechnung * (1 + rp.mwst_satz/100)), 0)
                 FROM rechnung r
                 JOIN rechnung_position rp ON r.rechnung_id = rp.rechnung_id
                 WHERE r.status != 'bezahlt') AS offener_betrag,
                (SELECT COUNT(*) FROM kunden
                 WHERE DATE(erstellt_am) BETWEEN ? AND ?) AS neue_kunden",
            [$von, $bis, $von, $bis, $von, $bis]
        );

        // ── Jahreswerte ───────────────────────────────────
        $jahresKpi = $this->db->fetchOne(
            "SELECT
                COALESCE((
                    SELECT SUM(ap.menge * ap.einzelpreis_bei_bestellung)
                    FROM auftrag a2
                    JOIN auftrag_position ap ON a2.auftrag_id = ap.auftrag_id
                    WHERE a2.status IN ('abgeschlossen','abgerechnet')
                      AND YEAR(a2.erstellt_am) = ?
                ), 0) AS jahres_umsatz,
                (SELECT COUNT(*) FROM auftrag
                 WHERE status IN ('abgeschlossen','abgerechnet')
                   AND YEAR(erstellt_am) = ?) AS jahres_auftraege,
                (SELECT COUNT(*) FROM kunden
                 WHERE YEAR(erstellt_am) = ?) AS jahres_neue_kunden",
            [$jahr, $jahr, $jahr]
        );

        // ── Vormonat Vergleich ────────────────────────────
        $vormonat     = $monat === 1 ? 12 : $monat - 1;
        $vormonatJahr = $monat === 1 ? $jahr - 1 : $jahr;
        $vmVon = sprintf('%04d-%02d-01', $vormonatJahr, $vormonat);
        $vmBis = date('Y-m-t', strtotime($vmVon));

        $vormonatUmsatz = $this->db->fetchOne(
            "SELECT COALESCE(SUM(ap.menge * ap.einzelpreis_bei_bestellung), 0) AS umsatz
             FROM auftrag a
             JOIN auftrag_position ap ON a.auftrag_id = ap.auftrag_id
             WHERE a.status IN ('abgeschlossen','abgerechnet')
               AND DATE(a.erstellt_am) BETWEEN ? AND ?",
            [$vmVon, $vmBis]
        );

        $vmUmsatz      = (float)($vormonatUmsatz['umsatz'] ?? 0);
        $aktUmsatz     = (float)($monatKpi['monat_umsatz'] ?? 0);
        $umsatzTrend   = $vmUmsatz > 0
            ? round((($aktUmsatz - $vmUmsatz) / $vmUmsatz) * 100, 1)
            : null;

        return $this->ok($response, [
            'zeitraum' => [
                'jahr'  => $jahr,
                'monat' => $monat,
                'von'   => $von,
                'bis'   => $bis,
            ],
            'monat' => [
                'umsatz_netto'           => round($aktUmsatz, 2),
                'auftraege_abgeschlossen'=> (int)($monatKpi['monat_auftraege_abgeschlossen'] ?? 0),
                'neue_kunden'            => (int)($monatKpi['neue_kunden'] ?? 0),
                'umsatz_trend_prozent'   => $umsatzTrend,
            ],
            'gesamt_offen' => [
                'auftraege'    => (int)($monatKpi['offene_auftraege'] ?? 0),
                'rechnungen'   => (int)($monatKpi['offene_rechnungen'] ?? 0),
                'betrag_brutto'=> round((float)($monatKpi['offener_betrag'] ?? 0), 2),
            ],
            'jahr' => [
                'umsatz_netto'=> round((float)($jahresKpi['jahres_umsatz'] ?? 0), 2),
                'auftraege'   => (int)($jahresKpi['jahres_auftraege'] ?? 0),
                'neue_kunden' => (int)($jahresKpi['jahres_neue_kunden'] ?? 0),
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════
    //  GET /api/reporting/umsatz
    //  Umsatz pro Monat (ganzes Jahr) oder pro Tag (1 Monat)
    //  ?jahr=2026
    //  ?jahr=2026&monat=3  → Tageswerte für März 2026
    // ══════════════════════════════════════════════════════
    public function umsatz(
        ServerRequestInterface $request,
        ResponseInterface      $response
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $jahr   = (int)($params['jahr']  ?? date('Y'));
        $monat  = isset($params['monat']) ? (int)$params['monat'] : null;

        if ($monat !== null) {
            // ── Tageswerte für einen Monat ────────────────
            $von = sprintf('%04d-%02d-01', $jahr, $monat);
            $bis = date('Y-m-t', strtotime($von));
            $tage = (int)date('t', strtotime($von));

            $rows = $this->db->fetchAll(
                "SELECT
                    DAY(a.erstellt_am) AS tag,
                    COALESCE(SUM(ap.menge * ap.einzelpreis_bei_bestellung), 0) AS umsatz_netto,
                    COUNT(DISTINCT a.auftrag_id) AS anzahl_auftraege
                 FROM auftrag a
                 JOIN auftrag_position ap ON a.auftrag_id = ap.auftrag_id
                 WHERE a.status IN ('abgeschlossen','abgerechnet')
                   AND DATE(a.erstellt_am) BETWEEN ? AND ?
                 GROUP BY DAY(a.erstellt_am)
                 ORDER BY tag",
                [$von, $bis]
            );

            // Lücken mit 0 füllen
            $indexed = [];
            foreach ($rows as $r) {
                $indexed[(int)$r['tag']] = $r;
            }
            $data = [];
            for ($t = 1; $t <= $tage; $t++) {
                $data[] = [
                    'label'           => sprintf('%02d.%02d.', $t, $monat),
                    'umsatz_netto'    => round((float)($indexed[$t]['umsatz_netto'] ?? 0), 2),
                    'anzahl_auftraege'=> (int)($indexed[$t]['anzahl_auftraege'] ?? 0),
                ];
            }

            return $this->ok($response, [
                'modus'     => 'taeglich',
                'jahr'      => $jahr,
                'monat'     => $monat,
                'gesamt'    => round(array_sum(array_column($data, 'umsatz_netto')), 2),
                'datenpunkte' => $data,
            ]);
        }

        // ── Monatswerte für ein ganzes Jahr ───────────────
        $rows = $this->db->fetchAll(
            "SELECT
                MONTH(a.erstellt_am) AS monat,
                COALESCE(SUM(ap.menge * ap.einzelpreis_bei_bestellung), 0) AS umsatz_netto,
                COUNT(DISTINCT a.auftrag_id) AS anzahl_auftraege
             FROM auftrag a
             JOIN auftrag_position ap ON a.auftrag_id = ap.auftrag_id
             WHERE a.status IN ('abgeschlossen','abgerechnet')
               AND YEAR(a.erstellt_am) = ?
             GROUP BY MONTH(a.erstellt_am)
             ORDER BY monat",
            [$jahr]
        );

        $monate = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
        $indexed = [];
        foreach ($rows as $r) {
            $indexed[(int)$r['monat']] = $r;
        }

        $data = [];
        for ($m = 1; $m <= 12; $m++) {
            $data[] = [
                'label'           => $monate[$m - 1],
                'monat_nr'        => $m,
                'umsatz_netto'    => round((float)($indexed[$m]['umsatz_netto'] ?? 0), 2),
                'anzahl_auftraege'=> (int)($indexed[$m]['anzahl_auftraege'] ?? 0),
            ];
        }

        // Vorjahr zum Vergleich
        $vorjahrRows = $this->db->fetchAll(
            "SELECT MONTH(a.erstellt_am) AS monat,
                    COALESCE(SUM(ap.menge * ap.einzelpreis_bei_bestellung), 0) AS umsatz_netto
             FROM auftrag a
             JOIN auftrag_position ap ON a.auftrag_id = ap.auftrag_id
             WHERE a.status IN ('abgeschlossen','abgerechnet')
               AND YEAR(a.erstellt_am) = ?
             GROUP BY MONTH(a.erstellt_am)",
            [$jahr - 1]
        );
        $vjIndexed = [];
        foreach ($vorjahrRows as $r) {
            $vjIndexed[(int)$r['monat']] = round((float)$r['umsatz_netto'], 2);
        }
        $vorjahrData = [];
        for ($m = 1; $m <= 12; $m++) {
            $vorjahrData[] = $vjIndexed[$m] ?? 0;
        }

        return $this->ok($response, [
            'modus'        => 'monatlich',
            'jahr'         => $jahr,
            'gesamt'       => round(array_sum(array_column($data, 'umsatz_netto')), 2),
            'datenpunkte'  => $data,
            'vorjahr'      => [
                'jahr'   => $jahr - 1,
                'werte'  => $vorjahrData,
                'gesamt' => round(array_sum($vorjahrData), 2),
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════
    //  GET /api/reporting/mitarbeiter/auslastung
    //  ?jahr=2026
    // ══════════════════════════════════════════════════════
    public function mitarbeiterAuslastung(
        ServerRequestInterface $request,
        ResponseInterface      $response
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $jahr   = (int)($params['jahr'] ?? date('Y'));

        // Monatliche Auslastung pro Mitarbeiter (Stunden)
        $rows = $this->db->fetchAll(
            "SELECT
                m.mitarbeiter_id,
                CONCAT(m.vorname, ' ', m.nachname) AS name,
                m.rolle,
                MONTH(t.start_datetime) AS monat,
                COUNT(DISTINCT t.auftrag_id) AS auftraege,
                ROUND(SUM(TIMESTAMPDIFF(MINUTE, t.start_datetime, t.end_datetime)) / 60, 1) AS stunden
             FROM mitarbeiter m
             JOIN termin t ON m.mitarbeiter_id = t.mitarbeiter_id
             WHERE YEAR(t.start_datetime) = ?
               AND m.aktiv = 1
             GROUP BY m.mitarbeiter_id, MONTH(t.start_datetime)
             ORDER BY m.nachname, monat",
            [$jahr]
        );

        // Strukturieren: pro Mitarbeiter, 12 Monate
        $monate = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
        $byMa = [];
        foreach ($rows as $r) {
            $id = $r['mitarbeiter_id'];
            if (!isset($byMa[$id])) {
                $byMa[$id] = [
                    'name'    => $r['name'],
                    'rolle'   => $r['rolle'],
                    'stunden' => array_fill(0, 12, 0),
                    'auftraege' => array_fill(0, 12, 0),
                ];
            }
            $mi = (int)$r['monat'] - 1;
            $byMa[$id]['stunden'][$mi]   = (float)$r['stunden'];
            $byMa[$id]['auftraege'][$mi] = (int)$r['auftraege'];
        }

        // Gesamtstunden pro Mitarbeiter
        foreach ($byMa as &$ma) {
            $ma['gesamt_stunden']   = round(array_sum($ma['stunden']), 1);
            $ma['gesamt_auftraege'] = array_sum($ma['auftraege']);
        }
        unset($ma);

        // Sortierung: meiste Stunden zuerst
        usort($byMa, fn($a, $b) => $b['gesamt_stunden'] <=> $a['gesamt_stunden']);

        return $this->ok($response, [
            'jahr'       => $jahr,
            'monate'     => $monate,
            'mitarbeiter'=> array_values($byMa),
        ]);
    }

    // ══════════════════════════════════════════════════════
    //  GET /api/reporting/top-kunden
    //  ?limit=10&jahr=2026
    // ══════════════════════════════════════════════════════
    public function topKunden(
        ServerRequestInterface $request,
        ResponseInterface      $response
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $limit  = min((int)($params['limit'] ?? 10), 50);
        $jahr   = (int)($params['jahr'] ?? date('Y'));

        $rows = $this->db->fetchAll(
            "SELECT
                CASE WHEN k.typ = 'privat'
                     THEN CONCAT(kp.vorname, ' ', kp.nachname)
                     ELSE kf.firmenname END AS name,
                k.typ,
                COUNT(DISTINCT a.auftrag_id) AS auftraege,
                COALESCE(SUM(ap.menge * ap.einzelpreis_bei_bestellung), 0) AS umsatz_netto,
                ROUND(COALESCE(SUM(ap.menge * ap.einzelpreis_bei_bestellung), 0) * 1.19, 2) AS umsatz_brutto,
                MIN(DATE_FORMAT(a.erstellt_am, '%d.%m.%Y')) AS erster_auftrag,
                MAX(DATE_FORMAT(a.erstellt_am, '%d.%m.%Y')) AS letzter_auftrag
             FROM kunden k
             JOIN auftrag a ON k.kunden_id = a.kunden_id
             JOIN auftrag_position ap ON a.auftrag_id = ap.auftrag_id
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             WHERE a.status IN ('abgeschlossen','abgerechnet')
               AND YEAR(a.erstellt_am) = ?
             GROUP BY k.kunden_id
             ORDER BY umsatz_netto DESC
             LIMIT ?",
            [$jahr, $limit]
        );

        // Gesamtumsatz für Prozentberechnung
        $gesamt = array_sum(array_column($rows, 'umsatz_netto'));

        foreach ($rows as &$row) {
            $row['umsatz_netto']   = round((float)$row['umsatz_netto'], 2);
            $row['umsatz_brutto']  = round((float)$row['umsatz_brutto'], 2);
            $row['anteil_prozent'] = $gesamt > 0
                ? round(((float)$row['umsatz_netto'] / $gesamt) * 100, 1)
                : 0;
        }
        unset($row);

        return $this->ok($response, [
            'jahr'          => $jahr,
            'limit'         => $limit,
            'gesamt_umsatz' => round($gesamt, 2),
            'kunden'        => $rows,
        ]);
    }
}
