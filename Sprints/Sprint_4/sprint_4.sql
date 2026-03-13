
-- ** Vorbereitung: Logging & Struktur **
-- Damit wir Änderungen nachverfolgen können, brauchen wir eine Archiv- und eine Log-Tabelle.

USE handwerkerpro_db;

-- Tabelle für gelöschte Kunden (Archiv)
CREATE TABLE IF NOT EXISTS kunden_archiv AS SELECT * FROM kunden WHERE 1=0;
ALTER TABLE kunden_archiv 
ADD COLUMN geloescht_am TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN archiv_name VARCHAR(255) AFTER kunden_id, 
ADD COLUMN archiv_kontakt VARCHAR(255) AFTER archiv_name;

-- Allgemeine Log-Tabelle für Statusänderungen
CREATE TABLE IF NOT EXISTS status_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    tabelle_name VARCHAR(50),
    referenz_id INT,
    alter_status VARCHAR(50),
    neuer_status VARCHAR(50),
    zeitpunkt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- --------------------------------------------------------------------------------------
-- 1. Stored Procedures (Die "Motoren" des Systems)
-- Hier sind die 5 wichtigsten Prozeduren mit Parameter-Validierung und Fehlerbehandlung.

DELIMITER //

-- A. Neuer Auftrag mit Validierung
    CREATE PROCEDURE sp_neuer_auftrag(IN p_kunden_id INT, IN p_titel VARCHAR(255), IN p_prio VARCHAR(20))
    BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION 
        BEGIN 
            ROLLBACK;
            RESIGNAL; 
        END;

        START TRANSACTION;
            IF p_kunden_id IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fehler: Kunden_ID darf nicht leer sein!';
            END IF;
            
            INSERT INTO auftrag (kunden_id, titel, status, prioritaet, erstellt_am)
            VALUES (p_kunden_id, p_titel, 'neu', p_prio, NOW());
        COMMIT; 
    END //


-- B. Material zuordnen & Bestand prüfen
    CREATE PROCEDURE sp_material_zuordnen(IN p_auftrag_id INT, IN p_material_id INT, IN p_menge DECIMAL(10,2))
    BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION 
        BEGIN 
            ROLLBACK;
            RESIGNAL; 
        END;

        START TRANSACTION;
            IF p_menge <= 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Menge muss positiv sein!';
            END IF;

            -- Lagerbestandskontrolle
            IF (SELECT lagerbestand FROM material WHERE material_id = p_material_id) < p_menge THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nicht genügend Lagerbestand!';
            END IF;

            INSERT INTO auftrag_material (auftrag_id, material_id, menge, bestell_status)
            VALUES (p_auftrag_id, p_material_id, p_menge, 'Verwendet');
            -- Die Lagerabbuchung erfolgt über den Trigger „tr_lager_abbuchen“.
        COMMIT;
    END //


-- C. Rechnung bezahlen & Status-Log
    CREATE PROCEDURE sp_rechnung_bezahlen(IN p_rechnung_id INT)
    BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION 
        BEGIN 
            ROLLBACK;
            RESIGNAL; 
        END;

        START TRANSACTION;
            UPDATE rechnung 
            SET status = 'bezahlt', updated_at = NOW() 
            WHERE rechnung_id = p_rechnung_id;
        COMMIT;
    END //

-- D. Auftrag abschließen (Setzt Zeitstempel für Sprint 3 Metriken)
    CREATE PROCEDURE sp_auftrag_abschliessen(IN p_auftrag_id INT)
    BEGIN
        -- EXIT HANDLER: Wenn der Trigger zur Rechnungserstellung einen Fehler wirft, muss der Rechnungsabschluss rückgängig gemacht werden (Rollback).
        DECLARE EXIT HANDLER FOR SQLEXCEPTION 
        BEGIN 
            ROLLBACK;
            RESIGNAL; 
        END;

        START TRANSACTION;
            UPDATE auftrag 
            SET status = 'abgeschlossen', abgeschlossen_am = NOW() 
            WHERE auftrag_id = p_auftrag_id;
            -- Wichtig: Der Trigger tr_auto_rechnung_erstellen wird durch dieses UPDATE aktiviert. 
            -- Sollte innerhalb des Triggers ein Fehler auftreten, verhindert der Handler, dass der Auftrag auf 'abgeschlossen' gesetzt wird.
        COMMIT;
    END //

