<?php
// ============================================================
//  Dashboard Controller
// ============================================================
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class DashboardController extends BaseController
{
    /**
     * GET /api/dashboard
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->getAuthUser($request);

        $auftraege = $this->db->fetchOne("
            SELECT
                COUNT(*) AS gesamt,
                SUM(status='neu')           AS neu,
                SUM(status='geplant')       AS geplant,
                SUM(status='aktiv')         AS aktiv,
                SUM(status='abgeschlossen') AS abgeschlossen,
                SUM(status='abgerechnet')   AS abgerechnet,
                SUM(status='storniert')     AS storniert
            FROM auftrag
        ");

        $rechnungen = $this->db->fetchOne("
            SELECT
                COUNT(*) AS gesamt,
                SUM(r.status='überfällig') AS ueberfaellig,
                SUM(r.status='gesendet')   AS gesendet,
                SUM(r.status='bezahlt')    AS bezahlt,
                COALESCE(SUM(
                    CASE WHEN r.status IN ('gesendet','überfällig')
                    THEN rp.menge * rp.einzelpreis_bei_rechnung * (1 + rp.mwst_satz/100)
                    ELSE 0 END
                ), 0) AS offener_betrag_brutto
            FROM rechnung r
            LEFT JOIN rechnung_position rp ON r.rechnung_id = rp.rechnung_id
        ");

        $termine = $this->db->fetchAll("
            SELECT t.termin_id, t.start_datetime, t.end_datetime, t.status,
                   CONCAT(m.vorname, ' ', m.nachname) AS mitarbeiter,
                   a.titel AS auftrag_titel, a.prioritaet, a.auftrag_nr
            FROM termin t
            JOIN mitarbeiter m ON t.mitarbeiter_id = m.mitarbeiter_id
            JOIN auftrag a ON t.auftrag_id = a.auftrag_id
            WHERE DATE(t.start_datetime) = CURDATE()
            ORDER BY t.start_datetime
        ");

        $notfall = $this->db->fetchAll("
            SELECT a.auftrag_id, a.auftrag_nr, a.titel, a.status, a.prioritaet, a.erstellt_am,
                   CASE WHEN k.typ='privat'
                        THEN CONCAT(kp.vorname,' ',kp.nachname)
                        ELSE kf.firmenname END AS kunden_name
            FROM auftrag a
            JOIN kunden k ON a.kunden_id = k.kunden_id
            LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
            LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
            WHERE a.prioritaet IN ('notfall','dringend')
              AND a.status NOT IN ('abgeschlossen','abgerechnet','storniert')
            ORDER BY FIELD(a.prioritaet,'notfall','dringend'), a.erstellt_am DESC
            LIMIT 5
        ");

        $avgBearbzeit = $this->db->fetchColumn(
            'SELECT avg_tage_bis_abschluss FROM view_durchschnittliche_bearbeitungszeit'
        );
        $kundenGesamt = (int) $this->db->fetchColumn('SELECT COUNT(*) FROM kunden');

        return $this->ok($response, [
            'timestamp'              => date('c'),
            'mitarbeiter'            => ['mitarbeiter_id' => $user['mitarbeiter_id'], 'rolle' => $user['rolle']],
            'kpis' => [
                'kunden_gesamt'             => $kundenGesamt,
                'offene_auftraege'          => (int)(($auftraege['neu'] ?? 0) + ($auftraege['geplant'] ?? 0) + ($auftraege['aktiv'] ?? 0)),
                'ueberfaellige_rechnungen'  => (int)($rechnungen['ueberfaellig'] ?? 0),
                'offener_betrag_brutto'     => round((float)($rechnungen['offener_betrag_brutto'] ?? 0), 2),
                'kritische_lagerbestaende'  => count($this->db->fetchAll('SELECT * FROM view_material_nachbestellen')),
                'termine_heute'             => count($termine),
                'avg_bearbeitungszeit_tage' => $avgBearbzeit !== false ? round((float)$avgBearbzeit, 1) : null,
            ],
            'auftraege_statistik'      => $auftraege,
            'rechnungen_statistik'     => $rechnungen,
            'termine_heute'            => $termine,
            'notfall_auftraege'        => $notfall,
            'lager_kritisch'           => $this->db->fetchAll('SELECT * FROM view_material_nachbestellen'),
            'wartende_kunden'          => $this->db->fetchAll('SELECT * FROM view_wartende_kunden LIMIT 5'),
            'mitarbeiter_auslastung'   => $this->db->fetchAll('SELECT * FROM view_mitarbeiter_auslastung_woche'),
            'ueberfaellige_rechnungen' => $this->db->fetchAll('SELECT * FROM view_ueberfaellige_rechnungen ORDER BY tage_verzug DESC LIMIT 5'),
        ]);
    }
}
