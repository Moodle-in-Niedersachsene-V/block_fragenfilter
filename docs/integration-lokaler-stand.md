# Integration von Friedhelms Stand und Spec #20

Analyse vom 07.11.2026. Remote-Referenzen wurden vor dem Vergleich aktualisiert.
Dieser Bericht dokumentiert zunächst die Analyse. Die anschließende Umsetzung folgt dem aktuellen Integrationsnachweis im Fallplan (I1); die folgenden Ausgangsbefunde beziehen sich auf die ursprünglichen Branch-Stände.

## Verbindliches Ziel

Friedhelms funktionierende Fragenfilter-Vorschau erhalten, unsere Refactorings und
Sicherheitsprüfungen übernehmen, danach über einen geprüften PR nach `main` integrieren.
Der Fragenfilter behält seine eigenen `fragenfilter_pool*`-Konten. Die Auswahl und
Belegung dieser Konten darf keine Kursfilter-Pool-Logik aufrufen. Keine erneute
Kursfilter-Kopplung einführen.

## Geprüfte Stände und Probe-Merge

| Referenz | Commit |
|---|---|
| `origin/main` | `5ad49315d421794ccffc9722d62de7f271e18e9c` |
| `origin/lokaler-stand` | `0de51a9ee8f52a920757a0cfcde01429961753df` |
| `refactor/spec-20-deep-modules` | `e5beac93e74789baf5351ba8ad5586a4ff04f8c6` |
| Gemeinsamer Vorfahr von Refactor und lokalem Stand | `8b1194d55703ea351920e4c6cf33c5145f11f0d6` |

Friedhelms Branch enthält einen Sammelcommit auf einem älteren Vorfahren von `main`.
Inhaltlich enthält er bereits große Teile der späteren Sicherheitsarbeiten und der
Umbenennung. Diese gemeinsame Codebasis ist in der Commit-Abstammung nicht abgebildet;
dadurch entstehen auch Konflikte in beidseitig hinzugefügten Dateien.

Direkter Inhaltsvergleich `origin/main` gegen `origin/lokaler-stand`: 23 geänderte
Dateien. Unser Refactor enthält acht zusätzliche Commits gegenüber `main`.

Der echte Probe-Merge mit `git merge-tree --write-tree --name-only e5beac9 0de51a9`
lief in einer separaten temporären Git-Kopie und meldete Konflikte in 21 Dateien:

```text
GLOSSARY.md
amd/build/filter.min.js
amd/build/filter.min.js.map
amd/src/filter.js
block_fragenfilter.php
classes/external.php
classes/preview_role.php
classes/question_export.php
classes/question_visibility.php
classes/suggestion_review.php
classes/task/generate_tags.php
db/access.php
db/install.php
db/tasks.php
db/upgrade.php
docs/fallplan.md
guest_preview.php
lib.php
review.php
tests/search_export_test.php
version.php
```

Zwei davon sind generierte AMD-Dateien: nach Auflösung von `amd/src/filter.js` neu
bauen. Nicht anhand eines pauschalen „ours“/„theirs“ über alle Dateien entscheiden.
Konfliktfreie Dateien ebenfalls prüfen: insbesondere CI, Hooks und Dokumentation.

## Fachliche Auflösung

| Bereich | Vorgehen |
|---|---|
| Eigener Pool | Friedhelms `pool_manager`, Kontenerzeugung, Belegung ohne IP-Sperre und Cleanup erhalten; Anmeldung, Wiederverwendung und parallele Belegung testen. |
| Vorschau | `cmid` für `mod_qbank`, temporäre Kurseinschreibung und Weiterleitung auch angemeldeter Besucher erhalten. Unsere zentrale Besucher-Berechtigung und POST-/Sesskey-Prüfung weiterverwenden. |
| Oberfläche | Speichern und Wiederherstellen der Filter nach Pool-Anmeldung erhalten, einschließlich Suche ohne ausgewählte Fragen. Alle Vorschauen weiter über den Einstieg mit `cmid` führen. |
| Suche | Unsere Module `search`/`search_filters` behalten. Friedhelms Aufgabenpool-Einschränkung in die zentrale Abfrage integrieren, statt die Suchlogik wieder in `external.php` einzubauen. |
| Sichtbarkeit | Eine gemeinsame, getestete Regel für Suche, Kategorien, vorhandene Fragetypen, Vorschau, Export und Bewertungen verwenden. Öffentliche Freigabe auf den Aufgabenpool begrenzen. |
| Export | Unsere Writer und Format-Regressionstests behalten; dieselbe fachliche Sichtbarkeitsgrenze wie bei Suche und Vorschau erzwingen. |
| Bewertungen | Unser `rating`-Modul behalten und direkte Kursfilter-Helper-Aufrufe ablösen. Bestehende Cookie-Identitäten und Altbewertungen erhalten. |
| KI-Tags | Unseren `tag_suggestion`-Lebenszyklus behalten, Friedhelms Aufgabenpool-Einschränkung übernehmen und das bestehende Batch-Maximum erhalten. |
| Installation/Upgrade | Eigenen Pool und Rollen sowohl frisch installieren als auch aus beiden Branch-Ständen upgraden. Neue Build-Nummer und neuen Upgrade-Schritt verwenden. |
| CI | PHPUnit, HTTP-Tests und Semgrep aus unserem Stand erhalten; Installation und Tests ohne Kursfilter ermöglichen und zusätzlich die Koexistenz prüfen. AMD-Ausgabepfad bleibt `fragenfilter`. |
| Doku | ADR 0002 ersetzen/ablösen, ADR 0003 anpassen; Glossar, Testing-Doku, Datenschutz, Release-Hinweise und betroffene Fallplan-Fälle samt Nachweisen aktualisieren (A3/A4). |

