<?php
// ============================================================
//  Material Controller
// ============================================================
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class MaterialController extends BaseController
{
    /**
     * GET /api/material
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $pg     = $this->getPagination($request);

        // Spezial-Abfragen via Views
        if (isset($params['bestellliste'])) {
            return $this->ok($response, $this->db->fetchAll('SELECT * FROM view_material_bestellliste'));
        }
        if (isset($params['nachbestellen'])) {
            return $this->ok($response, $this->db->fetchAll('SELECT * FROM view_material_nachbestellen'));
        }
        if (isset($params['meistverwendet'])) {
            return $this->ok($response, $this->db->fetchAll('SELECT * FROM view_meistverwendete_materialien'));
        }

        $where = ['1=1']; $values = [];

        if (!empty($params['search'])) {
            $where[]  = 'name LIKE ?';
            $values[] = '%' . $this->sanitize($params['search']) . '%';
        }
        if (isset($params['niedrig_bestand'])) {
            $where[] = 'lagerbestand < 5';
        }

        $whereStr = implode(' AND ', $where);

        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM material WHERE {$whereStr}", $values);
        $rows  = $this->db->fetchAll(
            "SELECT * FROM material WHERE {$whereStr} ORDER BY name LIMIT ? OFFSET ?",
            array_merge($values, [$pg['limit'], $pg['offset']])
        );

        return $this->ok($response, [
            'total'      => $total,
            'page'       => $pg['page'],
            'limit'      => $pg['limit'],
            'materialien' => $rows,
        ]);
    }

    /**
     * GET /api/material/{id}
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id  = (int) $args['id'];
        $mat = $this->db->fetchOne('SELECT * FROM material WHERE material_id = ?', [$id]);

        if (!$mat) {
            return $this->notFound($response, "Material #{$id} nicht gefunden.");
        }

        $mat['verwendungen'] = $this->db->fetchAll(
            'SELECT am.*, a.auftrag_nr, a.titel
             FROM auftrag_material am
             JOIN auftrag a ON am.auftrag_id = a.auftrag_id
             WHERE am.material_id = ?
             ORDER BY am.auftrag_material_id DESC LIMIT 10',
            [$id]
        );

        return $this->ok($response, $mat);
    }

    /**
     * POST /api/material
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->sanitize($this->getBody($request));

        $missing = $this->validateRequired($body, ['name', 'preis_pro_einheit']);
        if ($missing) {
            return $this->unprocessable($response, 'Pflichtfelder fehlen.', ['fehlende_felder' => $missing]);
        }

        if (!is_numeric($body['preis_pro_einheit']) || (float)$body['preis_pro_einheit'] <= 0) {
            return $this->unprocessable($response, 'preis_pro_einheit muss eine positive Zahl sein.');
        }

        if (isset($body['lagerbestand']) && (int)$body['lagerbestand'] < 0) {
            return $this->unprocessable($response, 'lagerbestand darf nicht negativ sein.');
        }

        $this->db->execute(
            'INSERT INTO material (name, beschreibung, einheit, lagerbestand, preis_pro_einheit) VALUES (?,?,?,?,?)',
            [
                $body['name'],
                $body['beschreibung'] ?? null,
                $body['einheit']      ?? 'Stück',
                (int)($body['lagerbestand'] ?? 0),
                (float)$body['preis_pro_einheit'],
            ]
        );

        $newId = $this->db->lastInsertId();
        return $this->created($response,
            $this->db->fetchOne('SELECT * FROM material WHERE material_id = ?', [$newId]),
            'Material angelegt.'
        );
    }

    /**
     * POST /api/material/{id}/nachbestellen
     */
    public function nachbestellen(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $body = $this->sanitize($this->getBody($request));

        if (!$this->db->fetchColumn('SELECT 1 FROM material WHERE material_id = ?', [$id])) {
            return $this->notFound($response, "Material #{$id} nicht gefunden.");
        }

        $missing = $this->validateRequired($body, ['menge']);
        if ($missing) {
            return $this->unprocessable($response, 'Feld "menge" fehlt.');
        }

        if (!is_numeric($body['menge']) || (int)$body['menge'] <= 0) {
            return $this->unprocessable($response, 'menge muss eine positive Ganzzahl sein.');
        }

        $this->db->callProcedure('sp_material_nachbestellen', [$id, (int)$body['menge']]);

        return $this->ok($response,
            $this->db->fetchOne('SELECT * FROM material WHERE material_id = ?', [$id]),
            'Lagerbestand aktualisiert.'
        );
    }

    /**
     * PUT /api/material/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $body = $this->sanitize($this->getBody($request));

        if (!$this->db->fetchColumn('SELECT 1 FROM material WHERE material_id = ?', [$id])) {
            return $this->notFound($response, "Material #{$id} nicht gefunden.");
        }

        $fields = []; $vals = [];

        foreach (['name', 'beschreibung', 'einheit'] as $f) {
            if (isset($body[$f])) { $fields[] = "{$f} = ?"; $vals[] = $body[$f]; }
        }
        if (isset($body['preis_pro_einheit'])) {
            if ((float)$body['preis_pro_einheit'] <= 0) {
                return $this->unprocessable($response, 'preis_pro_einheit muss > 0 sein.');
            }
            $fields[] = 'preis_pro_einheit = ?'; $vals[] = (float)$body['preis_pro_einheit'];
        }
        if (isset($body['lagerbestand'])) {
            if ((int)$body['lagerbestand'] < 0) {
                return $this->unprocessable($response, 'lagerbestand darf nicht negativ sein.');
            }
            $fields[] = 'lagerbestand = ?'; $vals[] = (int)$body['lagerbestand'];
        }

        if (empty($fields)) {
            return $this->badRequest($response, 'Keine aktualisierbaren Felder.');
        }

        $vals[] = $id;
        $this->db->execute('UPDATE material SET ' . implode(', ', $fields) . ' WHERE material_id = ?', $vals);

        return $this->ok($response,
            $this->db->fetchOne('SELECT * FROM material WHERE material_id = ?', [$id]),
            'Material aktualisiert.'
        );
    }

    /**
     * DELETE /api/material/{id}
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];

        if (!$this->db->fetchColumn('SELECT 1 FROM material WHERE material_id = ?', [$id])) {
            return $this->notFound($response, "Material #{$id} nicht gefunden.");
        }

        try {
            $this->db->execute('DELETE FROM material WHERE material_id = ?', [$id]);
            return $this->ok($response, null, "Material #{$id} gelöscht.");
        } catch (\PDOException $e) {
            return $this->conflict($response, 'Löschen fehlgeschlagen (Material wird verwendet).');
        }
    }
}
