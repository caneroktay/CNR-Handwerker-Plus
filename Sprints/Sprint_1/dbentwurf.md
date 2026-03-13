# Datenbankentwurf – CNR Handwerker Plus

## 1. Einleitung

Im Rahmen des Projekts wurde für die Anwendung CNR **Handwerker plus** eine relationale Datenbank entwickelt. Ziel der Datenbank ist die strukturierte Verwaltung aller relevanten Geschäftsprozesse eines Handwerksbetriebs.

Die Datenbank dient als zentrale Datenquelle für:

* Kundenverwaltung
* Mitarbeiterverwaltung
* Auftragsverwaltung
* Terminplanung
* Angebots- und Rechnungsverwaltung

Bei der Entwicklung wurde besonderer Wert auf:

* Datenkonsistenz
* Erweiterbarkeit
* Normalisierung
* klare Tabellenbeziehungen

gelegt.

Die Modellierung orientiert sich an den Anforderungen der **Industrie- und Handelskammer** für Fachinformatiker Anwendungsentwicklung.

---

# 2. Konzeptionelles Datenmodell

Im ersten Schritt wurden die fachlichen Objekte (Entitäten) und deren Beziehungen definiert.

## 2.1 Entitäten

* Kunde
* Mitarbeiter
* Auftrag
* Termin
* Angebot
* Rechnung
* Position
* Login_Log
* Material
* Auftrag_Material

Die Entität **Kunde** wurde bewusst weiter unterteilt, um Privat- und Firmenkunden sauber zu trennen.

---

## 2.2 Beziehungen

* Ein Kunde kann mehrere Aufträge besitzen
* Ein Auftrag enthält mehrere Positionen
* Ein Auftrag kann mehrere Termine besitzen
* Ein Mitarbeiter kann mehrere Termine durchführen
* Ein Auftrag kann eine Rechnung erzeugen
* Ein Auftrag kann mehrere Materialien erfordern.
* Ein Material kann in vielen Aufträgen verwendet werden.

---

## 2.3 Kardinalitäten

| Beziehung             | Kardinalität |
| --------------------- | ------------- |
| Kunde – Auftrag      | 1 : N         |
| Auftrag – Position   | 1 : N         |
| Auftrag – Termin     | 1 : N         |
| Mitarbeiter – Termin | 1 : N         |
| Auftrag – Rechnung   | 1 : 0..1      |
| Auftrag – Material   | N : M         |

Dieses Modell bildet die fachliche Grundlage für das logische Datenmodell.

---

# 3. Logisches Datenmodell

Im logischen Modell wurden die Attribute der einzelnen Entitäten festgelegt.

---

## 3.1 Kunde (Haupttabelle)

Speichert grundlegende Kundeninformationen.

**Attribute**

* kunden_id (PK)    		- Primärschlüssel
* typ (privat / firma)		- Kundentyp
* ist_stammkunde		- Stammkunde
* erstellt_am			- Erstellungsdatum
* notizen				- Allgemeine Informationen oder Besonderheiten zum Kunden.

Da Privat- und Firmenkunden unterschiedliche Daten besitzen, wurden diese in separate Tabellen ausgelagert.

---

## 3.2 Kunden_Person

Speichert personenbezogene Daten für Privatkunden.

**Attribute**

* kunden_id (PK, FK)
* vorname
* nachname

Kardinalität:

<pre class="overflow-visible! px-0!" data-start="2490" data-end="2532"><div class="w-full my-4"><div class=""><div class="relative"><div class="h-full min-h-0 min-w-0"><div class="h-full min-h-0 min-w-0"><div class="border corner-superellipse/1.1 border-token-border-light bg-token-bg-elevated-secondary rounded-3xl"><div class="pointer-events-none absolute inset-x-4 top-12 bottom-4"><div class="pointer-events-none sticky z-40 shrink-0 z-1!"><div class="sticky bg-token-border-light"></div></div></div><div class="pointer-events-none absolute inset-x-px top-6 bottom-6"><div class="sticky z-1!"><div class="bg-token-bg-elevated-secondary sticky"></div></div></div><div class="corner-superellipse/1.1 rounded-3xl bg-token-bg-elevated-secondary"><div class="relative z-0 flex max-w-full"><div id="code-block-viewer" dir="ltr" class="q9tKkq_viewer cm-editor z-10 light:cm-light dark:cm-light flex h-full w-full flex-col items-stretch ͼk ͼy"><div class="cm-scroller"><div class="cm-content q9tKkq_readonly"><span>kunden (1) —— (0..1) kunden_person</span></div></div></div></div></div></div></div></div><div class=""><div class=""></div></div></div></div></div></pre>