## Vor dem Merge zu schließende Lücken

1. **Restkopplung:** In Friedhelms `classes/external.php` rufen `rate_question()`
   und `get_ratings()` weiter ungeschützt `block_kursfilter\\rating_helper` auf.
   In unserem Stand liegen diese Aufrufe in `classes/rating.php`. Das Entfernen der
   Plugin-Abhängigkeit allein macht Bewertungen noch nicht eigenständig.
2. **Pool-Sperren bei Koexistenz:** Friedhelms `hook_callbacks::after_config()`
   steigt aus, sobald die Kursfilter-Pool-Klasse existiert. Ob der Kursfilter die
   neuen `fragenfilter_pool*`-Konten schützt, ist damit nicht nachgewiesen.
   Die Sperren für die eigenen Konten müssen mit und ohne Kursfilter greifen.
   Kursfilter-Konten dabei nicht erneut als eigenen Fragenfilter-Pool auswählen.
3. **Uneinheitliche Aufgabenpool-Grenze:** `public_context_ids()` liefert alle
   gesuchten Kontexte zurück; die Aufgabenpool-Einschränkung wird separat nur in
   Suche und Kategorien ergänzt. `visible_ids()` und damit Vorschau, Export und
   Bewertungen tragen diese zusätzliche Grenze nicht. Manipulierte Frage-IDs
   außerhalb des Aufgabenpools müssen vor Anmeldung/Export abgewiesen werden
   (S2/S3). Das ist ein statischer Befund, noch kein ausgeführter Angriffstest.
4. **Rollen und Einschreibung:** Friedhelms Pool-Einschreibung bevorzugt `teacher`,
   danach sogar `editingteacher`, danach `student`. Vorschau muss funktionieren,
   ohne unbeabsichtigte Bearbeitungsrechte zu vergeben. Erforderliche Rechte mit
   echten Moodle-Vorschauen prüfen und explizit begrenzen (S2).
5. **Upgrade-Pfad:** Friedhelms neue Schritte enden bei `2026102208`, sein Build
   lautet `2026102216`. Unser Refactor-Build ist bereits `2026110703`.
   Auf einer so installierten Instanz laufen die älteren Schritte nicht mehr.
   Ein neuer Schritt oberhalb beider Versionsstände muss Pool und Rollen gezielt
   herstellen. Bestehende Konten, Bewertungen und Einstellungen erhalten.
6. **Prüfungen:** Friedhelms Stand entfernt Semgrep-Regeln und den Semgrep-Job
   sowie PHPUnit-/HTTP-Schritte aus CI. Das ist unabhängig von der Entkopplung;
   diese Prüfungen erhalten. Seine bestehende Testsuite beschreibt überwiegend
   weiterhin Kursfilter-Konten und die alte Rollen-/IP-Logik. Die Tests mit dem
   geänderten Vertrag anpassen, Schutztests erhalten (T4).

## Umsetzung und Abnahme

1. Integrationsbranch aus `origin/lokaler-stand` anlegen und den Refactor-Branch
   hineinmergen. Beide Historien durch einen Merge erhalten; kein Rebase und kein
   Force-Push. Keinen der beiden Arbeitsbranches vorab nach `main` mergen.
2. Zuerst Vertrag und Regressionstests für eigenen Pool, Aufgabenpool-Grenze,
   Vorschau und Kursfilter-Unabhängigkeit festhalten. Konflikte nach obiger
   Tabelle auflösen; keine fachlichen Rücknahmen zugunsten alter Tests.
3. Ohne Kursfilter testen: öffentliche Suche, erste und weitere Vorschau,
   `cmid` in echter Aufgabenpool-Fragebank, Export nach Vorschau, Bewertung,
   Filterzustand nach Reload, voller Pool und konkurrierende Besucher.
4. Mit installiertem Kursfilter testen: ausschließlich eigener Fragenfilter-Pool
   für neue Fragenvorschauen; eigene Konten bleiben von Profil-/Kennwortseiten
   und sonstigen gesperrten Seiten ausgeschlossen. Echte Anmeldung erhalten.
5. Gegenfälle testen: GET/ungültiger Sesskey, Frage außerhalb Aufgabenpool,
   falscher Kontext, unbekannte Frage, fremde Exportdatei und ähnlich benannter
   regulärer Nutzer. Suche und direkte IDs müssen dieselbe Grenze haben.
6. Frische Installation und Upgrade von `2026102216` sowie `2026110703` in
   isolierten Datenbanken prüfen; keine Live-Datenbank verwenden.
7. Formatierung, neue Build-Nummer, Moodle-Upgrade, AMD-Build, vollständige
   PHPUnit-/HTTP-Suite, Semgrep und bestehende CI-Matrix durchführen. Danach
   Standards-/Spec-Review und einen gemeinsamen Integrations-PR nach `main`.

Ausgeführt: Remote-Aktualisierung, Historien-/Inhaltsvergleich, statische Prüfung
der genannten Pfade und isolierter Probe-Merge. Keine Moodle-Laufzeittests,
keine Konfliktauflösung, kein Push und kein Main-Merge. Eine zeitliche Zusage
zur funktionierenden Vorschau lässt sich aus dem Probe-Merge nicht ableiten.