-- E. Material nachbestellen
   CREATE PROCEDURE sp_material_nachbestellen(IN p_mat_id INT, IN p_menge INT)
    BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION 
        BEGIN 
            ROLLBACK;
            RESIGNAL; 
        END;

        START TRANSACTION;
            IF p_menge <= 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Menge muss positiv sein!';
            END IF;

            UPDATE material SET lagerbestand = lagerbestand + p_menge WHERE material_id = p_mat_id;
        COMMIT;
    END //

-- F. Der "Überfälligkeits-Check" (30 Tage)
-- Da SQL-Trigger nur reagieren, wenn sich ein Datensatz ändert, die Zeit aber "einfach so" vergeht, 
-- nutzen wir hier am besten eine Stored Procedure, die das System regelmäßig aufruft.

    DELIMITER //

   CREATE PROCEDURE sp_update_ueberfaellige_rechnungen()
    BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION 
        BEGIN 
            ROLLBACK;
            RESIGNAL; 
        END;

        START TRANSACTION;
            UPDATE rechnung 
            SET status = 'überfällig' 
            WHERE status = 'gesendet' 
            AND DATEDIFF(CURDATE(), faellig_am) >= 30;
        COMMIT;
    END //

    DELIMITER ;

-- --------------------------------------------------------------------------------------
-- 2. Trigger (Die "Automaten")
-- Diese Trigger laufen im Hintergrund und sorgen für Datenintegrität.

DELIMITER //
DROP TRIGGER IF EXISTS tr_auftrag_nummer_gen //
-- A. Automatische Auftragsnummer generieren (BEFORE INSERT)
CREATE TRIGGER tr_auftrag_nummer_gen
BEFORE INSERT ON auftrag
FOR EACH ROW
BEGIN
    DECLARE v_max INT;
    SELECT IFNULL(MAX(CAST(SUBSTRING(auftrag_nr, 6) AS UNSIGNED)), 0) INTO v_max 
    FROM auftrag WHERE auftrag_nr LIKE CONCAT(YEAR(CURDATE()), '-%');
    SET NEW.auftrag_nr = CONCAT(YEAR(CURDATE()), '-', LPAD(v_max + 1, 4, '0'));
END //

-- B. Lagerbestand reduzieren (AFTER INSERT auf auftrag_material)
CREATE TRIGGER tr_lager_abbuchen
AFTER INSERT ON auftrag_material
FOR EACH ROW
BEGIN
    UPDATE material 
    SET lagerbestand = lagerbestand - NEW.menge 
    WHERE material_id = NEW.material_id;
END //

-- C. Löschschutz für Kunden (BEFORE DELETE)
CREATE TRIGGER tr_kunden_loeschschutz
BEFORE DELETE ON kunden
FOR EACH ROW
BEGIN
    DECLARE v_offene_auftraege INT;
    SELECT COUNT(*) INTO v_offene_auftraege FROM auftrag WHERE kunden_id = OLD.kunden_id AND status != 'abgeschlossen';
    
    IF v_offene_auftraege > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Kunde hat noch offene Aufträge und kann nicht gelöscht werden!';
    END IF;
END //

-- D. Statusänderungen protokollieren (AFTER UPDATE auf rechnung und auftrag)
-- RECHNUNG STATUS-LOGGING -------------------------------------
DELIMITER //

CREATE TRIGGER tr_rechnung_status_log
AFTER UPDATE ON rechnung
FOR EACH ROW
BEGIN
    -- Log nur bei Status änderung!
    IF OLD.status <> NEW.status THEN
        INSERT INTO status_log (tabelle_name, referenz_id, alter_status, neuer_status)
        VALUES ('rechnung', NEW.rechnung_id, OLD.status, NEW.status);
    END IF;
