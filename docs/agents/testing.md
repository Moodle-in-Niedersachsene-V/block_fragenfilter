# Testing

PHPUnit läuft in einer isolierten Moodle-Testinstallation. CI prüft Moodle 5.1/PHP 8.3 mit MariaDB 10.11 und Moodle 5.2/PHP 8.4 mit PostgreSQL 16. Beide Jobs installieren `block_kursfilter` von `main` per `moodle-plugin-ci add-plugin` und führen `moodle-plugin-ci phpunit --fail-on-warning` aus. phpcs, phpdoc, savepoints, mustache und grunt bleiben aktiv.

Der Fragenfilter hängt von `block_kursfilter` ab (`version.php`) und baut keine eigenen Pool-Sperren. `tests/preview_role_test.php` prüft, dass er keine eigenen Hook-Callbacks für Pool-Konten registriert, und die Vorschaurolle (Upgrade-Schritt, Ausschluss ähnlich benannter Konten). Die Pool-Grenzen selbst testet der Kursfilter.

## Lokale Vorbereitung

Keine gemeinsam genutzte Installation, Datenbank oder laufende Instanz verwenden. Eine eigene Moodle-Kopie, eine eigene leere Datenbank und eigene Datenverzeichnisse anlegen. PHP benötigt die Moodle-Erweiterungen, insbesondere cURL und den passenden Datenbanktreiber, sowie `max_input_vars=5000` und ausreichend Speicher (lokal 512 MB).

1. Moodle 5.1 oder 5.2 in ein eigenes Verzeichnis auschecken, Abhängigkeiten mit `composer install` installieren. Dieses Repo nach `public/blocks/fragenfilter` verlinken oder kopieren, `block_kursfilter` von `main` nach `public/blocks/kursfilter` auschecken. Keine Secrets oder `config.php` aus einer anderen Installation übernehmen.
2. Eine eigene `config.php` im Moodle-Root nach `config-dist.php` erstellen: Datenbanktreiber, Host, Name, Benutzer und Passwort ausschließlich der Testdatenbank, `prefix = 'mdl_'`, `wwwroot` und ein eigenes `dataroot`. Zusätzlich `phpunit_prefix = 'phpu_'` und ein separates `phpunit_dataroot` setzen. Beide Datenverzeichnisse müssen für CLI und Webserver schreibbar sein.
3. Vor `require_once(__DIR__ . '/lib/setup.php');` die Umleitung einfügen:

   ```php
   if (getenv('QUESTIONFILTER_WEB_TESTS')) {
       $CFG->prefix = $CFG->phpunit_prefix;
       $CFG->dataroot = $CFG->phpunit_dataroot;
       $CFG->wwwroot = getenv('QUESTIONFILTER_WEB_TESTS');
       unset($CFG->phpunit_prefix, $CFG->phpunit_dataroot);
   }
   ```

   Das `unset` verhindert, dass Moodle den Webserver als PHPUnit-Prozess erkennt. CI bindet denselben Code als `qf-web-config.php` ein.
4. Im Moodle-Root **ohne** `QUESTIONFILTER_WEB_TESTS` initialisieren und auf Exit 0 warten:

   ```bash
   php public/admin/tool/phpunit/cli/init.php
   ```

   Dies installiert auch beide Plugins in den PHPUnit-Tabellen und erzeugt `phpunit.xml`. Nach Plugin- oder Versionsänderungen erneut ausführen. Während Initialisierung und Testlauf darf kein anderer Test dieselbe Datenbank verwenden.
   Den strengen CI-Nachinstallationsschritt bei Änderungen an Bootstrap/Hooks zusätzlich ausführen:

   ```bash
   php public/admin/tool/phpunit/cli/util.php --buildconfig
   ```

   Exit 0 allein genügt hier nicht: Der Schritt darf keine PHP-Fehler oder Moodle-`debugging()`-Meldungen ausgeben. `moodle-plugin-ci install` erzwingt das über `MoodleProcess`; insbesondere dürfen keine veralteten `after_config`-Callbacks verbleiben. Hook-Registrierungen liegen in `db/hooks.php`; nach Änderungen die Plugin-Version erhöhen und die isolierte Testinstallation aktualisieren. Wenn nur die PHPUnit-Tabellen initialisiert sind, das CLI-Upgrade gezielt auf diese umleiten und danach wieder ohne Umleitung initialisieren:

   ```bash
   QUESTIONFILTER_WEB_TESTS=http://127.0.0.1:8080 php admin/cli/upgrade.php --non-interactive
   php public/admin/tool/phpunit/cli/init.php
   ```

