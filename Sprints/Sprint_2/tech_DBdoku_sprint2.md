# Technische Datenbankdokumentation: HandwerkerPro DB

---

## 1. Dokumentation der Datentypen

Bei der Gestaltung der Tabellen wurde besonderer Wert auf Datensicherheit und Präzision gelegt.

### Warum `DECIMAL` und nicht `FLOAT`?

In der `handwerkerpro_db` verwenden wir für alle finanziellen Beträge (Preise, Summen, MwSt) und Mengen den Datentyp **`DECIMAL(10,2)`** bzw.  **`DECIMAL(10,3)`** .

* **Präzision:** `FLOAT` und `DOUBLE` sind Gleitkommazahlen, die auf binärer Basis arbeiten. Dies führt bei kaufmännischen Berechnungen oft zu Rundungsfehlern (z. B. **$0.1 + 0.2 = 0.300000000004$**).
* **Exaktheit:** `DECIMAL` speichert Zahlen als exakte Festkommazahlen. Für ein Handwerker-System, in dem Rechnungen rechtssicher erstellt werden müssen, ist dies zwingend erforderlich, um Cent-Abweichungen zu vermeiden.
* **Mengen:** Bei `menge` nutzen wir `DECIMAL(10,3)`, damit auch kleinteilige Einheiten (wie 0,5 Stunden oder 1,255 kg Material) präzise abgebildet werden können.

### Weitere wichtige Datentypen

* **`ENUM`:** Wird für Statusfelder (z. B. `status`, `rolle`, `typ`) genutzt. Es stellt sicher, dass nur vordefinierte Werte in die Datenbank geschrieben werden (z. B. kann eine Rolle nicht "Chef" heißen, wenn nur "admin" oder "meister" erlaubt sind).
* **`BOOLEAN`:** Eigentlich ein `TINYINT(1)`. Ideal für Ja/Nein-Werte wie `ist_stammkunde` oder `aktiv`.
* **`DATETIME` vs. `DATE`:** `DATETIME` für Protokolle (Login, Erstellung), `DATE` für reine Kalendertage (Rechnungsdatum).

---

## 2. Erklärung der wichtigsten Constraints

Constraints (Einschränkungen) sichern die  **Datenintegrität** . Ohne sie würde die Datenbank schnell inkonsistent ("Datenmüll").

* **`PRIMARY KEY` (Primärschlüssel):** Identifiziert jeden Datensatz eindeutig (z. B. `kunden_id`). Er verhindert Dubletten.
* **`FOREIGN KEY` (Fremdschlüssel):** Verknüpft Tabellen (z. B. ein Auftrag gehört zu einer `kunden_id`).
* **`ON DELETE CASCADE`:** Wenn ein Kunde gelöscht wird, werden automatisch auch seine Adressen und Kontakte gelöscht. Das verhindert "Waisen-Datensätze".
* **`ON DELETE SET NULL`:** Wenn ein Mitarbeiter gelöscht wird, bleibt das `login_log` bestehen, aber das Feld `mitarbeiter_id` wird auf `NULL` gesetzt (historische Daten bleiben erhalten).
* **`CHECK` Constraints:** Dies sind Logik-Wächter.
  * `CHECK (netto_summe >= 0)`: Verhindert negative Preise.
  * `CHECK (end_datetime > start_datetime)`: Stellt sicher, dass ein Termin nicht endet, bevor er begonnen hat.
* **`UNIQUE`:** Garantiert, dass Werte wie `email` oder `auftrag_nr` nur einmal im gesamten System vorkommen dürfen.

---

## 3. Anleitung zur Installation der Datenbank

Um die Datenbank mit den Testdaten aufzusetzen, folge diesen Schritten:

### Voraussetzung

* Ein installierter MySQL-Server (z. B. via XAMPP, MariaDB oder Docker).
* Ein Datenbank-Client (MySQL Workbench, phpMyAdmin, DBeaver oder die Konsole).

### Schritt-für-Schritt-Installation

1. **Script vorbereiten:** Kopiere das create_db Script und das test_data Script in eine `.sql` Datei oder direkt in den SQL-Editor deines Clients.
2. **Ausführung:**
   * Führe zuerst den Block mit `DROP DATABASE` und `CREATE TABLE` aus, um die Struktur zu erstellen. 	( create_db.sql )
   * Führe danach das Script mit den `INSERT INTO` Befehlen aus. 	( test_data.sql )
3. **Verbindung prüfen (Optional via Konsole):**

```
mysql -u dein_benutzer -p
USE handwerkerpro_db;
SHOW TABLES;
SELECT * FROM mitarbeiter;
```

* **Erfolgskontrolle:** Wenn du `SELECT * FROM auftrag;` ausführst, solltest du 10 Einträge mit verschiedenen Status-Werten sehen.
