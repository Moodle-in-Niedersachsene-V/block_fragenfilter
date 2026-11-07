# Fragenfilter

Ein Block, mit dem man Fragen aus allen zugänglichen Fragensammlungen (System-, Kurs- und Modulkontext) findet, filtert und exportiert.

## Fragen

**Fragensammlung**:
Eine Moodle-Fragebank in einem Kontext (System, Kurs oder Modul), aus der der Block Fragen liest.
_Avoid_: Fragenpool, Bank
Code: `question bank`

**Frage**:
Eine einzelne Moodle-Frage mit Name, Typ, Tags und Schwierigkeitsstufe.
_Avoid_: Item, Aufgabe
Code: `question`

**Schwierigkeitsstufe**:
Vom Admin konfigurierbare Stufe einer Frage (z. B. A1/A2/B1), eine pro Zeile in den Einstellungen.
_Avoid_: Level, Niveau
Code: `difficulty level`

**Suchbereich**:
Festlegung, in welchen Kontexten gesucht wird: alle, nur der aktuelle Kurs oder nur System.
_Avoid_: Scope
Code: `search scope`

## Ergebnisse

**Ergebnis**:
Eine Frage, die zu einer Suchanfrage passt; Anzahl ist durch das Limit für Ergebnisse begrenzt.
_Avoid_: Treffer, Hit
Code: `result`

**Export**:
Download ausgewählter Fragen im Format Moodle XML, CSV oder GIFT.
_Avoid_: Download, Backup
Code: `export`

**Format**:
Ein Exportformat (Moodle XML, CSV oder GIFT), einzeln aktivierbar.
_Avoid_: Dateityp
Code: `export format`

## Besuch

**Materialien-Hub**:
Das Moodle des Vereins Moodle in Niedersachsen e. V., in dem Lehrkräfte Kurse und Fragen zur Nachnutzung finden; einziger Einsatzort des Fragenfilters.
_Avoid_: Aufgabenpool, Vereins-Moodle
Code: `materials hub`

**Besucher**:
Eine Person, die den Fragenfilter nutzt, mit oder ohne eigenes Moodle-Konto.
_Avoid_: Gast, Nutzer
Code: `visitor`

**Pool-Konto**:
Ein vom Kursfilter verwaltetes Moodle-Konto, das genau einem Besucher für eine Vorschau überlassen wird; danach darf der Besucher auch exportieren.
_Avoid_: Pool-Nutzer, Gastnutzer, Gastkonto
Code: `pool account`

**Vorschau**:
Der Blick eines Besuchers in eine Frage über ein Pool-Konto oder ein eigenes Konto.
_Avoid_: Gast-Vorschau, Gast-Login
Code: `preview`

**Bewertung**:
Eine Sternebewertung (1–5) eines Besuchers für eine Frage; höchstens eine je Besucher und Frage.
_Avoid_: Rating, Stimme
Code: `rating`

## Berechtigungen

**Sichtbarkeit**:
Welche Fragen ein Nutzer sehen darf, geregelt über die Moodle-Capabilities `question:viewmine` und `question:viewall`.
_Avoid_: Rechte bei der Suche
Code: `visibility`

## KI-Verschlagwortung

**Tag-Vorschlag**:
Ein von der KI erzeugter Tag-Satz für eine Frage, der mit Status `pending` (offen), `applied` (übernommen), `rejected` (verworfen) oder `empty` (kein Tag) gespeichert wird.
_Avoid_: Tag-Kandidat, KI-Tag
Code: `suggestion`

**Prüfung**:
Die Freigabe oder Ablehnung eines Tag-Vorschlags durch eine berechtigte Person (`reviewtags`); jede Prüfung löst ein Ereignis aus.
_Avoid_: Review, Moderation
Code: `suggestion_review`, Ereignis `suggestion_reviewed`