5. In einem zweiten Terminal aus dem Moodle-Root den echten Testwebserver starten:

   ```bash
   QUESTIONFILTER_WEB_TESTS=http://127.0.0.1:8080 php -S 127.0.0.1:8080 -t public > webserver.log 2>&1
   ```

6. Nach erfolgreichem Start die Suite im ersten Terminal ausführen:

   ```bash
   QUESTIONFILTER_WEB_URL=http://127.0.0.1:8080 vendor/bin/phpunit --fail-on-warning --testsuite block_fragenfilter_testsuite
   ```

   `QUESTIONFILTER_WEB_TESTS` gehört in den Webserver-Prozess oder das oben gezeigte gezielte CLI-Upgrade. Im PHPUnit-Prozess darf diese Variable nicht gesetzt sein. Für einen einzelnen Test kann statt `--testsuite` der Pfad `public/blocks/fragenfilter/tests/request_test.php` und optional `--filter test_anonymous_search_returns_a_visible_question` verwendet werden.

Ohne `QUESTIONFILTER_WEB_URL` überspringen sich die Request-Tests gezielt; eine gesetzte, aber unerreichbare URL lässt sie fehlschlagen. Der Webserver muss dieselben PHPUnit-Tabellen und dasselbe PHPUnit-Datenverzeichnis lesen. Die Fixtures setzen `forcelogin=0`, weil ADR 0002 eine öffentliche Suche beschreibt (Moodle 5.2 liefert standardmäßig `forcelogin=1`). Der Suchtest prüft eine echte Frage über `/lib/ajax/service.php`, der Exporttest die Ablehnung ohne Anmeldung und unveränderte Fragen-, Bewertungs- und Exportdaten. Die HTTP-Anfragen der Such- und Exporttests senden weder Cookie noch Sesskey; die Bewertungstests senden Sesskey und, wo nötig, das Cookie `kf_rater_id`. Vor dem Request schließen die Tests ihre Transaktion, damit Fixtures sichtbar sind; danach erzwingen sie den vollständigen Datenbank-Reset, auch für HTTP-Schreibzugriffe außerhalb von PHPUnit.

Der lokale Nachweis für das Testgerüst wurde unter `/tmp/questionfilter-spec2-issue5/` mit eigenen Moodle-5.1/PHP-8.3- und Moodle-5.2/PHP-8.4-Installationen geführt, jeweils mit separatem PostgreSQL-Container und PHP-Container. Beide Installationen bestehen die strengen CI-Nachinstallationsschritte (`--buildconfig`, `--buildcomponentconfigs`) und `moodle-plugin-ci phpunit --fail-on-warning` mit echten HTTP-Requests. Die verbindlichen MariaDB-/PostgreSQL-Matrixnachweise liefert CI. Der Webserver und die Datenbank waren ausschließlich für diesen Lauf angelegt. Logs und tatsächliche Exitcodes liegen getrennt vor.

- Änderungen an `amd/src/` brauchen einen Grunt-Build (`amd/build/`).
- Nach Änderungen an `db/access.php`, `db/services.php`, `db/install.xml` oder `version.php` Moodle-Upgrade ausführen.

## Semgrep

Der eigenständige CI-Job `semgrep` verwendet den unveränderten
[Vereinsregelsatz samt Herkunft und Hash](../../.github/semgrep/README.md).
Im Repo-Root mit Docker denselben blockierenden Lauf starten:

```bash
docker run --rm --network none -v "$PWD:/src:ro" -w /src \
  semgrep/semgrep@sha256:93963d9295a366f59e4850127b1550400ee7b388f04fe144e4a1f6325d96e01b \
  semgrep scan --config .github/semgrep/moodle-security.yml \
  --severity ERROR --error --strict --metrics=off --disable-version-check \
  --no-rewrite-rule-ids .
```

Das Image muss vorher vorhanden sein (`docker pull` mit demselben Digest).
Semgrep 1.179.0 liefert bei ERROR-Treffern Exit 1, bei keinem ERROR Exit 0;
`--strict` lässt auch Scan-/Regelfehler scheitern. WARNING und INFO blockieren
nicht. Für die fachliche Bewertung aller Schweregrade `--severity ERROR --error`
weglassen; dieser Bericht liefert auch bei Treffern Exit 0 und ist kein Gate.
Bei Bedarf `--json --output /tmp/questionfilter-semgrep.json` ergänzen.
`.semgrepignore` enthält bewusst keine Ausschlüsse, damit auch Test-PHP-Dateien
geprüft werden; einzelne Regeln haben eigene, unverändert übernommene Pfadgrenzen.
Begründete Ausnahmen stehen nur am betroffenen Fund als `nosemgrep: <Regel-ID>`
mit konkreter Begründung (A2). Bekannte echte Lücken dürfen so nicht ausgeblendet
werden. Der [Fallplan](../fallplan.md) bewertet die tatsächlichen Treffer.

Die Gate-Proben zu #7 liegen unter `/tmp/questionfilter-spec2-issue7/fixtures/`:
`unsafe.php` enthält Superglobal-Zugriff und `eval` (ERROR, Gate-Exit 1),
`safe.php` nutzt typisierte Parameter, gebundenes SQL und `s()` (Exit 0),
`advisory.php` enthält `md5` und einen ungeprüften `html_writer`-Inhalt
(WARNING/INFO im Vollbericht, Gate-Exit 0). `gate-checks.py` dort führt diese
Scans und den Plugin-Scan mit JSON-Bericht, Logs und getrennten Exitdateien aus.
Die Fixtures werden nur gescannt, nie als PHP ausgeführt. Sie prüfen Auswahl und
Exitverhalten des Gates, keine vollständige Wirksamkeit aller 23 Regeln.

## Suche und Export (#9)

`search_export_test` prüft die externe API: `viewmine`/`viewall` in System-, Kurs-
und Modulkontexten, alle Suchbereiche, Metadaten ohne Fragen-/Blockrechte,
Sonderzeichen einschließlich Backslash und LIKE-Wildcards, Eigentümer-/Tagfilter
vor dem Ergebnislimit sowie ungültige IDs und maximal 200 Such-/Exportfragen.
Die XML-/CSV-/GIFT-Inhalte einer Beispielfrage werden aus der erzeugten Datei geprüft.

Die Request-Suite prüft den anonymen Export nach öffentlicher Suche, den erlaubten
Pool-Export samt Download, fremde/gemischte IDs und deaktivierte/unbekannte Formate
ohne Fachdaten-/Dateiänderung. Downloads werden zusätzlich gegen fremde Konten,
eine neue Sitzung desselben Kontos, Kontextmanipulation und Traversierung geprüft.
Pool-Konten werden wiederverwendet; deshalb gehört eine Exportdatei sowohl zum
Konto als auch zur erzeugenden Browsersitzung. Ein weiterer Export ersetzt die
vorherige Datei dieses Kontos. Kein Browser-XSS-Test, kein Test aller Fragetypen.

Neue lokale Belege: `/tmp/questionfilter-spec2-issue9/`, Zuordnung und Grenzen im
[Fallplan](../fallplan.md). Die CI bleibt für die vereinbarte Datenbankmatrix nötig.