END //

DELIMITER ;

DELIMITER ;

-- AUFTRAG STATUS-LOGGING -------------------------------------
DELIMITER //

CREATE TRIGGER tr_auftrag_status_log
AFTER UPDATE ON auftrag
FOR EACH ROW
BEGIN
    IF OLD.status <> NEW.status THEN
        INSERT INTO status_log (tabelle_name, referenz_id, alter_status, neuer_status)
        VALUES ('auftrag', NEW.auftrag_id, OLD.status, NEW.status);
    END IF;
END //

DELIMITER ;

-- E. Der "Auto-Rechnungs-Trigger"
-- Damit sofort eine Rechnung erstellt wird, wenn du einen Auftrag auf "abgeschlossen" setzt.

ALTER TABLE rechnung 
ADD COLUMN gesamtbetrag DECIMAL(10,2) DEFAULT 0.00 AFTER faellig_am;

DELIMITER //

DROP TRIGGER IF EXISTS tr_auto_rechnung_erstellen //

CREATE TRIGGER tr_auto_rechnung_erstellen
AFTER UPDATE ON auftrag
FOR EACH ROW
BEGIN
    DECLARE v_gesamtbetrag DECIMAL(10,2) DEFAULT 0.00;

    IF OLD.status <> 'abgeschlossen' AND NEW.status = 'abgeschlossen' THEN
        
        -- 1. Kosten berechnen: Summe aus (Menge * preis pro einheit)
        SELECT IFNULL(SUM(ROUND(am.menge * m.preis_pro_einheit, 2)), 0.00) INTO v_gesamtbetrag
        FROM auftrag_material am
        JOIN material m ON am.material_id = m.material_id
        WHERE am.auftrag_id = NEW.auftrag_id;

        -- 2. Rechnung mit dem berechneten Betrag erstellen
        INSERT INTO rechnung (
            auftrag_id, 
            kunden_id,          
            rechnungs_datum, 
            faellig_am, 
            gesamtbetrag, -- Neu hinzugefügte Spalte !!!!
            status              
        )
        VALUES (
            NEW.auftrag_id, 
            NEW.kunden_id,      
            CURDATE(), 
            DATE_ADD(CURDATE(), INTERVAL 14 DAY), 
            v_gesamtbetrag, 
            'entwurf'           
        );
        
    END IF;
END //

DELIMITER ;

-- F. Material aus dem Auftrag löschen
-- Wenn ein Material aus einem Auftrag entfernt wird, soll der Lagerbestand automatisch wieder erhöht werden.

DELIMITER //

CREATE TRIGGER tr_lager_rueckgabe_delete
AFTER DELETE ON auftrag_material
FOR EACH ROW
BEGIN
    UPDATE material 
    SET lagerbestand = lagerbestand + OLD.menge 
    WHERE material_id = OLD.material_id;
END //

DELIMITER ;

-- G. Materialmenge im Auftrag ändern
-- Wenn die Menge eines Materials in einem Auftrag geändert wird, soll die Differenz automatisch im Lagerbestand angepasst werden.

DELIMITER //

CREATE TRIGGER tr_lager_anpassung_update
AFTER UPDATE ON auftrag_material
FOR EACH ROW
BEGIN
    -- Wenn die Differenz positiv ist, erfolgt eine Rückgabe; wenn sie negativ ist, erfolgt ein zusätzlicher Abzug.
    -- Beisp.: 10 (ALT) - 12 (NEU) = -2 (Es werden 2 weitere Einheiten vom Bestand abgezogen)
    -- Beisp.: 10 (ALT) - 7 (NEU) = +3 (3 Einheiten werden dem Bestand wieder gutgeschrieben)
    UPDATE material 
    SET lagerbestand = lagerbestand + (OLD.menge - NEW.menge) 
    WHERE material_id = NEW.material_id;
END //

DELIMITER ;

-- H. Kundenarchivierung bei Löschung
-- Kopiert Kundeninformationen unmittelbar vor dem Löschen in die Archivtabelle

