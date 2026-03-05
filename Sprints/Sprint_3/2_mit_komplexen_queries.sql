-- Was wurde verbessert?
-- 1- Indizes: Die CREATE INDEX Befehle sorgen für schnellere Abfragen, besonders bei großen Datenmengen.

-- 2- Multiplikationsfehler: In view_monatsumsatz_mitarbeiter wird 
-- der Umsatz jetzt in einer Subquery pro Auftrag fixiert, bevor er dem Mitarbeiter zugeordnet wird.

-- 3- Echte Zeitmessung: Die View für die Bearbeitungszeit nutzt nun abgeschlossen_am. 
-- (Beim Update eines Auftrags auf 'abgeschlossen' muss dieses Feld nun mit NOW() befüllt werden).

-- 4- Namen: Die Top-10-Liste zeigt nun echte Namen statt nur IDs.

-- 5- Wochenübersicht: Zeigt nun Aufträge, an denen diese Woche gearbeitet wird (via termin), 
-- was für die Ressourcenplanung logischer ist.

-- 6- view_mitarbeiter_umsatz wurde aus dem Türkischen ins Deutsche übersetzt. 


USE handwerkerpro_db;

-- ---------------------------------------------------------------------------------------------------
-- Korrecturen und Erweiterungen: 
-- Damit die Bearbeitungszeit stimmt, fügen wir ein festes Feld für den Abschlusszeitpunkt hinzu.
-- Korrektur der Tabellenstruktur für view_durchschnittliche_bearbeitungszeit
ALTER TABLE auftrag ADD COLUMN abgeschlossen_am DATETIME AFTER status;

-- Performance-Indizes für schnelle Abfragen (Abnahmekriterium)
CREATE INDEX idx_auftrag_status ON auftrag(status);
CREATE INDEX idx_auftrag_erstellt ON auftrag(erstellt_am);
CREATE INDEX idx_termin_start ON termin(start_datetime);
CREATE INDEX idx_rechnung_faellig ON rechnung(faellig_am);
CREATE INDEX idx_rechnung_status ON rechnung(status);
-- ----------------------------------------------------------------------------------------------------


-- -----------------------------------------------------------------------------
-- 1. Tagesgeschäft: Der schnelle Überblick ------------------------------------
-- -----------------------------------------------------------------------------

-- A. Wartende Kunden (Anfragen)
-- Sortiert nach der Wartezeit (erstellt_am bis heute)

CREATE OR REPLACE VIEW view_wartende_kunden AS
SELECT 
    k.kunden_id,
    CASE WHEN k.typ = 'privat' THEN CONCAT(kp.vorname, ' ', kp.nachname) ELSE kf.firmenname END AS kunde,
    a.titel AS anfrage_titel,
    a.erstellt_am,
    DATEDIFF(NOW(), a.erstellt_am) AS tage_warten
FROM auftrag a
JOIN kunden k ON a.kunden_id = k.kunden_id
LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
LEFT JOIN kunden_firma kf ON k.kunden_id = kf.kunden_id
WHERE a.status = 'neu'
ORDER BY tage_warten DESC;

-- B. Unbezahlte Rechnungen mit Verzugstagen
CREATE OR REPLACE VIEW view_ueberfaellige_rechnungen AS
SELECT 
    r.rechnung_id,
    r.faellig_am,
    DATEDIFF(CURDATE(), r.faellig_am) AS tage_verzug,
    SUM(rp.menge * rp.einzelpreis_bei_rechnung * (1 + COALESCE(rp.mwst_satz, 19)/100)) AS brutto_betrag
FROM rechnung r
JOIN rechnung_position rp ON r.rechnung_id = rp.rechnung_id
WHERE r.status != 'bezahlt' AND r.faellig_am < CURDATE()
GROUP BY r.rechnung_id;