## Vorschau-Start (#7, #8)

`request_test` prüft den Vorschau-Einstieg `guest_preview.php` gegen den echten Webserver:

- `test_preview_rejects_get_and_invalid_sesskeys_before_logging_in`: GET ohne, mit
  falschem und mit gültigem Sesskey sowie POST ohne/mit falschem Sesskey melden kein Pool-Konto an
  und lassen Nutzer-, Fragen-, Bewertungs- und Tag-Daten unverändert. Gegenfall: gültiger POST
  meldet ein Pool-Konto an und liefert das Vorschauziel.
- `test_preview_keeps_a_real_login_and_takes_no_pool_account`: eine echte Anmeldung wird nicht ersetzt.
- `test_preview_starts_pool_accounts_for_visitors_on_different_addresses`: zwei Besucher
  (Adresse per `X-Forwarded-For`, `getremoteaddrconf` gesetzt) erhalten verschiedene Pool-Konten.
- `test_preview_shows_a_notice_when_the_pool_is_full` und
  `test_preview_shows_a_notice_when_the_address_already_has_a_preview`: Hinweis, keine neue Sitzung.
- `test_preview_gives_the_preview_role_to_a_pool_account_created_later`: ein nach dem Upgrade
  angelegtes Pool-Konto erhält beim Start die Vorschaurolle.
- `test_preview_of_an_unknown_question_takes_no_pool_account`.

Belegung, IP-Grenze und Seitensperren testet der Kursfilter
(dort `preview_starter_test`, `hook_callbacks_test`); Belege für #8 liegen unter
`/tmp/questionfilter-spec2-issue8/`. Der Kursfilter muss lokal in der Version von `main` stehen
(`preview_starter::start(null, …)` seit Merge 811973e).

## KI-Prüfseite und Connector (#12)

Die Suite prüft die Prüfseite mit echter Moodle-Anmeldung über HTTP: kein
`reviewtags`, GET mit gültigem Sesskey, POST ohne/falschen Sesskey und fremde,
verwaiste oder bereits bearbeitete Vorschlags-IDs dürfen keine Pending-/Tag-Daten
ändern. Zulässige Einzel-/Sammelaktionen, `tagmine` samt Eigentümergrenze,
Erhaltung bestehender Tags und maskierte Skriptvorschläge gehören zu den Gegenfällen.

`ai_connector_test` startet eigene kurzlebige HTTP-/TLS-Systempeers über `proc_open`
mit einem Argumentarray ohne Shell. PHP CLI und OpenSSL müssen verfügbar sein;
keine externen KI-Konten oder API-Schlüssel werden verwendet. Die Peers stehen nur
mit gesetzter Testprozess-Umgebung zur Verfügung. Geprüft werden der öffentliche
Connector und der Task mit realer Moodle-Datenbank: 16-KiB-Request-/Streaminglimit,
normaler/ungültiger JSON-Inhalt, Redirect-/HTTP-Fehler, Moodle-Hostblockade,
unvertrautes und explizit vertrautes TLS-Zertifikat sowie ein echter 30-Sekunden-
Timeout. Deshalb dauert die Suite mindestens 30 Sekunden. Der Test-CA-Bundle
liegt nur vorübergehend im isolierten PHPUnit-Datenverzeichnis. Keine private
Helper-Methode wird direkt getestet. Die gemeinsame Transportgrenze wird über
Ollama geprüft; ein realer Anthropic-Aufruf ist kein lokaler Nachweis.

Entwicklungsbelege, Rot-/Grün-Läufe, Gate-/Vollscan und Reviewprotokolle:
`/tmp/questionfilter-spec2-issue12/`. CI für den finalen Commit bleibt ein
zusätzlicher Pflichtnachweis, insbesondere Moodle 5.1 mit MariaDB.

## Cookie-Bewertungen und Upgrade (#10)

