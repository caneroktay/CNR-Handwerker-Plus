USE handwerkerpro_db;


-- -- ------------------------------------------------------------------------------
-- -- Die folgenden 'ADD COLUMN'-Abfragen wurden bereits während der Erstellungsphase in die create_db.sql eingefügt. 
-- -- Sie können diese entweder hier manuell ausführen oder 
-- -- diese Zeilen auskommentiert lassen und die create_db_2.sql erneut ausführen.
-- ALTER TABLE rechnung ADD COLUMN faellig_am DATE AFTER rechnungs_datum;
-- ALTER TABLE rechnung ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
-- ALTER TABLE rechnung ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
-- --          auftrag ADD COLUMN created_at wurde nicht hinzugefügt, da 'erstellt_am' bereits vorhanden ist.
-- ALTER TABLE auftrag ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
-- -- -----------------------------------------------------------------------------

-- 1. MITARBEITER
INSERT INTO mitarbeiter (vorname, nachname, email, password_hash, rolle) VALUES
('Max', 'Mustermann', 'm.mustermann@handwerker.de', '$2y$10$abcdefgh...', 'admin'),
('Lena', 'Zimmer', 'l.zimmer@handwerker.de', '$2y$10$ijklmnop...', 'meister'),
('Klaus', 'Bauer', 'k.bauer@handwerker.de', '$2y$10$qrstuvwx...', 'geselle');

-- 2. LOGIN_LOG
INSERT INTO login_log (mitarbeiter_id, ip_adresse, user_agent, erfolgreich) VALUES
(1, '192.168.1.10', 'Chrome/Windows', TRUE),
(2, '192.168.1.11', 'Safari/iOS', TRUE),
(1, '192.168.1.10', 'Chrome/Windows', TRUE),
(3, '10.0.0.5', 'Firefox/Linux', FALSE),
(3, '10.0.0.5', 'Firefox/Linux', TRUE);

-- 3. KUNDEN
INSERT INTO kunden (typ, ist_stammkunde, notizen) VALUES
('privat', TRUE, 'Stammkunde seit 2020'),
('privat', FALSE, 'Neukunde über Empfehlung'),
('firma', TRUE, 'Großkunde Metallbau'),
('privat', FALSE, NULL),
('firma', FALSE, 'Bürokomplex Wartungsvertrag');

-- 4. KUNDEN_PERSON
INSERT INTO kunden_person (kunden_id, vorname, nachname) VALUES
(1, 'Thomas', 'Müller'),
(2, 'Sabine', 'Schmidt'),
(4, 'Peter', 'Lustig');

-- 5. KUNDEN_FIRMA
INSERT INTO kunden_firma (kunden_id, firmenname, ansprechpartner, ust_id) VALUES
(3, 'Berger Bau GmbH', 'Frau Meyer', 'DE123456789'),
(5, 'Tech-Logistik AG', 'Herr Wagner', 'DE987654321');

-- 6. KUNDEN_ADRESSEN
INSERT INTO kunden_adressen (kunden_id, strasse, hausnummer, plz, ort, typ) VALUES
(1, 'Hauptstraße', '12', '10115', 'Berlin', 'rechnung'),
(1, 'Nebenweg', '1', '10115', 'Berlin', 'einsaetze'),
(2, 'Gartenweg', '5', '20095', 'Hamburg', 'einsaetze'),
(3, 'Industriepark', 'A4', '80331', 'München', 'rechnung'),
(5, 'Logistikallee', '100', '50667', 'Köln', 'lieferadresse'),
(4, 'Schlossplatz', '7', '70173', 'Stuttgart', 'rechnung');

-- 7. KUNDEN_KONTAKT
INSERT INTO kunden_kontakt (kunden_id, typ, wert) VALUES
(1, 'Mobil', '0170-1234567'),
(1, 'Email', 't.mueller@web.de'),
(3, 'Email', 'info@berger-bau.de'),
(3, 'Telefon', '089-987654'),
(5, 'Whatsapp Business', '0221-554433');

-- 8. MATERIAL
INSERT INTO material (name, beschreibung, einheit, lagerbestand, preis_pro_einheit) VALUES
('Kupferrohr 15mm', 'Wasserleitung Standard', 'Meter', 100, 12.50),
('Mischbatterie', 'Einhandmischer Bad', 'Stück', 15, 89.00),
('Dichtungssatz', 'Diverse Größen', 'Set', 50, 9.90),
('Heizungspumpe', 'Grundfos Hocheffizienz', 'Stück', 4, 245.00),
('Siphon flexibel', 'Standardanschluss Küche', 'Stück', 20, 14.50);

-- 9. ANGEBOT
INSERT INTO angebot (kunden_id, status, netto_summe) VALUES
(1, 'angenommen', 450.00),
(3, 'gesendet', 2400.00),
(5, 'entwurf', 150.00),
(2, 'abgelehnt', 890.00),
(1, 'angenommen', 320.00);