---

## 3.3 Kunden_Firma

Speichert Firmendaten.

**Attribute**

* kunden_id (PK, FK)
* firmenname
* ansprechpartner
* ust_id

Kardinalität:

<pre class="overflow-visible! px-0!" data-start="2683" data-end="2724"><div class="w-full my-4"><div class=""><div class="relative"><div class="h-full min-h-0 min-w-0"><div class="h-full min-h-0 min-w-0"><div class="border corner-superellipse/1.1 border-token-border-light bg-token-bg-elevated-secondary rounded-3xl"><div class="pointer-events-none absolute inset-x-4 top-12 bottom-4"><div class="pointer-events-none sticky z-40 shrink-0 z-1!"><div class="sticky bg-token-border-light"></div></div></div><div class="pointer-events-none absolute inset-x-px top-6 bottom-6"><div class="sticky z-1!"><div class="bg-token-bg-elevated-secondary sticky"></div></div></div><div class="corner-superellipse/1.1 rounded-3xl bg-token-bg-elevated-secondary"><div class="relative z-0 flex max-w-full"><div id="code-block-viewer" dir="ltr" class="q9tKkq_viewer cm-editor z-10 light:cm-light dark:cm-light flex h-full w-full flex-col items-stretch ͼk ͼy"><div class="cm-scroller"><div class="cm-content q9tKkq_readonly"><span>kunden (1) —— (0..1) kunden_firma</span></div></div></div></div></div></div></div></div><div class=""><div class=""></div></div></div></div></div></pre>

---

## 3.4 Kunden_Adressen

Ein Kunde kann mehrere Adressen besitzen.

**Attribute**

* adress_id (PK)
* kunden_id (FK)
* strasse
* hausnummer
* plz
* ort
* typ

Kardinalität:

<pre class="overflow-visible! px-0!" data-start="2902" data-end="2946"><div class="w-full my-4"><div class=""><div class="relative"><div class="h-full min-h-0 min-w-0"><div class="h-full min-h-0 min-w-0"><div class="border corner-superellipse/1.1 border-token-border-light bg-token-bg-elevated-secondary rounded-3xl"><div class="pointer-events-none absolute inset-x-4 top-12 bottom-4"><div class="pointer-events-none sticky z-40 shrink-0 z-1!"><div class="sticky bg-token-border-light"></div></div></div><div class="pointer-events-none absolute inset-x-px top-6 bottom-6"><div class="sticky z-1!"><div class="bg-token-bg-elevated-secondary sticky"></div></div></div><div class="corner-superellipse/1.1 rounded-3xl bg-token-bg-elevated-secondary"><div class="relative z-0 flex max-w-full"><div id="code-block-viewer" dir="ltr" class="q9tKkq_viewer cm-editor z-10 light:cm-light dark:cm-light flex h-full w-full flex-col items-stretch ͼk ͼy"><div class="cm-scroller"><div class="cm-content q9tKkq_readonly"><span>kunden (1) —— (0..N) kunden_adressen</span></div></div></div></div></div></div></div></div><div class=""><div class=""></div></div></div></div></div></pre>

---

## 3.5 Kunden_Kontakt

Speichert Kommunikationsdaten.

**Attribute**

* kontakt_id (PK)
* kunden_id (FK)
* typ
* wert

Kardinalität:

<pre class="overflow-visible! px-0!" data-start="3094" data-end="3137"><div class="w-full my-4"><div class=""><div class="relative"><div class="h-full min-h-0 min-w-0"><div class="h-full min-h-0 min-w-0"><div class="border corner-superellipse/1.1 border-token-border-light bg-token-bg-elevated-secondary rounded-3xl"><div class="pointer-events-none absolute inset-x-4 top-12 bottom-4"><div class="pointer-events-none sticky z-40 shrink-0 z-1!"><div class="sticky bg-token-border-light"></div></div></div><div class="pointer-events-none absolute inset-x-px top-6 bottom-6"><div class="sticky z-1!"><div class="bg-token-bg-elevated-secondary sticky"></div></div></div><div class="corner-superellipse/1.1 rounded-3xl bg-token-bg-elevated-secondary"><div class="relative z-0 flex max-w-full"><div id="code-block-viewer" dir="ltr" class="q9tKkq_viewer cm-editor z-10 light:cm-light dark:cm-light flex h-full w-full flex-col items-stretch ͼk ͼy"><div class="cm-scroller"><div class="cm-content q9tKkq_readonly"><span>kunden (1) —— (0..N) kunden_kontakt</span></div></div></div></div></div></div></div></div><div class=""><div class=""></div></div></div></div></div></pre>