`request_test` ruft die Bewertungs- und Lese-API über den echten AJAX-Endpunkt auf:
anonymer Besucher ohne Bewertungs-Cookie, erneuter Aufruf mit demselben Cookie,
andere Frage, neuer Browser am selben Pool-Konto und mehrere Aufrufe im ersten
AJAX-Batch. Fehlender/falscher Sesskey, GET/PUT, Sterne außerhalb 1–5,
unbekannte Frage und fehlendes Blockrecht dürfen keine Fachdaten ändern.
Mitgesendete alte Kennungen wählen keine andere Identität. Cookie-Header werden
auf SHA-256-Format, Laufzeit und Sicherheitsattribute geprüft; kein Browser- oder
HTTPS-Transporttest. Kursfilter-Helper aus `main` (`811973e`) bleiben unverändert.

`rating_upgrade_test` baut ausschließlich in der eigenen PHPUnit-Datenbank das
alte Schema auf und führt den echten Upgrade-Einstieg aus, einmal leer und einmal
mit Konto-/Gastbewertungen einschließlich doppelter alter Kennung. Sterne,
Frage und Erstellungszeit bleiben erhalten; `userid`, `sessionhash` und
`timemodified` entfallen. Altbewertungen erhalten `cookiehash = NULL`: Sie zählen
weiter im Durchschnitt, sind aber keinem heutigen Besucher zugeordnet. Der
Eindeutigkeitsindex schützt neue Bewertungen je Frage und Cookie. Die installierte
Tabellenstruktur wird auch bei einem Testfehler wiederhergestellt.

