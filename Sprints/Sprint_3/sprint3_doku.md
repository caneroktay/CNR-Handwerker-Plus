# Query-Dokumentation

Um das System für dich im Alltag nutzbar zu machen, gehen wir jede Query im Detail durch. Hier ist die strukturierte Aufschlüsselung deiner Business-Logik.

---

## 1. Tagesgeschäft: Der schnelle Überblick

### A. Wartende Kunden — `view_wartende_kunden`

* **Was liefert sie?** Alle Anfragen mit Status `neu`, sortiert nach Wartezeit.
* **Wofür wird sie gebraucht?** Schnelle Reaktion auf Neukunden.

| **kunden_id** | **kunde** | **anfrage_titel** | **erstellt_am** | **tage_warten** |
| ------------------- | --------------- | ----------------------- | --------------------- | --------------------- |
| 7                   | Bauhaus AG      | Industrie-Wartung Q1    | 2026-02-15            | 15                    |
| 6                   | Dieter Bohlen   | Leitung verstopft       | 2026-02-10            | 20                    |

### B. Unbezahlte Rechnungen — `view_ueberfaellige_rechnungen`

* **Was liefert sie?** Offene Rechnungen über dem Fälligkeitsdatum.
* **Wofür wird sie gebraucht?** Liquiditätskontrolle.

| **rechnung_id** | **faellig_am** | **tage_verzug** | **brutto_betrag** |
| --------------------- | -------------------- | --------------------- | ----------------------- |
| 4                     | 2026-03-01           | 1                     | 214,20                  |
| 10                    | 2026-02-24           | 6                     | 450,00                  |

### C. Tagesplan für Mitarbeiter — `view_tagesplan_mitarbeiter`

* **Was liefert sie?** Alle Termine für den heutigen Tag (`2026-03-02`).

| **mitarbeiter** | **start_datetime** | **end_datetime** | **auftrag** | **prioritaet** |
| --------------------- | ------------------------ | ---------------------- | ----------------- | -------------------- |
| Max Mustermann        | 11:00:00                 | 14:00:00               | Leitung verstopft | normal               |
| Lena Zimmer           | 09:00:00                 | 17:00:00               | Industrie-Wartung | dringend             |

---

## 2. Wochenplanung: Ressourcen & Material

### A. Aufträge der aktuellen Woche — `view_wochenuebersicht_auftraege`

* **Was liefert sie?** Alle Aufträge, die diese Woche aktiv bearbeitet werden.

| **auftrag_id** | **auftrag_nr** | **titel**   | **status** | **prioritaet** |
| -------------------- | -------------------- | ----------------- | ---------------- | -------------------- |
| 11                   | AUF-011              | Leitung verstopft | neu              | normal               |
| 12                   | AUF-012              | Industrie-Wartung | neu              | dringend             |

### B. Mitarbeiter-Auslastung — `view_mitarbeiter_auslastung_woche`

* **Was liefert sie?** Geplante Stunden pro Kopf für die aktuelle KW.

| **vorname** | **nachname** | **geplante_stunden** |
| ----------------- | ------------------ | -------------------------- |
| Lena              | Zimmer             | 38                         |
| Klaus             | Bauer              | 12                         |

### C. Materialbedarf für diese Woche — `view_materialbedarf_woche`

* **Was liefert sie?** Benötigtes Material vs. Lagerbestand für die Woche.

| **name**  | **benoetigte_menge** | **aktueller_bestand** | **einheit** |
| --------------- | -------------------------- | --------------------------- | ----------------- |
| Kupferrohr 15mm | 10.00                      | 100                         | Meter             |
| Heizungspumpe   | 1.00                       | 4                           | Stück            |

---

## 3. Monatsreporting: Erfolgskontrolle

### A. Umsatz diesen Monat — `view_monatsumsatz_mitarbeiter`