-- C. Tagesplan für Mitarbeiter (Heute)
CREATE OR REPLACE VIEW view_tagesplan_mitarbeiter AS
SELECT 
    CONCAT(m.vorname, ' ', m.nachname) AS mitarbeiter,
    t.start_datetime,
    t.end_datetime,
    a.titel AS auftrag,
    a.prioritaet
FROM termin t
JOIN mitarbeiter m ON t.mitarbeiter_id = m.mitarbeiter_id
JOIN auftrag a ON t.auftrag_id = a.auftrag_id
WHERE DATE(t.start_datetime) = CURDATE()
ORDER BY m.mitarbeiter_id, t.start_datetime;


-- ------------------------------------------------------------------------------
-- 2. Wochenplanung: Ressourcen & Material --------------------------------------
-- ------------------------------------------------------------------------------

-- A. A. Aktive Aufträge dieser Woche (Basierend auf Terminen, nicht Erstellung)
CREATE OR REPLACE VIEW view_wochenuebersicht_auftraege AS
SELECT DISTINCT a.* FROM auftrag a
JOIN termin t ON a.auftrag_id = t.auftrag_id
WHERE YEARWEEK(t.start_datetime, 1) = YEARWEEK(CURDATE(), 1);

-- B. Mitarbeiter-Auslastung (Stunden pro Woche)
CREATE OR REPLACE VIEW view_mitarbeiter_auslastung_woche AS
SELECT 
    m.vorname, m.nachname,
    SUM(TIMESTAMPDIFF(HOUR, t.start_datetime, t.end_datetime)) AS geplante_stunden
FROM mitarbeiter m
JOIN termin t ON m.mitarbeiter_id = t.mitarbeiter_id
WHERE YEARWEEK(t.start_datetime, 1) = YEARWEEK(CURDATE(), 1)
GROUP BY m.mitarbeiter_id;

-- C. Materialbedarf für diese Woche
CREATE OR REPLACE VIEW view_materialbedarf_woche AS
SELECT 
    mat.name,
    SUM(am.menge) AS benoetigte_menge,
    mat.lagerbestand AS aktueller_bestand,
    mat.einheit
FROM auftrag_material am
JOIN material mat ON am.material_id = mat.material_id
JOIN termin t ON am.auftrag_id = t.auftrag_id
WHERE YEARWEEK(t.start_datetime, 1) = YEARWEEK(CURDATE(), 1)
GROUP BY mat.material_id;


-- ------------------------------------------------------------------------------
-- 3. Monatsreporting: Erfolgskontrolle    --------------------------------------
-- ------------------------------------------------------------------------------

-- A. Umsatz pro Mitarbeiter (Korrektur: Verhindert Mehrfachzählung durch DISTINCT Mitarbeiter-Auftrag-Verknüpfung)
CREATE OR REPLACE VIEW view_monatsumsatz_mitarbeiter AS
SELECT 
    m.vorname, m.nachname,
    SUM(umsatz_pro_auftrag.netto) AS monats_umsatz
FROM mitarbeiter m
-- Korrektur: Wir nutzen DISTINCT, damit jeder Auftrag pro Mitarbeiter nur EINMAL zählt, 
-- egal wie viele Termine er dafür wahrgenommen hat.
JOIN (
    SELECT DISTINCT mitarbeiter_id, auftrag_id 
    FROM termin
) AS t ON m.mitarbeiter_id = t.mitarbeiter_id
JOIN (
    -- Subquery berechnet den Nettoumsatz pro Auftrag für den aktuellen Monat
    SELECT r.auftrag_id, SUM(rp.menge * rp.einzelpreis_bei_rechnung) AS netto
    FROM rechnung r
    JOIN rechnung_position rp ON r.rechnung_id = rp.rechnung_id
    WHERE MONTH(r.rechnungs_datum) = MONTH(CURDATE()) 
      AND YEAR(r.rechnungs_datum) = YEAR(CURDATE())
    GROUP BY r.auftrag_id
) AS umsatz_pro_auftrag ON t.auftrag_id = umsatz_pro_auftrag.auftrag_id
GROUP BY m.mitarbeiter_id;