DELIMITER //

DROP TRIGGER IF EXISTS tr_kunden_archivieren //

CREATE TRIGGER tr_kunden_archivieren
BEFORE DELETE ON kunden
FOR EACH ROW
BEGIN
    DECLARE v_display_name VARCHAR(255);
    DECLARE v_kontakt_info VARCHAR(255);

    IF OLD.typ = 'firma' THEN
        SELECT firmenname INTO v_display_name 
        FROM kunden_firma WHERE kunden_id = OLD.kunden_id LIMIT 1;
    ELSE
        SELECT CONCAT(vorname, ' ', nachname) INTO v_display_name 
        FROM kunden_person WHERE kunden_id = OLD.kunden_id LIMIT 1;
    END IF;

    SELECT wert INTO v_kontakt_info 
    FROM kunden_kontakt 
    WHERE kunden_id = OLD.kunden_id 
    ORDER BY FIELD(typ, 'Email', 'Telefon', 'Mobil') 
    LIMIT 1;

    INSERT INTO kunden_archiv (
        kunden_id, 
        archiv_name, 
        archiv_kontakt, 
        typ, 
        ist_stammkunde, 
        erstellt_am, 
        notizen,
        geloescht_am
    )
    VALUES (
        OLD.kunden_id, 
        IFNULL(v_display_name, 'Unbekannter Name'), 
        IFNULL(v_kontakt_info, 'Keine Kontaktinfo'), 
        OLD.typ, 
        OLD.ist_stammkunde, 
        OLD.erstellt_am, 
        OLD.notizen,
        NOW()
    );
END //

DELIMITER ;


-- --------------------------------------------------------------------------------------
-- 3. SQL-Script mit Transaktionen (Gekapselt in einer Procedure)
-- Hier wird das Prinzip der Atomarität (Alles-oder-Nichts) angewendet.

-- Zuerst das ENUM erweitern (einmalig)
ALTER TABLE auftrag 
MODIFY COLUMN status ENUM('neu', 'geplant', 'aktiv', 'abgeschlossen', 'abgerechnet', 'storniert') 
NOT NULL DEFAULT 'neu';

DELIMITER //

CREATE PROCEDURE sp_auftrag_stornieren(IN p_auftrag_id INT)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION 
    BEGIN 
        ROLLBACK;
        RESIGNAL; 
    END;

    START TRANSACTION;
        -- 1. Auftrag Status aktualisieren
        UPDATE auftrag SET status = 'storniert' WHERE auftrag_id = p_auftrag_id;
        
        -- 2. MANUELLES LAGER-UPDATE ENTFERNT.
        -- Stattdessen löschen wir die Materialzuweisungen.
        -- Dieser Vorgang löst den Trigger 'tr_lager_rueckgabe_delete' aus.
        DELETE FROM auftrag_material WHERE auftrag_id = p_auftrag_id;
    COMMIT;
END //

DELIMITER ;


-- --------------------------------------------------------------------------------------
-- 4. Performance & Check
-- Wir haben Indizes auf den Fremdschlüsseln (kunden_id, material_id). Mit EXPLAIN kannst du prüfen, wie MySQL die Prozeduren ausführt:

    -- 1. Optimierung für die Suche nach Auftragsstatus (z.B. für Dashboards)
    -- CREATE INDEX idx_auftrag_status ON auftrag(status);  (sprint 3 wurde hinzugefügt)

    -- 2. Optimierung für den 30-Tage-Check (Fälligkeitsdatum)
    CREATE INDEX idx_rechnung_datum ON rechnung(faellig_am, status);

    -- 3. Optimierung für die Materialprüfung (Bestandsabfragen)
    CREATE INDEX idx_mat_bestand ON material(lagerbestand);

    -- 4. Optimierung für die Historie (Status-Log nach Zeit sortieren)
    CREATE INDEX idx_log_zeit ON status_log(zeitpunkt);

    -- 5. Index für schnelle Suche über Referenz_id und Tabellenname
    CREATE INDEX idx_status_log_ref ON status_log(tabelle_name, referenz_id);

