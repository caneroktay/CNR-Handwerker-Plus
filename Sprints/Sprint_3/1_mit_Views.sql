USE handwerkerpro_db;

-- 1. View: Umfassende Kundenliste (Privat & Firma kombiniert)
-- Hilft dabei, alle Kunden mit ihrem Klarnamen oder Firmennamen zentral zu sehen.
CREATE OR REPLACE VIEW view_kunden_uebersicht AS
SELECT 
    k.kunden_id,
    k.typ,
    CASE 
        WHEN k.typ = 'privat' THEN CONCAT(kp.vorname, ' ', kp.nachname)
        WHEN k.typ = 'firma' THEN kf.firmenname
    END AS kunden_name,
    kf.ansprechpartner,
    k.ist_stammkunde,
    (SELECT wert FROM kunden_kontakt WHERE kunden_id = k.kunden_id LIMIT 1) AS primaer_kontakt
FROM kunden k
LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
LEFT JOIN kunden_firma kf ON k.kunden_id = kf.kunden_id;


-- 2. View: Aktuelle Einsatzplanung
-- Zeigt an, welcher Mitarbeiter wann zu welchem Auftrag ausrücken muss.
CREATE OR REPLACE VIEW view_einsatzplanung AS
SELECT 
    t.start_datetime AS termin_start,
    t.end_datetime AS termin_ende,
    CONCAT(m.vorname, ' ', m.nachname) AS mitarbeiter,
    a.auftrag_nr,
    a.titel AS auftrag_titel,
    a.prioritaet,
    t.status AS termin_status
FROM termin t
JOIN mitarbeiter m ON t.mitarbeiter_id = m.mitarbeiter_id
JOIN auftrag a ON t.auftrag_id = a.auftrag_id
WHERE t.status = 'geplant'
ORDER BY t.start_datetime;


-- 3. View: Offene Rechnungen und Mahnwesen
-- Zeigt alle Rechnungen, die noch nicht bezahlt sind, inklusive Verzugstage.
CREATE OR REPLACE VIEW view_offene_rechnungen AS
SELECT 
    r.rechnung_id,
    vk.kunden_name,
    r.rechnungs_datum,
    r.faellig_am,
    DATEDIFF(CURDATE(), r.faellig_am) AS verzug_tage,
    r.status,
    COALESCE(SUM(rp.menge * rp.einzelpreis_bei_rechnung * (1 + rp.mwst_satz/100)), 0) AS brutto_betrag
FROM rechnung r
JOIN view_kunden_uebersicht vk ON r.kunden_id = vk.kunden_id
LEFT JOIN rechnung_position rp ON r.rechnung_id = rp.rechnung_id
WHERE r.status IN ('gesendet', 'überfällig', 'entwurf')
GROUP BY r.rechnung_id;


-- 4. View: Material-Bedarfsliste (Logistik)
-- Zeigt, welches Material für aktive oder geplante Aufträge bestellt werden muss.
CREATE OR REPLACE VIEW view_material_bestellliste AS
SELECT 
    m.name AS material_name,
    SUM(am.menge) AS benoetigte_gesamtmenge,
    m.lagerbestand,
    m.einheit,
    COUNT(am.auftrag_id) AS anzahl_auftraege
FROM auftrag_material am
JOIN material m ON am.material_id = m.material_id
JOIN auftrag a ON am.auftrag_id = a.auftrag_id
WHERE am.bestell_status = 'Bestellt' AND a.status IN ('neu', 'geplant', 'aktiv')
GROUP BY m.material_id
HAVING benoetigte_gesamtmenge > m.lagerbestand;


-- 5. View: Umsatz-Statistik pro Mitarbeiter
-- Zeigt die erbrachte Leistung (Arbeitswerte) der Mitarbeiter basierend auf abgeschlossenen Aufträgen.
CREATE OR REPLACE VIEW view_mitarbeiter_umsatz AS
SELECT 
    m.mitarbeiter_id,
    m.vorname,
    m.nachname,
    m.rolle,
    COUNT(DISTINCT a.auftrag_id) AS erledigte_auftraege,
    CAST(SUM(ap.menge * ap.einzelpreis_bei_bestellung) AS DECIMAL(10,2)) AS generierter_arbeits_umsatz_netto    -- mit CAST - 2 Stellen
FROM mitarbeiter m
JOIN termin t ON m.mitarbeiter_id = t.mitarbeiter_id
JOIN auftrag a ON t.auftrag_id = a.auftrag_id
JOIN auftrag_position ap ON a.auftrag_id = ap.auftrag_id
WHERE a.status IN ('abgeschlossen', 'abgerechnet') 
  AND ap.typ = 'arbeit'
  AND t.status = 'abgeschlossen' -- Nur abgelaufene Termine
GROUP BY m.mitarbeiter_id;



-- TESTEN ----------------------------------------
-- 1. View ---------------------------------------
SELECT * FROM view_kunden_uebersicht;
-- 2. View ---------------------------------------
SELECT * FROM view_einsatzplanung;
-- 3. View ---------------------------------------
SELECT * FROM view_offene_rechnungen;

-- 4. View ---------------------------------------
-- -- Für die Kontrolle der view_material_bestellliste müssen diese Daten vorhanden sein. 
-- -- Ein neues Projekt für Kunde 3 (Berger Bau GmbH).
-- INSERT INTO auftrag (kunden_id, auftrag_nr, titel, beschreibung, status, prioritaet) 
-- VALUES (3, 'AUF-TEST-001', 'Test-Projekt Materialmangel', 'testdata für view_material_bestellliste .', 'geplant', 'normal');
-- -- Von „Heizungspumpe” (ID: 4) sind nur 4 Stück auf Lager. 
-- -- Wir fordern 10 Stück an, damit der Lagerbestand nicht ausreicht und sie in View angezeigt werden.
-- INSERT INTO auftrag_material (auftrag_id, material_id, menge, bestell_status)
-- VALUES (
--     (SELECT auftrag_id FROM auftrag WHERE auftrag_nr = 'AUF-TEST-001'), 
--    4,                -- Heizungspumpe
--     10.000, 
--    'Bestellt'
-- );

SELECT * FROM view_material_bestellliste;


-- 5. View ---------------------------------------
-- Für die Kontrolle der view_mitarbeiter_umsatz müssen diese Daten vorhanden sein. 
-- Setzen Wir eine bestehende Bestellung auf den status „Abgeschlossen“.
-- Auftrags-ID 3 (Heizungsausfall) – normmalerweise hatte sie den Status „geplant“.
UPDATE auftrag SET status = 'abgeschlossen' WHERE auftrag_id = 3;
-- Fügen wir für diesen Auftrag einen Arbeitsaufwand (Arbeit) hinzu.
INSERT INTO auftrag_position (auftrag_id, typ, bezeichnung, menge, einzelpreis_bei_bestellung) 
VALUES (3, 'arbeit', 'Heizungsreparatur Fachzeit', 3.0, 85.00);

-- Fügen wir für einen Mitarbeiter, der diese Aufgabe erledigt hat, einen „abgeschlossenen“ Termin hinzu.
-- Mitarbeiter-ID: 2 (Lena Zimmer) hat diese Aufgabe gestern erledigt.
INSERT INTO termin (auftrag_id, mitarbeiter_id, start_datetime, end_datetime, status) 
VALUES (3, 2, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 22 HOUR), 'abgeschlossen');

-- Wenn wir die oben genannten Daten hinzugefügt haben, wird die Tabelle nicht mehr leer sein. 
SELECT * FROM view_mitarbeiter_umsatz;