-- B. Durchschnittliche Bearbeitungszeit 
-- Korrektur: Nutzt neues Feld abgeschlossen_am
CREATE OR REPLACE VIEW view_durchschnittliche_bearbeitungszeit AS
SELECT 
    AVG(TIMESTAMPDIFF(DAY, erstellt_am, abgeschlossen_am)) AS avg_tage_bis_abschluss
FROM auftrag
WHERE status = 'abgeschlossen' AND abgeschlossen_am IS NOT NULL;

-- C. Top 10 Kunden (Korrektur: Jetzt mit Klarnamen durch JOIN)
CREATE OR REPLACE VIEW view_top_10_kunden AS
SELECT 
    k.kunden_id,
    CASE WHEN k.typ = 'privat' THEN CONCAT(kp.vorname, ' ', kp.nachname) ELSE kf.firmenname END AS kunden_name,
    SUM(rp.menge * rp.einzelpreis_bei_rechnung) AS gesamt_umsatz
FROM kunden k
LEFT JOIN kunden_person kp ON k.kunden_id = kp.kunden_id
LEFT JOIN kunden_firma kf ON k.kunden_id = kf.kunden_id
JOIN rechnung r ON k.kunden_id = r.kunden_id
JOIN rechnung_position rp ON r.rechnung_id = rp.rechnung_id
GROUP BY k.kunden_id
ORDER BY gesamt_umsatz DESC
LIMIT 10;

-- ------------------------------------------------------------------------------
-- 4. Material & Lager ----------------------------------------------------------
-- ------------------------------------------------------------------------------

-- A. Kritischer Lagerbestand (Nachbestellen)
-- Hier definieren wir eine Regel: Wenn Lagerbestand < 5 Einheiten
CREATE OR REPLACE VIEW view_material_nachbestellen AS
SELECT name, lagerbestand, einheit
FROM material
WHERE lagerbestand < 5;

-- B. Meistverwendete Materialien (Top Runner)
CREATE OR REPLACE VIEW view_meistverwendete_materialien AS
SELECT 
    m.name,
    SUM(am.menge) AS gesamt_verbrauch
FROM auftrag_material am
JOIN material m ON am.material_id = m.material_id
WHERE am.bestell_status = 'Verwendet'
GROUP BY m.material_id
ORDER BY gesamt_verbrauch DESC;

-- -----------------------------------------------
-- TESTEN ----------------------------------------
-- -----------------------------------------------

-- Wartende Kunden (Anfragen) anzeigen
SELECT * FROM view_wartende_kunden;

-- Überfällige Rechnungen und Verzugstage prüfen
SELECT * FROM view_ueberfaellige_rechnungen;

-- Den heutigen Einsatzplan der Mitarbeiter abrufen
SELECT * FROM view_tagesplan_mitarbeiter;

-- Alle Aufträge der aktuellen Kalenderwoche sehen
SELECT * FROM view_wochenuebersicht_auftraege;

-- Die Arbeitsstunden-Auslastung der Mitarbeiter prüfen
SELECT * FROM view_mitarbeiter_auslastung_woche;

-- Benötigtes Material für die Termine dieser Woche auflisten
SELECT * FROM view_materialbedarf_woche;

-- Umsatz pro Mitarbeiter im aktuellen Monat abfragen
SELECT * FROM view_monatsumsatz_mitarbeiter;

-- Durchschnittliche Dauer von Anfrage bis Abschluss prüfen
SELECT * FROM view_durchschnittliche_bearbeitungszeit;

-- Die 10 umsatzstärksten Kunden anzeigen
SELECT * FROM view_top_10_kunden;

-- Einkaufsliste: Materialien unter Mindestbestand (5 Einheiten)
SELECT * FROM view_material_nachbestellen;

-- Analyse: Welche Materialien werden am häufigsten verbraucht?
SELECT * FROM view_meistverwendete_materialien;