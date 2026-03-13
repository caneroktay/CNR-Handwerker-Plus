DROP DATABASE IF EXISTS handwerkerpro_db;
CREATE DATABASE IF NOT EXISTS handwerkerpro_db 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE handwerkerpro_db;

-- 1. Kunden Basis-Tabelle - Speichert grundlegende Kundeninformationen.
CREATE TABLE kunden (
    kunden_id INT PRIMARY KEY AUTO_INCREMENT,
    typ ENUM('privat', 'firma') NOT NULL,
    ist_stammkunde BOOLEAN DEFAULT FALSE,
    erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
    notizen TEXT
);

-- 2. Spezialisierung: Privatpersonen 
-- Speichert personenbezogene Daten für Privatkunden.
CREATE TABLE kunden_person (
    kunden_id INT PRIMARY KEY,
    vorname VARCHAR(100) NOT NULL,
    nachname VARCHAR(100) NOT NULL,
    FOREIGN KEY (kunden_id) REFERENCES kunden(kunden_id) ON DELETE CASCADE
);

-- 3. Spezialisierung: Firmen 
-- Speichert Firmendaten.
CREATE TABLE kunden_firma (
    kunden_id INT PRIMARY KEY,
    firmenname VARCHAR(120) NOT NULL,
    ansprechpartner VARCHAR(120),
    ust_id VARCHAR(20),
    FOREIGN KEY (kunden_id) REFERENCES kunden(kunden_id) ON DELETE CASCADE
);

-- 4. Adressen - Speichert mehrere Adressen pro Kunde.
-- Ein Kunde kann mehrere Adressen haben (z.B. Rechnungsadresse, Einsatzadresse, Lieferadresse).
CREATE TABLE kunden_adressen (
    adress_id INT PRIMARY KEY AUTO_INCREMENT,
    kunden_id INT NOT NULL,
    strasse VARCHAR(120) NOT NULL,
    hausnummer VARCHAR(10),
    plz VARCHAR(10) NOT NULL,
    ort VARCHAR(100) NOT NULL,
    typ ENUM('rechnung', 'einsaetze', 'lieferadresse') NOT NULL,
    FOREIGN KEY (kunden_id) REFERENCES kunden(kunden_id) ON DELETE CASCADE
);

-- 5. Kontaktinformationen (Typ als ENUM für Konsistenz)
-- Ein Kunde kann mehrere Kontaktinfo haben (z.B. Telefon Nummer, E-Mail, Whatsapp Business).
CREATE TABLE kunden_kontakt (
    kontakt_id INT PRIMARY KEY AUTO_INCREMENT,
    kunden_id INT NOT NULL,
    typ ENUM('Telefon', 'Email', 'Mobil', 'Whatsapp Business') NOT NULL,
    wert VARCHAR(255) NOT NULL,
    FOREIGN KEY (kunden_id) REFERENCES kunden(kunden_id) ON DELETE CASCADE
);

-- 6. Mitarbeiter
-- Speichert Benutzer des Systems.
CREATE TABLE mitarbeiter (
    mitarbeiter_id INT PRIMARY KEY AUTO_INCREMENT,
    vorname VARCHAR(100) NOT NULL,
    nachname VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    rolle ENUM('admin', 'meister', 'geselle', 'azubi', 'buero') DEFAULT 'geselle',
    aktiv BOOLEAN DEFAULT TRUE,
    letzte_login DATETIME
);

-- 7. Login Logs
-- Speichert Login-Versuche der Mitarbeiter für Sicherheits- und Audit-Zwecke.
CREATE TABLE login_log (
    log_id INT PRIMARY KEY AUTO_INCREMENT,
    mitarbeiter_id INT,
    ip_adresse VARCHAR(45),
    user_agent TEXT,
    erfolgreich BOOLEAN,
    eingeloggt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter(mitarbeiter_id) ON DELETE SET NULL
);

-- 8. Angebote
-- Speichert Angebote, die an Kunden gesendet werden. 
-- Ein Angebot kann später in einen Auftrag umgewandelt werden.
CREATE TABLE angebot (
    angebot_id INT PRIMARY KEY AUTO_INCREMENT,
    kunden_id INT NOT NULL,
    status ENUM('entwurf', 'gesendet', 'angenommen', 'abgelehnt') DEFAULT 'entwurf',
    netto_summe DECIMAL(10,2) CHECK (netto_summe >= 0),
    erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kunden_id) REFERENCES kunden(kunden_id)
);

-- 9. Aufträge
-- Speichert Aufträge, die aus Angeboten entstehen oder direkt erstellt werden können.
CREATE TABLE auftrag (
    auftrag_id INT AUTO_INCREMENT PRIMARY KEY,
    kunden_id INT NOT NULL,
    angebot_id INT NULL,
    auftrag_nr VARCHAR(20) UNIQUE NOT NULL,
    titel VARCHAR(150) NOT NULL,
    beschreibung TEXT,
    prioritaet ENUM('niedrig', 'normal', 'dringend', 'notfall') DEFAULT 'normal',
    status ENUM('neu', 'geplant', 'aktiv', 'abgeschlossen', 'abgerechnet') DEFAULT 'neu',
    erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    notiz_intern TEXT,
    kunden_kommentar TEXT,
    FOREIGN KEY (kunden_id) REFERENCES kunden(kunden_id),
    FOREIGN KEY (angebot_id) REFERENCES angebot(angebot_id) ON DELETE SET NULL
);

