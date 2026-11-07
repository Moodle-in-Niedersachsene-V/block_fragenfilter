# Datenschutz (Entscheidungen)

Stand: 2026-10-18. Entscheidung: Matthias Grünwald.

- **Inhalte:** Beim Einreichen von Fragen klären die Einreichenden die Rechte (CC0, CC-BY o. ä.). Alle Fragen sind öffentlich. Für Fragentexte, die an die KI gehen, ist deshalb **kein Auftragsverarbeitungsvertrag** nötig.
- **KI-Backend:** Ollama ist **nicht** der Standard im Betrieb. Der Administrator wählt das Backend und die Zugangsdaten in den Plugin-Einstellungen (`ai_backend`). Die Werte stimmen mit dem Kursfilter überein.
- **Bewertungen:** Gespeichert unter dem pseudonymen Cookie-Hash `kf_rater_id`, nicht unter einem Konto (ADR 0003). Datenschutz-Provider: `classes/privacy/provider.php`.
- **Fragentext an die KI:** wird minimiert, nicht anonymisiert (siehe `settings_ai_desc`). Das ist unkritisch, weil die Texte öffentlich sind.
