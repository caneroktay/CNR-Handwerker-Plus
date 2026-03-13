# Automatisierung & Integrität

In diesem Sprint wurde die Datenbank von einem reinen Datenspeicher zu einem **aktiven Assistenzsystem** aufgewertet. Durch den Einsatz von Triggern, Stored Procedures und Transaktionen werden menschliche Fehler minimiert und Routineaufgaben automatisiert.

---

## 1. Stored Procedures (Geschäftslogik-Motoren)

Diese Prozeduren kapseln komplexe Abläufe in einfache Befehle und validieren Eingaben.

### A. `sp_neuer_auftrag`

* **Funktion:** Erstellt einen neuen Auftrag.
* **Besonderheit:** Prüft, ob eine `kunden_id` vorhanden ist, bevor gespeichert wird.
* **Aufruf:** `CALL sp_neuer_auftrag(KundenID, 'Titel', 'Priorität');`

### B. `sp_material_zuordnen`

* **Funktion:** Verknüpft Material mit einem Auftrag.
* **Sicherheitscheck:** Prüft vorab, ob die Menge positiv ist und ob genug Lagerbestand vorhanden ist.
* **Aufruf:** `CALL sp_material_zuordnen(AuftragID, MaterialID, Menge);`

### C. `sp_rechnung_bezahlen`

* **Funktion:** Markiert eine Rechnung als bezahlt und aktualisiert den Zeitstempel.

### D. `sp_auftrag_abschliessen`

* **Funktion:** Setzt den Status auf "abgeschlossen" und trägt das aktuelle Datum in `abgeschlossen_am` ein.
* **Automatisierung:** Löst indirekt die Rechnungserstellung aus (siehe Trigger).

### E. `sp_material_nachbestellen`

* **Funktion:** Erhöht den Lagerbestand eines bestimmten Materials.
* **Sicherheitscheck:** Stellt sicher, dass keine negativen Mengen "nachbestellt" werden können (Validierung).
* **Anwendungsfall:** Wareneingang vom Lieferanten.
* **Aufruf:** `CALL sp_material_nachbestellen(MaterialID, Menge);`

### F. `sp_update_ueberfaellige_rechnungen`

* **Funktion:** Prüft alle offenen Rechnungen. Wenn das Fälligkeitsdatum mehr als 30 Tage zurückliegt, wird der Status automatisch auf "überfällig" gesetzt.

---

## 2. Trigger (Automatische Hintergrundaktionen)

Trigger reagieren in Echtzeit auf Änderungen in der Datenbank, ohne dass ein Benutzer eingreifen muss.

|    | **Trigger-Name**                             | **Ereignis**                                  | **Aktion**                                                                                                    |
| :-: | -------------------------------------------------- | --------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| A. | `tr_auftrag_nummer_gen`                          | BEFORE INSERT (Auftrag)                             | Erzeugt automatisch eine Nummer wie `2026-001`.                                                                   |
| B. | `tr_lager_abbuchen`                              | AFTER INSERT (Materialzuordnung)                    | Reduziert sofort den Lagerbestand um die verbrauchte Menge.                                                         |
| C. | `tr_kunden_loeschschutz`                         | BEFORE DELETE (Kunden)                              | Blockiert das Löschen, wenn der Kunde noch aktive (nicht abgeschlossene) Aufträge hat.                            |
| D. | `tr_rechnung_status_log & tr_auftrag_status_log` | AFTER UPDATE (Rechnung)<br />AFTER UPDATE (Auftrag) | Schreibt jede Statusänderung einer Rechnung und Auftrag in die `status_log` Tabelle.                             |
| E. | `tr_auto_rechnung_erstellen`                     | AFTER UPDATE (Auftrag)                              | Erstellt sofort eine neue Rechnung, wenn ein Auftrag auf "abgeschlossen" gesetzt wird.                              |
| F. | `tr_lager_rueckgabe_delete`                      | AFTER DELETE (Auftrag Material)                     | Wenn ein Material aus einem Auftrag entfernt wird, wird der Lagerbestand automatisch wieder erhöht.              |
| G. | `tr_lager_anpassung_update`                      | AFTER UPDATE (Auftrag Material)                     | Wenn die Materialmenge in einem Auftrag geändert wird, wird die Differenz automatisch im Lagerbestand korrigiert. |
| H. | `tr_kunden_archivieren`                          | BEFORE DELETE (Kunden)                              | Kopiert Kundeninformationen unmittelbar vor dem Löschen in die Archivtabelle.                                      |

---

## 3. Transaktionen (Datensicherheit)

Kritische Operationen werden durch Transaktionen geschützt. Dies garantiert, dass bei einem Fehler alle Teilschritte rückgängig gemacht werden ( **Rollback** ).

**Beispiel: Auftrag stornieren**

1. Status wird auf "storniert" gesetzt.
2. Zugeordnetes Material wird dem Lager wieder gutgeschrieben.

* *Sicherheit:* Schlägt die Materialrückbuchung fehl, wird auch die Statusänderung nicht gespeichert (Datenkonsistenz).

---

## 4. Performance-Optimierung (Indizes)

Um die neuen Automatisierungen schnell zu halten, wurden folgende Indizes im SQL-Dump integriert:

* `idx_auftrag_status`: Beschleunigt die Suche nach abgeschlossenen/offenen Projekten. (sprint 3 wurde hinzugefügt)
* `idx_rechnung_datum`: Optimiert den 30-Tage-Überfälligkeitscheck.
* `idx_mat_bestand`: Hilft dem System, schnell zu prüfen, ob Material vorhanden ist.
* **`idx_log_zeit`** : Optimiert Abfragen auf der `status_log`-Tabelle basierend auf dem Zeitstempel.

