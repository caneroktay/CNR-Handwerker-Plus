USE handwerkerpro_db;

-- ------------------------------------------------------------------------------
-- ZUSÄTZLICHE TESTDATEN FÜR DAS REPORTING (Mind. 5 Zeilen pro View) ------------
-- ------------------------------------------------------------------------------

-- 1. WEITERE KUNDEN (Für Warteliste und Top-Kunden Reporting)
INSERT INTO kunden (typ, ist_stammkunde, notizen) VALUES
('privat', FALSE, 'Wartet auf Rückruf bezüglich Bad'),
('firma', TRUE, 'Dauerhafter Wartungsvertrag für Bürokomplex'),
('privat', TRUE, 'Stammkunde seit 2018'),
('firma', FALSE, 'Neuanfrage Industrie-Heizung');

INSERT INTO kunden_person (kunden_id, vorname, nachname) VALUES
(6, 'Dieter', 'Bohlen'),
(8, 'Heidi', 'Klum');

INSERT INTO kunden_firma (kunden_id, firmenname, ansprechpartner, ust_id) VALUES
(7, 'Bauhaus AG', 'Herr Hammer', 'DE555666777'),
(9, 'Global Logistics', 'Frau Cargo', 'DE999888777');


-- 2. WEITERE AUFTRÄGE (Für die "Wartende Kunden" View - Status 'neu')
-- Die Erstellungsdaten liegen in der Vergangenheit, um Wartezeit zu simulieren.
INSERT INTO auftrag (kunden_id, auftrag_nr, titel, status, erstellt_am, prioritaet) VALUES
(6, 'AUF-011', 'Leitung verstopft Küche', 'neu', '2026-02-10 08:00:00', 'normal'),
(7, 'AUF-012', 'Industrie-Wartung Q1', 'neu', '2026-02-15 10:30:00', 'dringend'),
(8, 'AUF-013', 'Heizkörper Montage OG', 'neu', '2026-02-20 09:00:00', 'niedrig'),
(9, 'AUF-014', 'Lagerhalle Siphon defekt', 'neu', '2026-02-25 14:00:00', 'normal'),
(4, 'AUF-015', 'Badplanung Neubau', 'neu', '2026-02-28 11:00:00', 'normal');


-- 3. WEITERE TERMINE (Für Mitarbeiter-Auslastung und Tagesplan)
-- Termine für die aktuelle Woche (März 2026)
INSERT INTO termin (auftrag_id, mitarbeiter_id, start_datetime, end_datetime, status) VALUES
(11, 1, '2026-03-02 11:00:00', '2026-03-02 14:00:00', 'geplant'), -- Max heute
(12, 2, '2026-03-02 09:00:00', '2026-03-02 17:00:00', 'geplant'), -- Lena heute
(13, 3, '2026-03-03 08:00:00', '2026-03-03 12:00:00', 'geplant'), -- Klaus morgen
(14, 1, '2026-03-04 10:00:00', '2026-03-04 15:00:00', 'geplant'),
(15, 2, '2026-03-05 13:00:00', '2026-03-05 16:00:00', 'geplant');


-- 4. WEITERER MATERIALBEDARF (Für die Wochenplanung View)
INSERT INTO auftrag_material (auftrag_id, material_id, menge, bestell_status) VALUES
(11, 1, 10.0, 'Bestellt'), -- Kupferrohr für Auftrag 11
(12, 4, 1.0, 'Bestellt'),  -- Heizungspumpe für Auftrag 12
(13, 2, 2.0, 'Bestellt'),  -- Mischbatterie für Auftrag 13
(14, 5, 3.0, 'Bestellt'),  -- Siphon
(15, 3, 5.0, 'Bestellt');  -- Dichtungssatz


-- 5. WEITERE RECHNUNGEN (Für Umsatz-Reporting und Verzugsliste)
-- Teilweise überfällig, teilweise aktuell gesendet
INSERT INTO rechnung (auftrag_id, kunden_id, rechnungs_datum, faellig_am, status) VALUES
(3, 2, '2026-02-10', '2026-02-24', 'überfällig'),
(4, 3, '2026-02-15', '2026-02-28', 'überfällig'),
(5, 3, '2026-03-01', '2026-03-15', 'gesendet'),
(7, 5, '2026-03-01', '2026-03-15', 'gesendet');


-- 6. WEITERE RECHNUNGSPOSITIONEN (Damit die Umsatz-Berechnungen Werte liefern)
-- Verknüpfung der neuen Rechnungen mit Leistungen (Auftragspositionen)
INSERT INTO rechnung_position (rechnung_id, position_id, menge, einzelpreis_bei_rechnung, mwst_satz) VALUES
(5, 7, 1.0, 150.00, 19.00), -- Rechnung ID 5 (Prüfung Kältemittel)
(6, 4, 5.0, 75.00, 19.00),  -- Rechnung ID 6 (Montage Sanitärobjekte)
(7, 3, 1.0, 30.00, 19.00),  -- Rechnung ID 7 (Anfahrtspauschale)
(8, 8, 2.0, 15.00, 19.00),  -- Rechnung ID 8 (Kleinteilpauschale)
(9, 1, 1.0, 95.00, 19.00);  -- Rechnung ID 9 (Notdienst Einsatz)