-- 10. Auftragspositionen (Arbeit/Material/Pauschale)
-- Speichert die einzelnen Positionen eines Auftrags, z.B. welche Arbeiten ausgeführt werden, welche Materialien verbraucht werden oder welche Pauschalen berechnet werden.
CREATE TABLE auftrag_position (
    position_id INT PRIMARY KEY AUTO_INCREMENT,
    auftrag_id INT NOT NULL,
    typ ENUM('arbeit', 'material', 'pauschale') NOT NULL,
    bezeichnung VARCHAR(255) NOT NULL,
    menge DECIMAL(10,3) DEFAULT 1.000 CHECK (menge > 0),                    -- Menge muss größer als 0 sein
    mwst_satz DECIMAL(4,2) DEFAULT 19.00 CHECK (mwst_satz >= 0),
    einzelpreis_bei_bestellung DECIMAL(10,2) NOT NULL CHECK (einzelpreis_bei_bestellung >= 0),  -- Hier erlauben wir >= 0 (falls mal etwas kostenlos ist)
    FOREIGN KEY (auftrag_id) REFERENCES auftrag(auftrag_id) ON DELETE CASCADE
);

-- 11. Termine
-- Speichert Termine, die mit Aufträgen verbunden sind, 
-- z.B. für die Planung von Einsätzen oder Meetings.
CREATE TABLE termin (
    termin_id INT PRIMARY KEY AUTO_INCREMENT,
    auftrag_id INT NOT NULL,
    mitarbeiter_id INT NOT NULL,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    status ENUM('geplant', 'abgeschlossen', 'abgesagt') DEFAULT 'geplant',
    CHECK (end_datetime > start_datetime),
    FOREIGN KEY (auftrag_id) REFERENCES auftrag(auftrag_id) ON DELETE CASCADE,
    FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter(mitarbeiter_id) ON DELETE CASCADE
);

-- 12. Rechnungen
-- Speichert Rechnungen, die aus Aufträgen generiert werden. 
-- Ein Auftrag kann i.d.R. nur eine Abschlussrechnung haben, 
-- aber es könnte auch Teilrechnungen geben (daher UNIQUE auf auftrag_id).
CREATE TABLE rechnung (
    rechnung_id INT PRIMARY KEY AUTO_INCREMENT,
    auftrag_id INT UNIQUE,                      -- Ein Auftrag hat i.d.R. eine Abschlussrechnung
    kunden_id INT NOT NULL,
    rechnungs_datum DATE NOT NULL,
    faellig_am DATE NOT NULL,
    status ENUM('entwurf', 'gesendet', 'bezahlt', 'überfällig') DEFAULT 'entwurf',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (auftrag_id) REFERENCES auftrag(auftrag_id),
    FOREIGN KEY (kunden_id) REFERENCES kunden(kunden_id)
);

-- 13. Rechnungspositionen Speichert die Positionen einer Rechnung, 
-- die sich auf die Auftragspositionen beziehen können. 
-- Hier werden die tatsächlich berechneten Mengen und 
-- Preise zum Zeitpunkt der Rechnungsstellung festgehalten.
CREATE TABLE rechnung_position (
    rpos_id INT PRIMARY KEY AUTO_INCREMENT,
    rechnung_id INT NOT NULL,
    position_id INT,                                                -- Referenz zur Auftragsposition
    menge DECIMAL(10,3) NOT NULL CHECK (menge > 0),                 -- Menge muss größer als 0 sein
    einzelpreis_bei_rechnung DECIMAL(10,2) NOT NULL,
    mwst_satz DECIMAL(4,2),
    FOREIGN KEY (rechnung_id) REFERENCES rechnung(rechnung_id) ON DELETE CASCADE,
    FOREIGN KEY (position_id) REFERENCES auftrag_position(position_id) ON DELETE SET NULL
);

-- 14. Stammdaten Material
-- Speichert Informationen über Materialien, 
-- die in Aufträgen verwendet werden können.
CREATE TABLE material (
    material_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    beschreibung TEXT,
    einheit VARCHAR(20) DEFAULT 'Stück',
    lagerbestand INT DEFAULT 0 CHECK (lagerbestand >= 0),                           -- Lagerbestand kann 0 sein, aber nicht negativ
    preis_pro_einheit DECIMAL(10, 2) NOT NULL CHECK (preis_pro_einheit > 0)         -- Ein Materialpreis darf nicht 0 oder negativ sein
);

-- 15. Materialzuordnung zu Aufträgen (Logistik)
-- Diese Tabelle verbindet Aufträge mit Materialien, 
-- die für die Ausführung benötigt werden.
CREATE TABLE auftrag_material (
    auftrag_material_id INT PRIMARY KEY AUTO_INCREMENT,
    auftrag_id INT NOT NULL,
    material_id INT NOT NULL,
    menge DECIMAL(10,3) NOT NULL CHECK (menge > 0),                                 -- Menge muss größer als 0 sein
    bestell_status ENUM('Bestellt', 'Geliefert', 'Verwendet') DEFAULT 'Bestellt',
    FOREIGN KEY (auftrag_id) REFERENCES auftrag(auftrag_id) ON DELETE CASCADE,
    FOREIGN KEY (material_id) REFERENCES material(material_id) ON DELETE CASCADE
);