---

## 5. Abnahmetest (Beispielszenarien)

### **Test-Szenarien für die Systemvalidierung**

Ein stabiles Datenbanksystem muss nicht nur unter Normalbedingungen funktionieren, sondern auch in Grenzfällen (Edge Cases) die Datenintegrität wahren. Die folgenden Szenarien dienen der Verifizierung unserer Fehlerbehandlung und Automatisierung.

#### **Szenario 1: Überprüfung des Lagerbestands (Negativtest)**

Ziel ist es, sicherzustellen, dass keine Materialien zugeordnet werden können, die nicht physisch im Lager vorhanden sind.

* **Vorgang:** Auswahl eines Materials mit einem Bestand von z.B. 5 Einheiten.
* **Abfrage:** `CALL sp_material_zuordnen(1, [id], 10);`
* **Erwartetes Ergebnis:** `SIGNAL SQLSTATE` muss ausgelöst werden mit der Meldung:  *"Nicht genügend Lagerbestand!"* . In der Tabelle `auftrag_material` darf **kein neuer Datensatz** erscheinen (Rollback-Nachweis).

#### **Szenario 2: „Kettenreaktion“ und Fehlerbehandlung**

Dieser Test prüft das Zusammenspiel zwischen Transaktionen in Stored Procedures und Triggern.

* **Vorbereitung:** Provisorisches Hinzufügen einer Pflichtspalte (`NOT NULL`) zur Tabelle `rechnung`, die vom Trigger nicht bedient wird.
* **Vorgang:** `CALL sp_auftrag_abschliessen(1);`
* **Erwartetes Ergebnis:** Der Trigger wird beim Versuch, die Rechnung zu erstellen, einen Fehler verursachen. Wenn der `EXIT HANDLER` korrekt arbeitet, darf der Status des Auftrags **nicht** auf `abgeschlossen` geändert werden, sondern muss auf den ursprünglichen Zustand zurückgesetzt werden (Rollback).

#### **Szenario 3: Dublettenprüfung und MAX()-Logik**

Test der Generierung fortlaufender Auftragsnummern bei hohen Frequenzen oder nach Löschvorgängen.

* **Vorgang:** Manuelles Einfügen eines Auftrags mit der Nummer `2026-999`. Anschließend Aufruf von `CALL sp_neuer_auftrag(...)`.
* **Erwartetes Ergebnis:** Der neue Auftrag muss die Nummer `2026-1000` erhalten. Dies beweist die Stabilität der `MAX()`-Logik gegenüber der fehleranfälligen `COUNT()`-Logik.

#### **Szenario 4: Kundenschutz (Referenzielle Integrität)**

Schutz von Kundenstammdaten, solange noch aktive Geschäftsvorgänge bestehen.

* **Vorgang:** `DELETE FROM kunden WHERE kunden_id = 1;` (bei einem Kunden mit offenen Aufträgen).
* **Erwartetes Ergebnis:** Der Trigger `tr_kunden_loeschschutz` muss die Löschung verhindern und eine entsprechende Fehlermeldung ausgeben.

#### **Szenario 5: Zeitbasierte Automatisierung (Mahnwesen)**

Verifizierung der Logik für überfällige Rechnungen und des automatischen Loggings.

* **Vorbereitung:** Manuelle Änderung eines Fälligkeitsdatums (`faellig_am`) auf 35 Tage in der Vergangenheit und Setzen des Status auf `gesendet`.
* **Vorgang:** `CALL sp_update_ueberfaellige_rechnungen();`
* **Erwartetes Ergebnis:** Nur diese spezifische Rechnung muss den Status `überfällig` erhalten. Diese Änderung muss automatisch in der Tabelle `status_log` dokumentiert werden.

#### SZENARIO 6: Atomarität und Fehlerbehandlung (Rollback-Test)

Nachweis, dass das System keine „halben Sachen“ macht (Datenkonsistenz), falls während einer Transaktion ein Fehler auftritt.

* **Ablauf**: Die Prozedur sp_material_zuordnen wird mit einer nicht existierenden auftrag_id aufgerufen.
* **Erwartung**: Das System muss eine Fehlermeldung ausgeben, und in der Lagerstabelle (Bestand) darf keine Änderung (Abzug) erfolgt sein.

#### SZENARIO 7: Kontrolle von multiplen Materialien und dem Gesamtbetrag

Überprüfung, ob der Rechnungsbetrag für mehrere Materialpositionen korrekt berechnet wird.

* **Szenario**: Einem Auftrag werden zwei verschiedene Materialien zu unterschiedlichen Preisen hinzugefügt.
* **Erwartung**: Der Rechnungsbetrag muss exakt der Formel (Menge1×Preis1)+(Menge2×Preis2) entsprechen.

#### SZENARIO 8: Rückgabe an den Lagerbestand nach Stornierung

Sicherstellen, dass bei der Stornierung einer Bestellung alle Materialien vollständig in das Lager zurückgeführt werden.

* **Ablauf**: Ein Material zuweisen, den Abzug vom Lagerbestand prüfen, danach die Bestellung stornieren.
* **Erwartung**: Der lagerbestand in der Tabelle material muss auf den ursprünglichen Wert zurückgesetzt werden.

#### SZENARIO 9: Korrektheit der Archivierung

Sicherstellen, dass ein gelöschter Kunde tatsächlich in die Tabelle kunden_archiv verschoben und aus der Haupttabelle entfernt wurde.

* **Ablauf**: Einen Kunden löschen, der keine Bestellungen hat.
* **Erwartung**: Der Kunde darf in der Tabelle kunden nicht mehr auffindbar sein, muss jedoch in der Tabelle kunden_archiv mit allen Informationen angezeigt werden.
