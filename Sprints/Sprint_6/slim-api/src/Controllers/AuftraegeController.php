<?php
// ============================================================
//  Aufträge Controller — vollständiges CRUD + Statuswechsel
// ============================================================
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AuftraegeController extends BaseController
{
    private const ERLAUBTE_STATUS    = ['neu','geplant','aktiv','abgeschlossen','abgerechnet','storniert'];
    private const ERLAUBTE_PRIORITÄT = ['niedrig','normal','dringend','notfall'];
    private const ERLAUBTE_SORT      = ['erstellt_am','prioritaet','status','auftrag_nr'];

    /**
     * GET /api/auftraege
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $pg     = $this->getPagination($request);

        $where  = ['1=1'];
        $values = [];

        // Filter: status (kommagetrennt)
        if (!empty($params['status'])) {
            $statusList = array_filter(
                explode(',', $params['status']),
                fn($s) => in_array(trim($s), self::ERLAUBTE_STATUS, true)
            );
            if (empty($statusList)) {
                return $this->unprocessable($response,
                    'Ungültiger Status. Erlaubt: ' . implode(', ', self::ERLAUBTE_STATUS));
            }
            $ph      = implode(',', array_fill(0, count($statusList), '?'));
            $where[] = "a.status IN ({$ph})";
            $values  = array_merge($values, array_values($statusList));
        }

        // Filter: prioritaet
        if (!empty($params['prioritaet'])) {
            if (!in_array($params['prioritaet'], self::ERLAUBTE_PRIORITÄT, true)) {
                return $this->unprocessable($response,
                    'Ungültige Priorität. Erlaubt: ' . implode(', ', self::ERLAUBTE_PRIORITÄT));
            }
            $where[]  = 'a.prioritaet = ?';
            $values[] = $params['prioritaet'];
        }

        // Filter: kunden_id
        if (!empty($params['kunden_id'])) {
            $where[]  = 'a.kunden_id = ?';
            $values[] = (int) $params['kunden_id'];
        }

        // Filter: datum_von / datum_bis
        if (!empty($params['datum_von'])) {
            if (!$this->isValidDate($params['datum_von'])) {
                return $this->unprocessable($response, 'Ungültiges datum_von Format. Erwartet: YYYY-MM-DD');
            }
            $where[]  = 'DATE(a.erstellt_am) >= ?';
            $values[] = $params['datum_von'];
        }
        if (!empty($params['datum_bis'])) {
            if (!$this->isValidDate($params['datum_bis'])) {
                return $this->unprocessable($response, 'Ungültiges datum_bis Format. Erwartet: YYYY-MM-DD');
            }
            $where[]  = 'DATE(a.erstellt_am) <= ?';
            $values[] = $params['datum_bis'];
        }

        // Filter: search
        if (!empty($params['search'])) {
            $where[]  = 'a.titel LIKE ?';
            $values[] = '%' . $this->sanitize($params['search']) . '%';
        }

        $whereStr = implode(' AND ', $where);

        // Sortierung (Whitelist!)
        $sortBy  = in_array($params['sort'] ?? '', self::ERLAUBTE_SORT, true)
                   ? $params['sort'] : 'erstellt_am';
        $sortDir = strtoupper($params['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM auftrag a WHERE {$whereStr}",
            $values
        );

        $rows = $this->db->fetchAll(
            "SELECT a.auftrag_id, a.auftrag_nr, a.titel, a.status, a.prioritaet,
                    a.erstellt_am, a.abgeschlossen_am, a.kunden_id,
                    CASE WHEN k.typ = 'privat'
                         THEN CONCAT(kp.vorname, ' ', kp.nachname)
                         ELSE kf.firmenname END AS kunden_name
             FROM auftrag a
             JOIN kunden k ON a.kunden_id = k.kunden_id
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             WHERE {$whereStr}
             ORDER BY a.{$sortBy} {$sortDir}
             LIMIT ? OFFSET ?",
            array_merge($values, [$pg['limit'], $pg['offset']])
        );

        return $this->ok($response, [
            'total'     => $total,
            'page'      => $pg['page'],
            'limit'     => $pg['limit'],
            'pages'     => (int) ceil($total / $pg['limit']),
            'auftraege' => $rows,
        ]);
    }

    /**
     * GET /api/auftraege/{id}
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id      = (int) $args['id'];
        $auftrag = $this->db->fetchOne(
            "SELECT a.*,
                    CASE WHEN k.typ = 'privat'
                         THEN CONCAT(kp.vorname, ' ', kp.nachname)
                         ELSE kf.firmenname END AS kunden_name,
                    k.typ AS kunden_typ
             FROM auftrag a
             JOIN kunden k ON a.kunden_id = k.kunden_id
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             WHERE a.auftrag_id = ?",
            [$id]
        );

        if (!$auftrag) {
            return $this->notFound($response, "Auftrag #{$id} nicht gefunden.");
        }

        // Positionen
        $auftrag['positionen'] = $this->db->fetchAll(
            'SELECT * FROM auftrag_position WHERE auftrag_id = ?', [$id]
        );

        // Materialien
        $auftrag['materialien'] = $this->db->fetchAll(
            'SELECT am.*, m.name, m.einheit, m.preis_pro_einheit
             FROM auftrag_material am
             JOIN material m ON am.material_id = m.material_id
             WHERE am.auftrag_id = ?',
            [$id]
        );

        // Termine
        $auftrag['termine'] = $this->db->fetchAll(
            "SELECT t.*, CONCAT(m.vorname, ' ', m.nachname) AS mitarbeiter
             FROM termin t
             JOIN mitarbeiter m ON t.mitarbeiter_id = m.mitarbeiter_id
             WHERE t.auftrag_id = ?",
            [$id]
        );

        // Summen berechnen
        $netto = array_sum(array_map(
            fn($p) => (float)$p['menge'] * (float)$p['einzelpreis_bei_bestellung'],
            $auftrag['positionen']
        ));
        $auftrag['summe_netto']  = round($netto, 2);
        $auftrag['summe_brutto'] = round($netto * 1.19, 2);

        return $this->ok($response, $auftrag);
    }

    /**
     * POST /api/auftraege
     * Neuer Auftrag via Stored Procedure sp_neuer_auftrag
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->sanitize($this->getBody($request));

        $missing = $this->validateRequired($body, ['kunden_id', 'titel']);
        if ($missing) {
            return $this->unprocessable($response, 'Pflichtfelder fehlen.', ['fehlende_felder' => $missing]);
        }

        if (!is_numeric($body['kunden_id']) || (int)$body['kunden_id'] <= 0) {
            return $this->unprocessable($response, 'kunden_id muss eine positive Ganzzahl sein.');
        }

        if (strlen($body['titel']) < 3) {
            return $this->unprocessable($response, 'Titel muss mindestens 3 Zeichen lang sein.');
        }
        if (strlen($body['titel']) > 150) {
            return $this->unprocessable($response, 'Titel darf maximal 150 Zeichen lang sein.');
        }

        $prio = $body['prioritaet'] ?? 'normal';
        if (!in_array($prio, self::ERLAUBTE_PRIORITÄT, true)) {
            return $this->unprocessable($response,
                'Ungültige Priorität. Erlaubt: ' . implode(', ', self::ERLAUBTE_PRIORITÄT));
        }

        // Kunde existiert?
        $exists = $this->db->fetchColumn(
            'SELECT 1 FROM kunden WHERE kunden_id = ?', [(int)$body['kunden_id']]
        );
        if (!$exists) {
            return $this->notFound($response, "Kunde #{$body['kunden_id']} nicht gefunden.");
        }

        try {
            $this->db->callProcedure('sp_neuer_auftrag', [(int)$body['kunden_id'], $body['titel'], $prio]);

            $neu = $this->db->fetchOne(
                'SELECT * FROM auftrag WHERE kunden_id = ? ORDER BY auftrag_id DESC LIMIT 1',
                [(int)$body['kunden_id']]
            );

            if (!empty($body['beschreibung'])) {
                $this->db->execute(
                    'UPDATE auftrag SET beschreibung = ? WHERE auftrag_id = ?',
                    [$body['beschreibung'], $neu['auftrag_id']]
                );
                $neu['beschreibung'] = $body['beschreibung'];
            }

            return $this->created($response, $neu, 'Auftrag erfolgreich angelegt.');

        } catch (\Exception $e) {
            return $this->serverError($response, 'Datenbankfehler: ' . $e->getMessage());
        }
    }

    /**
     * PUT /api/auftraege/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $body = $this->sanitize($this->getBody($request));

        if (!$this->auftragExists($id)) {
            return $this->notFound($response, "Auftrag #{$id} nicht gefunden.");
        }

        if (empty($body)) {
            return $this->badRequest($response, 'Kein Update-Body gesendet.');
        }

        $fields = []; $vals = [];
        $whitelist = ['titel', 'beschreibung', 'prioritaet', 'notiz_intern', 'kunden_kommentar', 'status'];

        foreach ($whitelist as $f) {
            if (!array_key_exists($f, $body)) continue;

            if ($f === 'prioritaet' && !in_array($body[$f], self::ERLAUBTE_PRIORITÄT, true)) {
                return $this->unprocessable($response, 'Ungültige Priorität.');
            }
            if ($f === 'status' && !in_array($body[$f], self::ERLAUBTE_STATUS, true)) {
                return $this->unprocessable($response, 'Ungültiger Status.');
            }

            $fields[] = "{$f} = ?";
            $vals[]   = $body[$f];
        }

        if (empty($fields)) {
            return $this->badRequest($response, 'Keine aktualisierbaren Felder gefunden.');
        }

        $vals[] = $id;
        $this->db->execute('UPDATE auftrag SET ' . implode(', ', $fields) . ' WHERE auftrag_id = ?', $vals);

        return $this->ok($response,
            $this->db->fetchOne('SELECT * FROM auftrag WHERE auftrag_id = ?', [$id]),
            'Auftrag aktualisiert.'
        );
    }

    /**
     * PUT /api/auftraege/{id}/status
     */
    public function updateStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $body = $this->sanitize($this->getBody($request));

        if (!$this->auftragExists($id)) {
            return $this->notFound($response, "Auftrag #{$id} nicht gefunden.");
        }

        $missing = $this->validateRequired($body, ['status']);
        if ($missing) {
            return $this->unprocessable($response, 'Feld "status" fehlt.');
        }

        if (!in_array($body['status'], self::ERLAUBTE_STATUS, true)) {
            return $this->unprocessable($response,
                'Ungültiger Status. Erlaubt: ' . implode(', ', self::ERLAUBTE_STATUS));
        }

        try {
            if ($body['status'] === 'abgeschlossen') {
                $this->db->callProcedure('sp_auftrag_abschliessen', [$id]);
                $auftrag = $this->db->fetchOne('SELECT * FROM auftrag WHERE auftrag_id = ?', [$id]);
                return $this->ok($response, $auftrag, 'Auftrag abgeschlossen. Rechnung wurde automatisch erstellt.');
            }

            if ($body['status'] === 'storniert') {
                $this->db->callProcedure('sp_auftrag_stornieren', [$id]);
                $auftrag = $this->db->fetchOne('SELECT * FROM auftrag WHERE auftrag_id = ?', [$id]);
                return $this->ok($response, $auftrag, 'Auftrag storniert. Materialien wurden zurückgebucht.');
            }

            $this->db->execute('UPDATE auftrag SET status = ? WHERE auftrag_id = ?', [$body['status'], $id]);
            return $this->ok($response,
                $this->db->fetchOne('SELECT * FROM auftrag WHERE auftrag_id = ?', [$id]),
                'Status aktualisiert.'
            );

        } catch (\PDOException $e) {
            return $this->serverError($response, 'Fehler beim Statuswechsel: ' . $e->getMessage());
        }
    }

    /**
     * DELETE /api/auftraege/{id}
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];

        $auftrag = $this->db->fetchOne(
            'SELECT auftrag_id, status FROM auftrag WHERE auftrag_id = ?', [$id]
        );
        if (!$auftrag) {
            return $this->notFound($response, "Auftrag #{$id} nicht gefunden.");
        }

        if ($auftrag['status'] === 'abgerechnet') {
            return $this->conflict($response, 'Bereits abgerechnete Aufträge können nicht gelöscht werden.');
        }

        try {
            $this->db->execute('DELETE FROM auftrag WHERE auftrag_id = ?', [$id]);
            return $this->ok($response, null, "Auftrag #{$id} erfolgreich gelöscht.");
        } catch (\PDOException $e) {
            return $this->conflict($response, 'Löschen fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private function auftragExists(int $id): bool
    {
        return (bool) $this->db->fetchColumn('SELECT 1 FROM auftrag WHERE auftrag_id = ?', [$id]);
    }
}