-- --------------------------------------------------------------------------------------
-- --------------------------------------------------------------------------------------
-- 5. Abnahmetest (Systemvalidierung & Szenarien)

-- VORBEREITUNG: Saubere Testdaten anlegen
-- Da Kunden nun auf mehrere Tabellen verteilt sind, legen wir einen Test-Privatkunden an.

INSERT INTO kunden (typ, ist_stammkunde, notizen) VALUES ('privat', 0, 'Testkunde für Abnahmetest');
SET @test_kunden_id = LAST_INSERT_ID();

INSERT INTO kunden_person (kunden_id, vorname, nachname) VALUES (@test_kunden_id, 'Abnahme', 'Tester');

-- Ein neues Test-Material anlegen (Sicherstellen, dass Bestand bekannt ist)
INSERT INTO material (name, beschreibung, einheit, lagerbestand, preis_pro_einheit) 
VALUES ('Test-Dichtung Sprint 4', 'Abnahmetest Material', 'Stück', 10, 5.50);
SET @test_material_id = LAST_INSERT_ID();

-- --------------------------------------------------------------------------------------
-- SZENARIO 1: Überprüfung des Lagerbestands (Negativtest)
-- Ziel: Trigger/Procedure muss Buchung über Bestand verhindern.
-- Erwartung: SQL-Fehler 'Nicht genügend Lagerbestand!'
-- CALL sp_material_zuordnen(NULL, @test_material_id, 50.00); 

-- --------------------------------------------------------------------------------------
-- SZENARIO 2: „Kettenreaktion“ (Auftrag -> Material -> Automatische Rechnung)
-- Ziel: Testet sp_neuer_auftrag, tr_auftrag_nummer_gen, sp_material_zuordnen und tr_auto_rechnung_erstellen.

-- A. Neuen Auftrag anlegen
CALL sp_neuer_auftrag(@test_kunden_id, 'System-Check Sprint 4', 'normal');
SET @test_auftrag_id = (SELECT auftrag_id FROM auftrag WHERE kunden_id = @test_kunden_id ORDER BY erstellt_am DESC LIMIT 1);

-- B. Material zuordnen (10 -> 7 - Trigger tr_lager_abbuchen)
CALL sp_material_zuordnen(@test_auftrag_id, @test_material_id, 3.00);

-- C. Auftrag abschließen (Erzeugt automatisch Rechnung - Trigger tr_auto_rechnung_erstellen)
CALL sp_auftrag_abschliessen(@test_auftrag_id);

-- VALIDIERUNG SZENARIO 2:
SELECT 'Szenario 2 - Bestand' AS Test, lagerbestand FROM material WHERE material_id = @test_material_id; -- Muss 7 sein
SELECT 'Szenario 2 - Rechnung' AS Test, status, rechnungs_datum FROM rechnung WHERE auftrag_id = @test_auftrag_id; -- Muss 'entwurf' sein

-- --------------------------------------------------------------------------------------
-- SZENARIO 3: Dublettenprüfung und fortlaufende Nummern
-- Ziel: Testet die Logik im Trigger tr_auftrag_nummer_gen, ob die Auftragsnummern korrekt generiert werden.

INSERT INTO auftrag (kunden_id, titel, status, prioritaet) 
VALUES (@test_kunden_id, 'Nummern-Check', 'neu', 'normal' );

CALL sp_neuer_auftrag(@test_kunden_id, 'fortlaufende Nummern-Check', 'normal');

-- VALIDIERUNG SZENARIO 3:
SELECT 'Szenario 3 - Auftrags-Nr' AS Test, auftrag_nr, auftrag_id FROM auftrag ORDER BY auftrag_id DESC LIMIT 1; 
-- Erwartung: Eine fortlaufende Nummer im Format '2026-XXXX', die um eins höher ist als die vorherige Höchstnummer.