---

## 3.6 Mitarbeiter

Speichert Benutzer des Systems.

**Attribute**

* mitarbeiter_id (PK)
* vorname
* nachname
* email
* password_hash
* rolle
* aktiv
* letzte_login

---

## 3.7 Login_Log

Speichert Login-Versuche.

**Attribute**

* log_id (PK)
* mitarbeiter_id (FK)
* ip_adresse
* user_agent
* erfolgreich
* eingeloggt_am

---

## 3.8 Auftrag

Zentrale Tabelle der Anwendung.

**Attribute**

* auftrag_id (PK)
* kunden_id (FK)
* angebot_id (FK)
* titel
* beschreibung
* status
* prioritaet  			(Niedrig, Normal, Dringend, Notfall)
* notiz_intern 			- Interne Bearbeitungshinweise
* kunden_kommentar 	- Spezifische Kundenwünsche zum Auftrag
* erstellt_am

---

## 3.9 Auftrag_Position

Speichert Leistungen und Materialien.

**Attribute**

* position_id (PK)
* auftrag_id (FK)
* typ
* bezeichnung
* menge
* einzelpreis_bei_bestellung 	(Historisierter Preis zum Zeitpunkt des Auftrags)
* mwst_satz 				(Der anzuwendende Steuersatz)

---

## 3.10 Termin

Speichert Termine.

**Attribute**

* termin_id (PK)
* auftrag_id (FK)
* mitarbeiter_id (FK)
* start_datetime
* end_datetime
* status

---

## 3.11 Angebot

Speichert Angebote.

**Attribute**

* angebot_id (PK)
* kunden_id (FK)
* status
* netto_summe 		(Redundantes Attribut zur Performance-Steigerung)

---

## 3.12 Rechnung

Speichert Rechnungen.

**Attribute**

* rechnung_id (PK)
* auftrag_id (FK)
* kunden_id (FK)
* rechnungs_datum
* status

---

## 3.13 Rechnung_Position

**Attribute**

* rpos_id (PK)
* rechnung_id (FK)
* position_id (FK)
* menge
* einzelpreis
* einzelpreis_bei_rechnung 	(Revisionssicherer Preis zum Zeitpunkt der Fakturierung)

---

### 3.14 Material (Stammdaten)

Speichert die verfügbaren Materialien und deren Lagerbestand.

**Attribute**

* material_id (PK)
* name
* beschreibung
* einheit (z.B. m, Stk, Pkg)
* lagerbestand
* preis_pro_einheit

---

### 3.15 Auftrag_Material (Verknüpfungstabelle)

Löst die N:M Beziehung zwischen Auftrag und Material auf und speichert den konkreten Bedarf.

**Attribute**

* auftrag_material_id (PK)
* auftrag_id (FK)
* material_id (FK)
* menge 				(Geplante/Verbrauchte Menge)
* bestell_status 			(z.B. 'Bestellt', 'Geliefert', 'Verwendet')
* preis_bei_bestellung

---

# 4. Physisches Datenmodell

Im physischen Datenmodell wurden konkrete Datentypen definiert.

Verwendete Datentypen:

| Datentyp        | Verwendung        |
| --------------- | ----------------- |
| INT             | Primärschlüssel |
| VARCHAR         | Textfelder        |
| TEXT            | Beschreibungen    |
| DECIMAL         | Preiswerte        |
| DATE / DATETIME | Zeitangaben       |
| BOOLEAN         | Statuswerte       |

Zusätzlich wurden folgende Constraints verwendet:

* Primary Key   			- PK
* Foreign Key			- FK
* NOT NULL			- NN
* AUTO_INCREMENT	- AI

---

# 5. Normalisierung

Die Datenbank wurde bis zur **3. Normalform (3NF)** normalisiert.

## 1. Normalform

Alle Attribute sind atomar.

## 2. Normalform

Alle Nicht-Schlüsselattribute hängen vollständig vom Primärschlüssel ab.

## 3. Normalform

Es existieren keine transitiven Abhängigkeiten.

Beispiel:

Kundendaten wurden aufgeteilt in:

* kunden
* kunden_person
* kunden_firma
* kunden_adressen
* kunden_kontakt

