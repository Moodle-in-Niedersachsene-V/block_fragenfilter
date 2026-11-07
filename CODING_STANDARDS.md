# Coding Standards

Es gelten die mit den Vereinsentwicklern abgestimmten Coding Standards von Moodle in Niedersachsen e. V.:
[`security-checks/coding-standards/CODING_STANDARDS.md`](https://github.com/Moodle-in-Niedersachsene-V/security-checks/blob/main/coding-standards/CODING_STANDARDS.md)
(Quellenprüfung: [`quellen.md`](https://github.com/Moodle-in-Niedersachsene-V/security-checks/blob/main/coding-standards/quellen.md)). Das Repo ist privat; Zugriff über die Vereinsorganisation.

Reviews zitieren die Regel mit Nummer (`S2`, `N3`, `E4`). Die Regelpräfixe sind S/D/T/E/N/U/A/K.

## Geltungsbereich

- **Scopes:** `all`, `Moodle` und `AI`: die KI-Verschlagwortung schickt Frageninhalte direkt an einen KI-Anbieter. K-Regeln gelten für Prompt und Auswertung der Antwort (`classes/ai_connector.php`).
- **Glossar für N1:** [`GLOSSARY.md`](GLOSSARY.md); Begriffe im Code verwenden die Schreibweise der jeweiligen `Code:`-Zeile.
- **Gate:** Fragenfilter hat kein Coverage-/CRAP-/Mutations-Gate. Die CI (`.github/workflows/moodle-ci.yml`) prüft phpcs, phpdoc, savepoints, mustache und grunt. Der eigenständige Semgrep-Job blockiert zusätzlich bei ERROR-Treffern des versionierten Vereinsregelsatzes; Befundbewertung siehe [Fallplan](docs/fallplan.md). Regeln, die laut Standard „vom Gate erzwungen“ werden, aber hier von keinem Werkzeug geprüft werden, gelten im Review.

## Abweichungen

| Regel | Abweichung | Begründung |
|---|---|---|
| N3 (nur `lang/en/` ausliefern) | `lang/de/` wird mit ausgeliefert | [ADR 0001](docs/adr/0001-fragenfilter-runs-only-in-the-materials-hub.md) |
| D2 (alle Datenbanken) | nur die Datenbank des Materialien-Hubs; DML-API und Platzhalter bleiben Pflicht | [ADR 0001](docs/adr/0001-fragenfilter-runs-only-in-the-materials-hub.md) |
