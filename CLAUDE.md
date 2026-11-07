# Fragenfilter – CLAUDE.md

Moodle-Block `block_fragenfilter`: kursübergreifende Suche in Fragensammlungen, mit Filtern, Bewertung und Export (Moodle XML, CSV, GIFT). Details: [README.md](README.md).

- **Stack:** PHP-Plugin für Moodle (`version.php`, `requires` siehe dort), AMD-Modul in `amd/src/`, Mustache-Templates in `templates/`.
- **GitHub:** `Moodle-in-Niedersachsene-V/block_questionfilter`

## Wichtige Dateien

| Datei/Ordner | Zweck |
|---|---|
| `GLOSSARY.md` | Domain-Glossar mit fester Code-Schreibweise je Begriff |
| `CODING_STANDARDS.md` | Verweis auf die Vereinsstandards (S/D/T/E/N/U/A/K) und projektbezogene Abweichungen |
| `docs/adr/` | Architekturentscheidungen |
| `docs/agents/` | Arbeitsdoku für Agenten (Issues, Labels, Domain, Testing) |

## Aufgabenhandling

- **Coding Standards:** vor dem Schreiben oder Reviewen von Code oder Tests [`CODING_STANDARDS.md`](CODING_STANDARDS.md) lesen.
- Vor Funktionsänderungen alle Aufrufer suchen.
- Änderungen an `amd/src/` brauchen einen Grunt-Build (`amd/build/`), sonst schlägt der CI-Schritt `grunt` fehl.
- Nach Änderungen an `db/access.php`, `db/services.php`, `db/install.xml` oder `version.php` Moodle-Upgrade ausführen.
- Testen: siehe [`docs/agents/testing.md`](docs/agents/testing.md).
- Spec #2: Vor jedem Folgeticket den [Sicherheits-Fallplan](docs/fallplan.md) lesen und dessen betroffene Fälle nach den dortigen Nachweisregeln in derselben Änderung aktualisieren.

## Git/gh-Workflow

`main` = veröffentlichter Stand. Änderungen über Branch und PR. Force-Push, History-Rewrite und Branch-Löschung nur nach Rückfrage.

## Agent skills

### Issue tracker

GitHub Issues in `Moodle-in-Niedersachsene-V/block_questionfilter`, via `gh` CLI. Siehe `docs/agents/issue-tracker.md`.

### Triage labels

Standard-Vokabular: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix` (1:1-Mapping). Siehe `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `GLOSSARY.md` + `docs/adr/` im Root. Siehe `docs/agents/domain.md`.
