# block_fragenfilter — Moodle Fragenfilter-Block

## Überblick

Dieser Block ermöglicht die komfortable, **kursübergreifende Suche** in allen Moodle-Fragesammlungen
direkt von der Startseite oder dem Dashboard aus.

---

## Features

| Feature | Details |
|---|---|
| **Kursübergreifende Suche** | Durchsucht System-, Kurs- und Modulkontexte gleichzeitig |
| **Tag-Suche** | Volltextsuche in Fragenamen UND Tags |
| **Erweiterbare Filter** | Schwierigkeitsstufen, Kategorien und Typen per Admin-Settings konfigurierbar |
| **Export** | Moodle XML, CSV, GIFT-Format – direkt als Download |
| **Berechtigungen** | Nutzt Moodles Standard-Capabilities `question:viewmine` / `question:viewall` |
| **Mehrsprachig** | Vollständige deutsche Sprachdatei enthalten |

---

## Installation

1. Plugin-Dateien nach `{moodle_root}/blocks/fragenfilter/` kopieren. Direkt darin müssen `version.php` und `block_fragenfilter.php` liegen; ab Moodle 5.1 liegt `blocks/` unter `public/`.
2. Als Admin unter **Website-Administration → Benachrichtigungen** das Upgrade ausführen
3. Den Block auf der **Startseite** (oder einem Kurs) über *Block hinzufügen* einfügen

### AMD-Build (Produktivbetrieb)

```bash
# Im Moodle-Root:
php admin/cli/build_js.php --component=block_fragenfilter
# oder mit grunt:
grunt amd --root=blocks/fragenfilter
```

Im Entwicklungsmodus (`$CFG->cachejs = false`) wird die Quelle aus `amd/src/` direkt geladen.

---

## Admin-Einstellungen

**Website-Administration → Plugins → Blöcke → Fragenfilter**

| Einstellung | Beschreibung |
|---|---|
| **Suchbereich** | `Alle` (kursübergreifend) / `Nur aktueller Kurs` / `Nur System` |
| **Schwierigkeitsstufen** | Eine Stufe pro Zeile – beliebig erweiterbar (z. B. A1/A2/B1 für Sprachen) |
| **Vorgeschlagene Tags** | Kommagetrennte Standard-Tags als Schnellfilter |
| **Export-Formate** | Moodle XML / CSV / GIFT einzeln aktivierbar |
| **Max. Ergebnisse** | Limit pro Suchanfrage (Standard: 200) |

---

## Architektur

```
block_fragenfilter/
├── block_fragenfilter.php   # Block-Klasse (applicable_formats, get_content)
├── renderer.php               # Renderer – Mustache-Template befüllen
├── lib.php                    # pluginfile-Handler für Export-Downloads
├── settings.php               # Admin-Einstellungsseite
├── styles.css                 # Block-CSS
├── version.php                # Plugin-Metadaten (Moodle 4.0+)
│
├── amd/src/
│   └── filter.js              # AMD-Modul: Suche, Filter, Export via AJAX
│
├── classes/
│   └── external.php           # Web Services (External API)
│                              #   search_questions   – kursübergreifende Suche
│                              #   get_categories     – alle Fragesammlungen laden
│                              #   export_questions   – XML / CSV / GIFT Export
│
├── db/
│   ├── access.php             # Capabilities
│   └── services.php           # AJAX-Service-Definitionen
│
├── lang/de/
│   └── block_fragenfilter.php  # Deutsche Sprachdatei
│
└── templates/
    └── block.mustache         # Block-HTML-Template
```

---

## Kursübergreifende Suche – Wie funktioniert das?

Moodle speichert Fragen immer in einer **Fragekategorie**, die an einen **Kontext** gebunden ist:

```
context_system  (id=1)
  └── question_categories → systemweite Fragen

context_course  (id=X, pro Kurs)
  └── question_categories → kursspezifische Fragen

context_module  (id=Y, pro Quiz/Aktivität)
  └── question_categories → aktivitätsspezifische Fragen
```

Die Methode `search_questions` sammelt je nach `searchscope` alle relevanten Kontext-IDs
und führt einen einzigen SQL-JOIN über `question`, `question_versions`, `question_bank_entries`
und `question_categories` aus.

**Suchbereich `all`** (Standard):
- System-Kontext
- Alle Kurs-Kontexte (`contextlevel = 50`)
- Optionale Modul-Kontexte (konfigurierbar)

---

## Berechtigungen

| Capability | Wer braucht sie | Zweck |
|---|---|---|
| `moodle/question:viewmine` | Lehrkräfte | Suche in eigenen Fragen |
| `moodle/question:viewall` | Admins, Kurs-Manager | Suche + Export aller Fragen |
| `block/fragenfilter:addinstance` | Editing Teacher | Block zum Kurs hinzufügen |

---

## Moodle-Versionskompatibilität

| Version | Status | Anmerkung |
|---|---|---|
| **Moodle 4.0 – 4.5** | Vollständig kompatibel | Basistabellen identisch |
| **Moodle 5.0+** | Kompatibel (mit Anpassung) | Siehe unten |

### Moodle 5.0 — wichtige Änderung der Fragebank-Architektur

In Moodle 5.0 wurden Fragebanken grundlegend umgebaut:

**Vorher (4.x):** Fragen lagen in `question_categories`, die an einen `CONTEXT_COURSE`-Kontext gebunden waren — eine Fragebank pro Kurs.

**Ab 5.0:** Fragebanken sind eigenständige `mod_qbank`-Aktivitäten mit eigenem `CONTEXT_MODULE`-Kontext. Ein Kurs kann mehrere Fragebanken haben. Bei einem Upgrade von 4.x wird automatisch eine „[Kursname] shared question bank" als mod_qbank-Instanz angelegt (ad-hoc-Task `mod_qbank\task\transfer_question_categories`).

**Was das Plugin dafür tut:** `get_searchable_contextids()` erkennt die Moodle-Version (`$CFG->version >= 2024100700`) und schließt in Moodle 5+ automatisch alle `mod_qbank`-Modul-Kontexte in die Suche ein. Die Kerntabellen (`question`, `question_versions`, `question_bank_entries`, `question_categories`) sind in beiden Versionen identisch.

### Bekannte Upgrade-Probleme bei Moodle 5.0

Nach einem Upgrade von 4.x auf 5.0 muss der ad-hoc-Task `transfer_question_categories` vollständig abgeschlossen sein, bevor das Plugin die Fragen korrekt findet. Prüfen mit:

```bash
php admin/cli/adhoc_task.php --classname=\\mod_qbank\\task\\transfer_question_categories --execute
```

### PHP-Voraussetzungen

- Moodle 4.0–4.5: PHP 8.0+
- Moodle 5.0+: PHP 8.2+ (Pflicht ab Moodle 5.0)