-- --------------------------------------------------------------------------------------
-- SZENARIO 4: Kundenschutz (Referential Integrity)
-- Ziel: tr_kunden_loeschschutz muss Löschen verhindern, da @test_auftrag_id noch existiert.
-- Erwartung: SQL-Fehler 'Kunde hat noch offene Aufträge...'

-- DELETE FROM kunden WHERE kunden_id = @test_kunden_id; 
-- -- Allerdings wurde die Bestellung dieses Kunden bereits im vorangegangenen Schritt (Szenario 2) via sp_auftrag_abschliessen geschlossen.
-- -- Das Delete-Statement unten lässt sich mit einer Kunden-ID testen, die noch eine offene Aufträge hat.
-- DELETE FROM kunden WHERE kunden_id = 14; 
-- -- "14" ist ein Beispiel für eine Kunden-ID mit offenem Auftrag. Bitte anpassen!

-- --------------------------------------------------------------------------------------
-- SZENARIO 5: Mahnwesen-Automatik & Logging
-- Ziel: Testet sp_update_ueberfaellige_rechnungen und den Logging-Trigger tr_rechnung_status_log.

-- Wir manipulieren die eben erstellte Rechnung auf "überfällig" (35 Tage alt)
UPDATE rechnung 
SET faellig_am = DATE_SUB(CURDATE(), INTERVAL 35 DAY), status = 'gesendet' 
WHERE auftrag_id = @test_auftrag_id;

CALL sp_update_ueberfaellige_rechnungen();

-- VALIDIERUNG SZENARIO 5:
SELECT 'Szenario 5 - Mahnstatus' AS Test, status FROM rechnung WHERE auftrag_id = @test_auftrag_id; -- Muss 'überfällig' sein
SELECT 'Szenario 5 - Status Log' AS Test, alter_status, neuer_status, zeitpunkt 
FROM status_log 
WHERE referenz_id = (SELECT rechnung_id FROM rechnung WHERE auftrag_id = @test_auftrag_id);

-- -----------------------------------------------------------------------------------------
-- SZENARIO 6: Atomarität und Fehlerbehandlung (Rollback-Test)
-- Ziel: Nachweis, dass das System keine „halben Sachen“ macht (Datenkonsistenz), falls während einer Transaktion ein Fehler auftritt.
-- Ablauf: Die Prozedur sp_material_zuordnen wird mit einer nicht existierenden auftrag_id aufgerufen.
-- Erwartung: Das System muss eine Fehlermeldung ausgeben, und in der Lagerstabelle (Bestand) darf keine Änderung (Abzug) erfolgt sein.

SET @bestand_vorher = (SELECT lagerbestand FROM material WHERE material_id = @test_material_id);

-- Fehlerhafter Aufruf (eine Bestellung mit der ID: 999999 existiert wahrscheinlich nicht)
-- Dieser Vorgang muss fehlschlagen und einen ROLLBACK auslösen.
CALL sp_material_zuordnen(999999, @test_material_id, 1.00); 

-- Verifizierung: Der Lagerbestand darf sich nicht verändert haben
SELECT 'Szenario 6 - Rollback Kontrolle' AS Test, 
       (SELECT lagerbestand FROM material WHERE material_id = @test_material_id) = @bestand_vorher AS Ist_Erfolgreich;

-- ------------------------------------------------------------------------------------------
-- SZENARIO 7: Kontrolle von multiplen Materialien und dem Gesamtbetrag
-- Ziel: Überprüfung, ob der Rechnungsbetrag für mehrere Materialpositionen korrekt berechnet wird.
-- Szenario: Einem Auftrag werden zwei verschiedene Materialien zu unterschiedlichen Preisen hinzugefügt.
-- Erwartung: Der Rechnungsbetrag muss exakt der Formel (Menge1​×Preis1​)+(Menge2​×Preis2​) entsprechen.

--  1. Ein zweites Testmaterial wird erstellt (Preis: 10,00 €)
INSERT INTO material (name, einheit, lagerbestand, preis_pro_einheit) 
VALUES ('Test-kabel', 'Meter', 100, 10.00);
SET @test_material_id_2 = LAST_INSERT_ID();