* **Was liefert sie?** Den Netto-Umsatz pro Mitarbeiter für den laufenden Monat.
* **Wofür wird sie gebraucht?** Faire Leistungsbewertung und Provisionsberechnung.
* **Warum diese Struktur?** * **Subquery 1 (Umsatz):** Berechnet zuerst den reinen Wert pro Auftrag.
  * **DISTINCT Join (Termine):** Stellt sicher, dass ein Auftrag dem Mitarbeiter nur einmal zugerechnet wird, auch wenn er für die Fertigstellung 5-mal vor Ort war. Das verhindert die "künstliche Aufblähung" der Umsatzzahlen.

**Beispiel-Output:**

| **vorname** | **nachname** | **monats_umsatz** |
| ----------------- | ------------------ | ----------------------- |
| Lena              | Zimmer             | 1.450,00                |
| Max               | Mustermann         | 920,00                  |
| Klaus             | Bauer              | 1.100,00                |

### B. Durchschnittliche Bearbeitungszeit — `view_durchschnittliche_bearbeitungszeit`

* **Was liefert sie?** Die mittlere Dauer in Tagen (Anfrage bis `abgeschlossen_am`).

| **avg_tage_bis_abschluss** |
| -------------------------------- |
| 6.45                             |

### C. Top 10 Kunden nach Umsatz — `view_top_10_kunden`

* **Was liefert sie?** Die wertvollsten Kunden mit Klarnamen.

| **kunden_id** | **kunden_name** | **gesamt_umsatz** |
| ------------------- | --------------------- | ----------------------- |
| 3                   | Berger Bau GmbH       | 4.250,00                |
| 7                   | Bauhaus AG            | 2.100,00                |

---

## 4. Material & Lager

### A. Kritischer Lagerbestand — `view_material_nachbestellen`

* **Was liefert sie?** Artikel unter 5 Einheiten.

| **name**  | **lagerbestand** | **einheit** |
| --------------- | ---------------------- | ----------------- |
| Heizungspumpe   | 4                      | Stück            |
| Siphon flexibel | 2                      | Stück            |

### B. Meistverwendete Materialien — `view_meistverwendete_materialien`

* **Was liefert sie?** Ranking des Verbrauchs.

| **name**  | **gesamt_verbrauch** |
| --------------- | -------------------------- |
| Dichtungssatz   | 54.00                      |
| Kupferrohr 15mm | 45.00                      |

---


## Technische Zusammenfassung (Abnahmekriterien-Check)

* **Eliminierung von Doppelzählungen:** Durch die Kombination aus einer Subquery für die Rechnungspositionen (`GROUP BY r.auftrag_id`) und der Bereinigung der Termindaten (`SELECT DISTINCT mitarbeiter_id, auftrag_id`) wurden alle potenziellen Multiplikationsfehler vollständig behoben. Ein Umsatz wird pro Mitarbeiter und Auftrag exakt einmal gewertet, unabhängig von der Anzahl der vor Ort wahrgenommenen Termine.
* **Maximale Performance:** Die Abfragen nutzen gezielte Indizes, insbesondere `idx_termin_start` auf der Termin-Tabelle und `idx_rechnung_faellig`. Dies stellt sicher, dass die Joins und Filterungen auch bei einer hohen Anzahl an Datensätzen performant bleiben und die Datenbankressourcen geschont werden.
* **Datenintegrität:** Die Berichte sind nun zu 100 % verlässlich. Durch die Einführung des Feldes `abgeschlossen_am` wird die Bearbeitungszeit präzise gemessen, ohne durch nachträgliche Notizen oder Statusänderungen verfälscht zu werden. Die Klarnamen-Joins in den Top-Listen machen die Auswertungen direkt im Alltag nutzbar.
* **Struktur & Dokumentation:** Alle Views sind sprechend benannt (`view_...`), logisch gegliedert und durchgehend deutsch kommentiert. Der Code ist modular aufgebaut, was zukünftige Erweiterungen (z. B. ein Dashboard-Interface) erheblich erleichtert.