**Vor Deploy zwingend extern:** Altbestand im Live-Hub prüfen und im PR belegen.
Lokale Fixtures ersetzen diesen Nachweis nicht; #10 bleibt bis dahin offen.
Die Privacy-Metadaten (#11, unten) deklarieren auch das neue Ereignis `question_rated`. Das Ereignis enthält Frage und Sterne, keine Cookie-Kennung; Moodle-Logs
können dennoch Konto/IP zuordnen. Belege: `/tmp/questionfilter-spec2-issue10/`,
dauerhafte ignorierte Sicherung `.issue10-evidence.tar.gz` und `.issue10-resume.md`.

## Privacy-Provider (#11)

`tests/privacy/provider_test.php` nutzt Moodles Provider-Testbasis (`core_privacy\tests\provider_testcase`) in der Datenbank der Testinstallation, ohne Webserver:

- Metadaten: Bewertungstabelle (Spalten ohne Konto), Cookie `kf_rater_id`, `core_files`, `core_cache`, Logstore-Link, Claude- und Ollama-Datenfluss. Jeder Beschreibungsschlüssel existiert in `lang/en` und `lang/de`; beide Dateien haben dieselben `privacy:`-Schlüssel.
- Kontobezug: zwei Konten exportieren echt über `question_export::create`. Kontextliste und Nutzerliste enthalten nur diese Konten; ein drittes Konto ohne Export bleibt außen vor.
- Export schreibt nur die Datei des anfragenden Kontos. Löschung je Konto, je Nutzerliste und für den Kontext lässt fremde Dateien (außer bei Kontextlöschung) und andere Kontexte unberührt.
- Bewertungen bleiben bei jeder Löschung bestehen: Sie haben keinen Kontobezug (ADR 0003, Abschnitt Privacy-Provider).

Nicht getestet: Export/Löschung der Logstore-Zeilen (Logstore-Plugin), Cache-Ablauf und ein realer KI-Aufruf.

Belege: `/tmp/questionfilter-spec2-issue11/`.

## Sichtbarkeit, Debug-Ausgabe und Profilsperre (#13)

- `guest_preview.php` und `rate_question` verlangen `question_visibility::is_visible()` (bereit, keine Unterfrage, für den Aufrufer sichtbar wie in der Suche). Unbekannte und nicht sichtbare Fragen nehmen kein Pool-Konto und ändern keine Anmeldung (`test_preview_of_a_question_the_visitor_cannot_see_takes_no_pool_account`, `test_preview_of_a_question_a_real_account_cannot_see_keeps_the_login_and_skips_the_preview`) und speichern keine Bewertung (`test_rating_rejects_invalid_requests_without_changing_domain_data`, einschließlich manipulierter Parametertypen). Testfixtures machen eine Kategorie über `make_category_visible()` für Gast und Standardnutzer sichtbar.
- Die Request-Tests laufen mit Entwickler-Debugging (`debug`, `debugdisplay`) und prüfen jede Seitenantwort (`preview_request`) auf Debug-Meldungen und angezeigte PHP-Hinweise. AJAX-Antworten sind ausgenommen: Moodle zeigt dort nie Debugging an (`NO_DEBUG_DISPLAY`). Die Tests laufen mit dem Tabellenpräfix `phpu_` statt `mdl_`.
- `test_pool_account_is_kept_off_profile_pages_and_a_real_account_is_not` prüft die Profilsperre des Kursfilters in der kombinierten Installation. Die Sperre selbst gehört dem Kursfilter.

## Review-Fixes zum Gesamtreview (Spec #2)

- `get_ratings` filtert die angefragten IDs über `question_visibility::visible_ids()` auf die für den Aufrufer sichtbaren Fragen; nicht sichtbare IDs erhalten dieselbe Nullantwort wie unbekannte. Höchstens `MAX_RATING_IDS` (200) verschiedene IDs, mehr wird abgewiesen (`test_get_ratings_treats_questions_the_visitor_cannot_see_like_unknown_ones`, `test_get_ratings_accepts_at_most_200_distinct_question_ids`). Die Fixtures legen Bewertungen direkt in der Tabelle an, auch unter dem eigenen Cookie des Besuchers.
- Die Anfragebegrenzung der Suche zählt je Konto und Netzwerkadresse (`getremoteaddr()`); der API-Test setzt `$_SERVER['REMOTE_ADDR']` und prüft anonyme Besucher sowie ein wiederverwendetes Konto (`search_export_test::test_search_rate_limit_is_kept_per_account_and_network_address`).
- `external::get_question_preview` und `question_preview` waren nicht als Webservice registriert und hatten keinen Aufrufer außer ihrem Test; sie sind entfernt. Die Vorschau läuft über `guest_preview.php` und die Core-Vorschau.
- Nach der Versionserhöhung die Test-Instanzen gezielt upgraden und `init.php` erneut ausführen (siehe oben).

## Release (#13)

Reihenfolge nach Vereins-Arbeitsablauf: Entwicklung abschließen, `moodle-plugin-ci phpcbf` (muss „No violations“ melden), erst danach die Build-Nummer `YYYYMMDDNN` in `version.php` setzen, Moodle-Upgrade, alle Checks und Suiten, Semgrep-Gate, CI. Jede Codeänderung danach verlangt eine neue Build-Nummer und einen neuen Durchlauf.

Paket aus dem geprüften Commit (Zip-Name `<Komponente>_<Build>.zip`, Wurzelverzeichnis `fragenfilter/` wie das Blockverzeichnis; Repo-Werkzeuge ohne Laufzeitwirkung bleiben draußen, ebenso `docs/`, damit kein veralteter Fallplan im Paket liegt). Das Paket wird nicht committet:

```bash
git archive --format=zip --prefix=fragenfilter/ -o /tmp/block_fragenfilter_<build>.zip <commit> \
  -- . ':(exclude).github' ':(exclude).semgrepignore' ':(exclude)AGENTS.md' ':(exclude)CLAUDE.md' ':(exclude)docs'
```

Installationsnachweis in einer frischen, isolierten Moodle-Instanz (eigene Datenbank, eigenes Datenverzeichnis, `block_kursfilter` von `main`): Zip nach `public/blocks/` entpacken, `admin/cli/install_database.php`, danach `admin/cli/upgrade.php` ohne Fehler und `core_plugin_manager` meldet `block_fragenfilter` mit der Build-Nummer.
