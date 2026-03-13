<?php
// ============================================================
//  Rechnungen Controller
// ============================================================
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RechnungenController extends BaseController
{
    private const ERLAUBTE_STATUS = ['entwurf','gesendet','bezahlt','überfällig'];

    /**
     * GET /api/rechnungen
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $pg     = $this->getPagination($request);

        $where = ['1=1']; $values = [];

        if (!empty($params['status'])) {
            if (!in_array($params['status'], self::ERLAUBTE_STATUS, true)) {
                return $this->unprocessable($response,
                    'Ungültiger Status. Erlaubt: ' . implode(', ', self::ERLAUBTE_STATUS));
            }
            $where[]  = 'r.status = ?';
            $values[] = $params['status'];
        }

        if (!empty($params['kunden_id'])) {
            $where[]  = 'r.kunden_id = ?';
            $values[] = (int) $params['kunden_id'];
        }

        if (!empty($params['ueberfaellig']) && filter_var($params['ueberfaellig'], FILTER_VALIDATE_BOOLEAN)) {
            $where[] = "r.faellig_am < CURDATE() AND r.status != 'bezahlt'";
        }

        if (!empty($params['datum_von'])) {
            if (!$this->isValidDate($params['datum_von'])) {
                return $this->unprocessable($response, 'Ungültiges datum_von Format. Erwartet: YYYY-MM-DD');
            }
            $where[]  = 'r.rechnungs_datum >= ?';
            $values[] = $params['datum_von'];
        }

        if (!empty($params['datum_bis'])) {
            if (!$this->isValidDate($params['datum_bis'])) {
                return $this->unprocessable($response, 'Ungültiges datum_bis Format. Erwartet: YYYY-MM-DD');
            }
            $where[]  = 'r.rechnungs_datum <= ?';
            $values[] = $params['datum_bis'];
        }

        $whereStr = implode(' AND ', $where);

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM rechnung r WHERE {$whereStr}", $values
        );

        $rows = $this->db->fetchAll(
            "SELECT r.rechnung_id, r.auftrag_id, r.kunden_id, r.rechnungs_datum,
                    r.faellig_am, r.status, r.gesamtbetrag, r.created_at,
                    CASE WHEN k.typ = 'privat'
                         THEN CONCAT(kp.vorname, ' ', kp.nachname)
                         ELSE kf.firmenname END AS kunden_name,
                    DATEDIFF(CURDATE(), r.faellig_am) AS verzug_tage,
                    COALESCE(SUM(rp.menge * rp.einzelpreis_bei_rechnung * (1 + rp.mwst_satz/100)), 0) AS brutto_betrag
             FROM rechnung r
             JOIN kunden k ON r.kunden_id = k.kunden_id
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             LEFT JOIN rechnung_position rp ON r.rechnung_id = rp.rechnung_id
             WHERE {$whereStr}
             GROUP BY r.rechnung_id
             ORDER BY r.rechnungs_datum DESC
             LIMIT ? OFFSET ?",
            array_merge($values, [$pg['limit'], $pg['offset']])
        );

        // Statistik
        $stats = $this->db->fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN r.status='bezahlt'
                    THEN rp.menge*rp.einzelpreis_bei_rechnung ELSE 0 END), 0) AS bezahlt_netto,
                COALESCE(SUM(CASE WHEN r.status!='bezahlt'
                    THEN rp.menge*rp.einzelpreis_bei_rechnung ELSE 0 END), 0) AS offen_netto
             FROM rechnung r
             LEFT JOIN rechnung_position rp ON r.rechnung_id = rp.rechnung_id
             WHERE {$whereStr}",
            $values
        );

        return $this->ok($response, [
            'total'      => $total,
            'page'       => $pg['page'],
            'limit'      => $pg['limit'],
            'pages'      => (int) ceil($total / $pg['limit']),
            'statistik'  => $stats,
            'rechnungen' => $rows,
        ]);
    }

    /**
     * GET /api/rechnungen/{id}
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];

        $rechnung = $this->db->fetchOne(
            "SELECT r.*,
                    CASE WHEN k.typ = 'privat'
                         THEN CONCAT(kp.vorname, ' ', kp.nachname)
                         ELSE kf.firmenname END AS kunden_name,
                    DATEDIFF(CURDATE(), r.faellig_am) AS verzug_tage,
                    COALESCE(SUM(rp.menge * rp.einzelpreis_bei_rechnung * (1 + rp.mwst_satz/100)), 0) AS brutto_betrag,
                    COALESCE(SUM(rp.menge * rp.einzelpreis_bei_rechnung), 0) AS netto_betrag
             FROM rechnung r
             JOIN kunden k ON r.kunden_id = k.kunden_id
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             LEFT JOIN rechnung_position rp ON r.rechnung_id = rp.rechnung_id
             WHERE r.rechnung_id = ?
             GROUP BY r.rechnung_id",
            [$id]
        );

        if (!$rechnung) {
            return $this->notFound($response, "Rechnung #{$id} nicht gefunden.");
        }

        $rechnung['positionen'] = $this->db->fetchAll(
            'SELECT rp.*, ap.bezeichnung, ap.typ
             FROM rechnung_position rp
             LEFT JOIN auftrag_position ap ON rp.position_id = ap.position_id
             WHERE rp.rechnung_id = ?',
            [$id]
        );

        return $this->ok($response, $rechnung);
    }

    /**
     * POST /api/rechnungen/{id}/bezahlen
     * Markiert Rechnung als bezahlt via sp_rechnung_bezahlen
     */
    public function bezahlen(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];

        $rechnung = $this->db->fetchOne(
            'SELECT rechnung_id, status FROM rechnung WHERE rechnung_id = ?', [$id]
        );

        if (!$rechnung) {
            return $this->notFound($response, "Rechnung #{$id} nicht gefunden.");
        }

        if ($rechnung['status'] === 'bezahlt') {
            return $this->conflict($response, "Rechnung #{$id} ist bereits als bezahlt markiert.");
        }

        try {
            $this->db->callProcedure('sp_rechnung_bezahlen', [$id]);
            return $this->ok($response,
                $this->db->fetchOne('SELECT * FROM rechnung WHERE rechnung_id = ?', [$id]),
                "Rechnung #{$id} erfolgreich als bezahlt markiert."
            );
        } catch (\Exception $e) {
            return $this->serverError($response, 'Fehler: ' . $e->getMessage());
        }
    }

    /**
     * PUT /api/rechnungen/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $body = $this->sanitize($this->getBody($request));

        if (!$this->db->fetchColumn('SELECT 1 FROM rechnung WHERE rechnung_id = ?', [$id])) {
            return $this->notFound($response, "Rechnung #{$id} nicht gefunden.");
        }

        $fields = []; $vals = [];

        if (isset($body['status'])) {
            if (!in_array($body['status'], self::ERLAUBTE_STATUS, true)) {
                return $this->unprocessable($response,
                    'Ungültiger Status. Erlaubt: ' . implode(', ', self::ERLAUBTE_STATUS));
            }
            $fields[] = 'status = ?'; $vals[] = $body['status'];
        }

        if (isset($body['faellig_am'])) {
            if (!$this->isValidDate($body['faellig_am'])) {
                return $this->unprocessable($response, 'Ungültiges faellig_am Format. Erwartet: YYYY-MM-DD');
            }
            $fields[] = 'faellig_am = ?'; $vals[] = $body['faellig_am'];
        }

        if (empty($fields)) {
            return $this->badRequest($response, 'Keine aktualisierbaren Felder.');
        }

        $vals[] = $id;
        $this->db->execute('UPDATE rechnung SET ' . implode(', ', $fields) . ' WHERE rechnung_id = ?', $vals);

        return $this->ok($response,
            $this->db->fetchOne('SELECT * FROM rechnung WHERE rechnung_id = ?', [$id]),
            'Rechnung aktualisiert.'
        );
    }
}