-- 2. Eine neue Bestellung anlegen
CALL sp_neuer_auftrag(@test_kunden_id, 'Mehrfachmaterial Berechnungstest', 'normal');
SET @multi_auftrag_id = LAST_INSERT_ID();

-- 3. Zwei verschiedene Materialien zur Bestellung hinzufügen
-- Material 1: 2 Stück * 5,50 € = 11,00 €
CALL sp_material_zuordnen(@multi_auftrag_id, @test_material_id, 2.00); 

-- -- Material 2: 1 Stück * 10,00 € = 10,00 €
CALL sp_material_zuordnen(@multi_auftrag_id, @test_material_id_2, 1.00); 

-- 4. Bestellung abschließen (dieser Vorgang löst die Rechnungserstellung und die Preisberechnung aus)
CALL sp_auftrag_abschliessen(@multi_auftrag_id);

-- 5. VERIFIZIERUNG: Der Gesamtbetrag muss 21,00 € betragen (11 + 10)
SELECT 'Szenario 7 - Gesamtbetrag' AS Test, 
       gesamtbetrag, 
       (gesamtbetrag = 21.00) AS Ist_Berechnung_korrekt 
FROM rechnung 
WHERE auftrag_id = @multi_auftrag_id;

-- --------------------------------------------------------------------------------------------
-- SZENARIO 8: Rückgabe an den Lagerbestand nach Stornierung
-- Ziel: Sicherstellen, dass bei der Stornierung einer Bestellung alle Materialien vollständig in das Lager zurückgeführt werden.
-- Ablauf: Ein Material zuweisen, den Abzug vom Lagerbestand prüfen, danach die Bestellung stornieren.
-- Erwartung: Der lagerbestand in der Tabelle material muss auf den ursprünglichen Wert zurückgesetzt werden.

-- 1. Anfangsbestand abrufen
SET @anfangs_bestand = (SELECT lagerbestand FROM material WHERE material_id = @test_material_id);

-- 2. Vorgang ausführen (3 Stück verwenden)
CALL sp_neuer_auftrag(@test_kunden_id, 'Stornierungstest', 'niedrig');
SET @stornierungs_id = LAST_INSERT_ID();
CALL sp_material_zuordnen(@stornierungs_id, @test_material_id, 3.00);

-- 3. Stornieren
CALL sp_auftrag_stornieren(@stornierungs_id);

-- 4. Verifizierung: Entspricht der Bestand dem Anfangswert?
SELECT 'Szenario 8 - Lagerrückgabe' AS Test, 
       lagerbestand = @anfangs_bestand AS Ist_Rückgabe_Abgeschlossen
FROM material WHERE material_id = @test_material_id;

-- ----------------------------------------------------------------------------------------------
-- SZENARIO 9: Korrektheit der Archivierung
-- Ziel: Sicherstellen, dass ein gelöschter Kunde tatsächlich in die Tabelle kunden_archiv verschoben und aus der Haupttabelle entfernt wurde.
-- Ablauf: Einen Kunden löschen, der keine Bestellungen hat.
-- Erwartung: Der Kunde darf in der Tabelle kunden nicht mehr auffindbar sein, muss jedoch in der Tabelle kunden_archiv mit allen Informationen angezeigt werden.

-- 1. Einen neuen Kunden ohne Bestellungen anlegen
INSERT INTO kunden (typ, ist_stammkunde) VALUES ('privat', 0);
SET @loeschende_id = LAST_INSERT_ID();

-- 2. Den Kunden löschen
DELETE FROM kunden WHERE kunden_id = @loeschende_id;

-- 3. Verifizierung
SELECT 'Szenario 9 - Im Archiv?' AS Test, COUNT(*) FROM kunden_archiv WHERE kunden_id = @loeschende_id; -- Muss 1 ergeben
SELECT 'Szenario 9 - Aus der Haupttabelle gelöscht?' AS Test, COUNT(*) FROM kunden WHERE kunden_id = @loeschende_id; -- Muss 0 ergeben
