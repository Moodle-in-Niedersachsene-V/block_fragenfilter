# Eigener Vorschau-Pool und öffentliche Aufgabenpool-Fragebanken

## Kontext

Friedhelms im Materialien-Hub erprobter Stand verwendet eigene Fragenfilter-Konten.
Die gemeinsame Kursfilter-Pool-Logik funktionierte für die Fragenvorschau nicht;
der Nutzer verlangt ausdrücklich, diese Kopplung bei der Integration nicht erneut
einzuführen. Moodle 5 benötigt bei der Vorschau zusätzlich die `cmid` der Fragebank.

Diese Entscheidung ersetzt die Pool- und Abhängigkeitsentscheidung aus ADR 0002
sowie die Implementierungsabhängigkeit des Bewertungs-Cookies aus ADR 0003.

## Entscheidung

- Fragenfilter erstellt und belegt ausschließlich eigene `fragenfilter_pool01` bis
  zur konfigurierten Pool-Größe (Standard 10, Obergrenze 50). Die Auswahl wird
  atomar gesperrt; aktive Konten werden nicht an weitere Besucher vergeben.
- Keine IP-Sperre: Zwei Besucher hinter derselben Netzwerkadresse dürfen getrennte
  Vorschauen öffnen. Weitere Tabs derselben Moodle-Sitzung behalten deren Konto.
- Der eigene Hook schützt eigene Pool-Konten auch bei installiertem Kursfilter.
  Kursfilter-Konten werden weder ausgewählt noch mit neuen Rollen versehen.
- Veröffentlicht sind bereite Hauptfragen aus `mod_qbank` in Kursen unter der
  Kategorie `Aufgabenpool`, einschließlich Unterkategorien. Fehlt die Kategorie,
  gibt es keine Ergebnisse. Für reguläre Konten gelten zusätzlich ihre Frage-Rechte.
- Dieselbe Sichtbarkeit gilt für Suche, Kategorien, vorhandene Fragetypen,
  Vorschau, Export und Bewertungen. Die automatische Verschlagwortung verwendet
  dieselbe Aufgabenpool-Grenze.
- Die Vorschau löst die Fragebank-`cmid` auf und schreibt eigene Pool-Konten
  vorübergehend mit einer eigenen Fragebankrolle in den Zielkurs ein. Diese Rolle
  wird nur durch die Einschreibung vergeben, nicht systemweit. Die systemweite
  Vorschaurolle erlaubt nur Blockzugang und Export; keine Trainer-/Bearbeiterrolle
  und kein einschreibungsunabhängiges `moodle/course:view`.
- Bestehende echte Anmeldungen bleiben erhalten. Pool-Anmeldung benötigt POST und
  Sesskey; Export verlangt Anmeldung und Exportrecht.
- Bewertungen behalten das bestehende Cookie `kf_rater_id` und sein Format,
  Lebensdauer und Sicherheitsattribute. Lesen/Erneuern übernimmt Fragenfilter
  selbst; bestehende Bewertungen und Browser-Kennungen bleiben erhalten.
- `block_kursfilter` ist keine Plugin-Abhängigkeit. Seine Implementierung bleibt
  außerhalb dieser Änderung.

## Folgen

Pool-Belegung, Profil-/Kennwortsperren, Cleanup, Rollen und Cookie-Verarbeitung
werden im Fragenfilter getestet und gewartet. Die Aufgabenpool-Grenze ist eine
bewusste Veröffentlichung, kein pauschaler Ersatz für Frage-Rechte im ganzen Hub.
Ein neuer Upgrade-Schritt oberhalb beider integrierter Build-Stände stellt die
eigenen Konten und Rollen auch auf bereits aktualisierten Installationen her.