-- 10. AUFTRAG
INSERT INTO auftrag (kunden_id, angebot_id, auftrag_nr, titel, status, prioritaet) VALUES
(1, 1, 'AUF-001', 'Rohrbruch Reparatur', 'abgeschlossen', 'notfall'),
(1, 5, 'AUF-002', 'Wartung Wasserhahn', 'abgerechnet', 'normal'),
(2, NULL, 'AUF-003', 'Heizungsausfall', 'geplant', 'dringend'),
(3, 2, 'AUF-004', 'Bad-Sanierung Etage 1', 'aktiv', 'normal'),
(3, NULL, 'AUF-005', 'Kleinstreparatur Siphon', 'neu', 'niedrig'),
(4, NULL, 'AUF-006', 'Verstopfung Notdienst', 'aktiv', 'notfall'),
(5, NULL, 'AUF-007', 'Vorbereitung Lager', 'neu', 'normal'),
(1, NULL, 'AUF-008', 'Gartenleitung legen', 'geplant', 'normal'),
(2, NULL, 'AUF-009', 'Armaturentausch', 'abgeschlossen', 'normal'),
(3, NULL, 'AUF-010', 'Check Klimaanlage', 'geplant', 'normal');

-- 11. AUFTRAG_POSITION
INSERT INTO auftrag_position (auftrag_id, typ, bezeichnung, menge, einzelpreis_bei_bestellung) VALUES
(1, 'arbeit', 'Notdienst Einsatz', 2.0, 95.00),         -- ID: 1
(1, 'material', 'Kupferrohr 15mm', 3.0, 12.50),         -- ID: 2
(2, 'pauschale', 'Anfahrtspauschale', 1.0, 25.00),      -- ID: 3
(4, 'arbeit', 'Montage Sanitärobjekte', 10.0, 75.00),   -- ID: 4
(6, 'arbeit', 'Rohrreinigung Spezial', 1.5, 120.00),    -- ID: 5
(9, 'material', 'Mischbatterie Bad', 1.0, 89.00),       -- ID: 6
(10, 'arbeit', 'Prüfung Kältemittel', 1.0, 150.00),     -- ID: 7
(10, 'pauschale', 'Kleinteilpauschale', 1.0, 15.00);    -- ID: 8

-- 12. AUFTRAG_MATERIAL
INSERT INTO auftrag_material (auftrag_id, material_id, menge, bestell_status) VALUES
(1, 1, 3.0, 'Verwendet'),
(4, 4, 2.0, 'Bestellt'),
(9, 2, 1.0, 'Geliefert'),
(5, 5, 1.0, 'Bestellt'),
(1, 3, 2.0, 'Verwendet'),
(6, 3, 1.0, 'Verwendet');

-- 13. TERMINE
INSERT INTO termin (auftrag_id, mitarbeiter_id, start_datetime, end_datetime, status) VALUES
(3, 2, DATE_ADD(NOW(), INTERVAL 1 DAY), DATE_ADD(DATE_ADD(NOW(), INTERVAL 1 DAY), INTERVAL 2 HOUR), 'geplant'),
(4, 3, DATE_ADD(NOW(), INTERVAL 3 DAY), DATE_ADD(DATE_ADD(NOW(), INTERVAL 3 DAY), INTERVAL 4 HOUR), 'geplant'),
(8, 3, DATE_ADD(NOW(), INTERVAL 7 DAY), DATE_ADD(DATE_ADD(NOW(), INTERVAL 7 DAY), INTERVAL 3 HOUR), 'geplant'),
(10, 1, DATE_ADD(NOW(), INTERVAL 10 DAY), DATE_ADD(DATE_ADD(NOW(), INTERVAL 10 DAY), INTERVAL 1 HOUR), 'geplant'),
(6, 2, DATE_ADD(NOW(), INTERVAL 2 HOUR), DATE_ADD(NOW(), INTERVAL 4 HOUR), 'geplant'),
(7, 1, DATE_ADD(NOW(), INTERVAL 12 DAY), DATE_ADD(DATE_ADD(NOW(), INTERVAL 12 DAY), INTERVAL 5 HOUR), 'geplant');

-- 14. RECHNUNG (Inklusive faellig_am)
INSERT INTO rechnung (auftrag_id, kunden_id, rechnungs_datum, faellig_am, status) VALUES
(2, 1, '2024-02-15', '2024-03-01', 'bezahlt'),
(1, 1, '2024-02-20', '2024-03-05', 'gesendet'),
(9, 2, '2024-02-22', '2024-03-07', 'entwurf'),
(6, 4, '2024-02-24', '2024-03-01', 'überfällig'), -- Veraltetes Fälligkeitsdatum für Status 'überfällig'
(10, 3, '2024-02-25', '2024-03-11', 'entwurf');

-- 15. RECHNUNG_POSITION (Korrekturen angewendet)
-- Rechnung ID 1 (Auftrag 2 - Wartung) -> Muss Position ID 3 (Anfahrt) enthalten
-- Rechnung ID 2 (Auftrag 1 - Rohrbruch) -> Muss Position ID 1 & 2 enthalten
INSERT INTO rechnung_position (rechnung_id, position_id, menge, einzelpreis_bei_rechnung, mwst_satz) VALUES
(1, 3, 1.0, 25.00, 19.00), -- Korrigiert: Rechnung 1 (Auftrag 2) zeigt nun auf Anfahrtspauschale
(2, 1, 2.0, 95.00, 19.00), -- Korrigiert: Rechnung 2 (Auftrag 1) zeigt nun auf Notdienst
(2, 2, 3.0, 12.50, 19.00), -- Korrigiert: Rechnung 2 (Auftrag 1) zeigt nun auf Kupferrohr
(3, 6, 1.0, 89.00, 19.00), 
(4, 5, 1.5, 120.00, 19.00);