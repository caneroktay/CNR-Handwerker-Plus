<?php
// ============================================================
//  Kunden Controller — vollständiges CRUD
//  Sicherheit: Alle Inputs sanitized + Prepared Statements
// ============================================================
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class KundenController extends BaseController
{
    private const ERLAUBTE_TYPEN        = ['privat', 'firma'];
    private const ERLAUBTE_KONTAKTTYPEN = ['Telefon', 'Email', 'Mobil', 'Whatsapp Business'];

    /**
     * GET /api/kunden
     * Liste mit Pagination und Filtern
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $pg     = $this->getPagination($request);

        $where  = ['1=1'];
        $values = [];

        // Filter: typ
        if (!empty($params['typ'])) {
            if (!in_array($params['typ'], self::ERLAUBTE_TYPEN, true)) {
                return $this->unprocessable($response, 'Ungültiger typ. Erlaubt: privat, firma');
            }
            $where[]  = 'k.typ = ?';
            $values[] = $params['typ'];
        }

        // Filter: stammkunde
        if (isset($params['stammkunde'])) {
            $where[]  = 'k.ist_stammkunde = ?';
            $values[] = filter_var($params['stammkunde'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        // Filter: search (Prepared Statement mit LIKE)
        if (!empty($params['search'])) {
            $s        = '%' . $this->sanitize($params['search']) . '%';
            $where[]  = '(kp.vorname LIKE ? OR kp.nachname LIKE ? OR kf.firmenname LIKE ?)';
            $values   = array_merge($values, [$s, $s, $s]);
        }

        $whereStr = implode(' AND ', $where);

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(DISTINCT k.kunden_id)
             FROM kunden k
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             WHERE {$whereStr}",
            $values
        );

        $rows = $this->db->fetchAll(
            "SELECT k.kunden_id, k.typ, k.ist_stammkunde, k.erstellt_am, k.notizen,
                    CASE WHEN k.typ = 'privat'
                         THEN CONCAT(kp.vorname, ' ', kp.nachname)
                         ELSE kf.firmenname END AS kunden_name,
                    kf.ansprechpartner,
                    (SELECT wert FROM kunden_kontakt
                     WHERE kunden_id = k.kunden_id LIMIT 1) AS primaer_kontakt
             FROM kunden k
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             WHERE {$whereStr}
             ORDER BY k.kunden_id DESC
             LIMIT ? OFFSET ?",
            array_merge($values, [$pg['limit'], $pg['offset']])
        );

        return $this->ok($response, [
            'total'   => $total,
            'page'    => $pg['page'],
            'limit'   => $pg['limit'],
            'pages'   => (int) ceil($total / $pg['limit']),
            'kunden'  => $rows,
        ]);
    }

    /**
     * GET /api/kunden/{id}
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id    = (int) $args['id'];
        $kunde = $this->db->fetchOne(
            "SELECT k.kunden_id, k.typ, k.ist_stammkunde, k.erstellt_am, k.notizen,
                    CASE WHEN k.typ = 'privat'
                         THEN CONCAT(kp.vorname, ' ', kp.nachname)
                         ELSE kf.firmenname END AS kunden_name,
                    kf.ansprechpartner
             FROM kunden k
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             WHERE k.kunden_id = ?",
            [$id]
        );

        if (!$kunde) {
            return $this->notFound($response, "Kunde #{$id} nicht gefunden.");
        }

        // Kontakte
        $kunde['kontakte'] = $this->db->fetchAll(
            'SELECT kontakt_id, typ, wert FROM kunden_kontakt WHERE kunden_id = ?',
            [$id]
        );

        // Adressen
        $kunde['adressen'] = $this->db->fetchAll(
            'SELECT * FROM kunden_adressen WHERE kunden_id = ?',
            [$id]
        );

        // Detail (Person oder Firma)
        if ($kunde['typ'] === 'privat') {
            $kunde['details'] = $this->db->fetchOne(
                'SELECT vorname, nachname FROM kunden_person WHERE kunden_id = ?', [$id]
            );
        } else {
            $kunde['details'] = $this->db->fetchOne(
                'SELECT firmenname, ansprechpartner, ust_id FROM kunden_firma WHERE kunden_id = ?', [$id]
            );
        }

        return $this->ok($response, $kunde);
    }

    /**
     * GET /api/kunden/{id}/auftraege
     */
    public function auftraege(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];

        if (!$this->kundeExists($id)) {
            return $this->notFound($response, "Kunde #{$id} nicht gefunden.");
        }

        $rows = $this->db->fetchAll(
            'SELECT auftrag_id, auftrag_nr, titel, status, prioritaet, erstellt_am
             FROM auftrag WHERE kunden_id = ? ORDER BY erstellt_am DESC',
            [$id]
        );

        return $this->ok($response, $rows);
    }

    /**
     * POST /api/kunden
     * Neuen Kunden anlegen (Transaktion: kunden + kunden_person/firma + optional kontakt)
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->sanitize($this->getBody($request));

        // Pflichtfeld: typ
        $missing = $this->validateRequired($body, ['typ']);
        if ($missing) {
            return $this->unprocessable($response, 'Pflichtfelder fehlen.', ['fehlende_felder' => $missing]);
        }

        if (!in_array($body['typ'], self::ERLAUBTE_TYPEN, true)) {
            return $this->unprocessable($response, 'Ungültiger typ. Erlaubt: privat, firma');
        }

        // Typ-spezifische Pflichtfelder
        if ($body['typ'] === 'privat') {
            $missing = $this->validateRequired($body, ['vorname', 'nachname']);
        } else {
            $missing = $this->validateRequired($body, ['firmenname']);
        }
        if ($missing) {
            return $this->unprocessable($response, 'Pflichtfelder fehlen.', ['fehlende_felder' => $missing]);
        }

        // Kontakt-Typ validieren falls mitgegeben
        if (!empty($body['kontakt_typ']) && !in_array($body['kontakt_typ'], self::ERLAUBTE_KONTAKTTYPEN, true)) {
            return $this->unprocessable(
                $response,
                'Ungültiger kontakt_typ. Erlaubt: ' . implode(', ', self::ERLAUBTE_KONTAKTTYPEN)
            );
        }

        $this->db->beginTransaction();
        try {
            // 1. Basis-Datensatz
            $this->db->execute(
                'INSERT INTO kunden (typ, ist_stammkunde, notizen) VALUES (?, ?, ?)',
                [
                    $body['typ'],
                    isset($body['ist_stammkunde']) ? (int)(bool)$body['ist_stammkunde'] : 0,
                    $body['notizen'] ?? null,
                ]
            );
            $kid = $this->db->lastInsertId();

            // 2. Spezialisierung (Person oder Firma)
            if ($body['typ'] === 'privat') {
                $this->db->execute(
                    'INSERT INTO kunden_person (kunden_id, vorname, nachname) VALUES (?, ?, ?)',
                    [$kid, $body['vorname'], $body['nachname']]
                );
            } else {
                $this->db->execute(
                    'INSERT INTO kunden_firma (kunden_id, firmenname, ansprechpartner, ust_id) VALUES (?, ?, ?, ?)',
                    [$kid, $body['firmenname'], $body['ansprechpartner'] ?? null, $body['ust_id'] ?? null]
                );
            }

            // 3. Optional: Erstkontakt
            if (!empty($body['kontakt_typ']) && !empty($body['kontakt_wert'])) {
                $this->db->execute(
                    'INSERT INTO kunden_kontakt (kunden_id, typ, wert) VALUES (?, ?, ?)',
                    [$kid, $body['kontakt_typ'], $body['kontakt_wert']]
                );
            }

            $this->db->commit();

            // Neuen Datensatz zurückgeben
            return $this->created($response, $this->getKundeById($kid), 'Kunde erfolgreich angelegt.');

        } catch (\Exception $e) {
            $this->db->rollBack();
            return $this->serverError($response, 'Datenbankfehler beim Anlegen des Kunden.');
        }
    }

    /**
     * PUT /api/kunden/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $body = $this->sanitize($this->getBody($request));

        $existing = $this->db->fetchOne(
            'SELECT kunden_id, typ FROM kunden WHERE kunden_id = ?', [$id]
        );
        if (!$existing) {
            return $this->notFound($response, "Kunde #{$id} nicht gefunden.");
        }

        if (empty($body)) {
            return $this->badRequest($response, 'Kein Update-Body gesendet.');
        }

        $this->db->beginTransaction();
        try {
            // Basis-Update
            $fields = []; $vals = [];
            foreach (['ist_stammkunde', 'notizen'] as $f) {
                if (array_key_exists($f, $body)) {
                    $fields[] = "{$f} = ?";
                    $vals[]   = $f === 'ist_stammkunde' ? (int)(bool)$body[$f] : $body[$f];
                }
            }
            if ($fields) {
                $vals[] = $id;
                // Sicher: Felder sind whitelist-geprüft, nur Werte über Prepared Statement
                $this->db->execute(
                    'UPDATE kunden SET ' . implode(', ', $fields) . ' WHERE kunden_id = ?',
                    $vals
                );
            }

            // Personen-Detail
            if ($existing['typ'] === 'privat') {
                $pf = []; $pv = [];
                foreach (['vorname', 'nachname'] as $f) {
                    if (isset($body[$f])) { $pf[] = "{$f} = ?"; $pv[] = $body[$f]; }
                }
                if ($pf) {
                    $pv[] = $id;
                    $this->db->execute('UPDATE kunden_person SET ' . implode(', ', $pf) . ' WHERE kunden_id = ?', $pv);
                }
            }

            // Firmen-Detail
            if ($existing['typ'] === 'firma') {
                $ff = []; $fv = [];
                foreach (['firmenname', 'ansprechpartner', 'ust_id'] as $f) {
                    if (isset($body[$f])) { $ff[] = "{$f} = ?"; $fv[] = $body[$f]; }
                }
                if ($ff) {
                    $fv[] = $id;
                    $this->db->execute('UPDATE kunden_firma SET ' . implode(', ', $ff) . ' WHERE kunden_id = ?', $fv);
                }
            }

            $this->db->commit();
            return $this->ok($response, $this->getKundeById($id), 'Kunde aktualisiert.');

        } catch (\Exception $e) {
            $this->db->rollBack();
            return $this->serverError($response, 'Datenbankfehler beim Aktualisieren.');
        }
    }

    /**
     * DELETE /api/kunden/{id}
     * DB-Trigger schützt vor Löschen bei offenen Aufträgen
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];

        if (!$this->kundeExists($id)) {
            return $this->notFound($response, "Kunde #{$id} nicht gefunden.");
        }

        try {
            $this->db->execute('DELETE FROM kunden WHERE kunden_id = ?', [$id]);
            return $this->ok($response, null, "Kunde #{$id} erfolgreich gelöscht.");
        } catch (\PDOException $e) {
            // Trigger tr_kunden_loeschschutz wirft Fehler bei offenen Aufträgen
            return $this->conflict($response, 'Kunde kann nicht gelöscht werden: ' . $e->getMessage());
        }
    }

    // ── Private Hilfsmethoden ────────────────────────────────

    private function kundeExists(int $id): bool
    {
        return (bool) $this->db->fetchColumn(
            'SELECT 1 FROM kunden WHERE kunden_id = ?', [$id]
        );
    }

    private function getKundeById(int $id): array|false
    {
        return $this->db->fetchOne(
            "SELECT k.kunden_id, k.typ, k.ist_stammkunde, k.erstellt_am, k.notizen,
                    CASE WHEN k.typ = 'privat'
                         THEN CONCAT(kp.vorname, ' ', kp.nachname)
                         ELSE kf.firmenname END AS kunden_name,
                    kf.ansprechpartner
             FROM kunden k
             LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
             LEFT JOIN kunden_firma  kf ON k.kunden_id = kf.kunden_id
             WHERE k.kunden_id = ?",
            [$id]
        );
    }
}