- Durch die Einführung der Tabelle **Auftrag_Material** wurde eine N:M-Beziehung zwischen Aufträgen und Materialien aufgelöst. Dies verhindert Redundanzen (Materialdaten müssen nicht pro Auftrag neu getippt werden) und transitive Abhängigkeiten (Stückpreise hängen am Material, nicht am Auftrag).
- In den Tabellen `Auftrag_Position` ve `Rechnung_Position` wurde eine bewusste Ausnahme von der strikten 3NF vorgenommen ( **Denormalisierung** ). Obwohl der Preis eines Materials bereits in der Tabelle `Material` gespeichert ist, wird er zum Zeitpunkt der Bestellung/Rechnungsstellung in die Positionstabellen kopiert. Dies ist notwendig, um die **Datenhistorie** zu sichern. Würde man nur auf die Materialstammdaten verweisen, würden sich bei künftigen Preisänderungen alle alten Rechnungen rückwirkend verändern, was gegen die Grundsätze ordnungsmäßiger Buchführung (**GoBD**) verstoßen würde.
- Analog zur Preishistorisierung wird auch der Mehrwertsteuersatz (`mwst_satz`) direkt in den Positions-Tabellen gespeichert. Dies verhindert fehlerhafte Berechnungen bei künftigen gesetzlichen Steuersatzänderungen. Eine Rechnung muss immer den Steuersatz ausweisen, der zum Zeitpunkt der Leistungserbringung gültig war.
- In der Tabelle `Angebot` wird das Attribut `netto_summe` redundant gespeichert. Hierbei handelt es sich um eine bewusste Denormalisierung aus Performancegründen. Anstatt bei jedem Aufruf eines Angebots die Summe aller Positionen rechenintensiv neu zu kalkulieren, wird der Gesamtwert einmalig berechnet und als Cache in der Haupttabelle gespeichert.

---

# 6. Datenintegrität

Zur Sicherstellung der Datenqualität wurden folgende Maßnahmen umgesetzt.

## Datenbankebene

* Fremdschlüsselbeziehungen
* NOT NULL Constraints
* ENUM Werte
* AUTO_INCREMENT Schlüssel

## Anwendungsebene

* Pflichtfelder bei Kundenerstellung
* Validierung von Datumswerten
* Rollenprüfung beim Login

---

# 7. Sicherheitskonzept

Folgende Sicherheitsmaßnahmen wurden implementiert:

* Passwortspeicherung als Hash
* Login-Protokollierung
* Rollenbasierte Zugriffskontrolle
* Trennung von Benutzer- und Kundendaten

---

# 8. Begründung der Tabellenstruktur

Die Datenbank wurde bewusst stark normalisiert, um:

* redundante Daten zu vermeiden.
* flexible Erweiterungen zu ermöglichen.
* eine präzise Materialbedarfsplanung pro Auftrag zu ermöglichen.
* Engpässe bei der Materialbestellung durch Lagerbestandsführung zu vermeiden (Lösung des Kundenproblems: "Bestelle zu spät oder doppelt").
* **Revisionssicherheit und Historisierung:** Durch die redundante Speicherung von Preisen in den Auftrags- und Rechnungspositionen wird sichergestellt, dass abgeschlossene Geschäftsvorfälle auch nach Stammdatenänderungen (z. B. Preiserhöhungen beim Material) unverändert und nachvollziehbar bleiben.
* **Performance-Optimierung:** Bewusste Denormalisierung der `netto_summe` in der Tabelle `Angebot`, um rechenintensive Aggregationen über alle Positionen bei jedem Ladevorgang zu vermeiden.
* **Effiziente Arbeitssteuerung:** Durch die Einführung von **Prioritäten** und **auftragsbezogenen Notizen** wird die Kommunikation zwischen Büro und Baustelle verbessert. Wichtige Informationen (Notfall-Einsätze oder spezielle Kundenwünsche) gehen nicht verloren und sind direkt im digitalen Auftrag hinterlegt.

---

# 9. Beschreibung des ER-Diagramms

Das Entity-Relationship-Diagramm zeigt die Beziehungen zwischen den Tabellen der Anwendung.

Die zentrale Tabelle bildet  **auftrag** , da hier alle Geschäftsprozesse zusammenlaufen.

Ein Kunde kann mehrere Aufträge besitzen, während ein Auftrag mehrere Positionen und Termine enthalten kann.

Durch die klare Struktur der Fremdschlüssel wird eine konsistente Datenhaltung gewährleistet.

Die zentrale Tabelle bildet  **auftrag** , da hier alle Geschäftsprozesse zusammenlaufen.

Über die Relationstabelle **auftrag_material** ist nun jederzeit ersichtlich, welche Ressourcen für ein spezifisches Projekt gebunden sind. Dies ermöglicht Klaus Meier eine vorausschauende Beschaffung und verhindert das Untertauchen von Materialkosten in der Rechnungsstellung.

---

# ERD

![](../../Sprints/Sprint_1/ERD.png)
