<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * German language strings for block_fragenfilter.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['action_approve'] = 'Freigeben';
$string['action_approveall'] = 'Alle {$a} freigeben';
$string['action_reject'] = 'Ablehnen';
$string['action_rejectall'] = 'Alle ablehnen';
$string['addtoquiz'] = 'Zum Test';
$string['ai_backend_claude'] = 'Anthropic Claude API';
$string['ai_backend_ollama'] = 'Ollama (lokal)';
$string['approved'] = 'Tag-Vorschlag wurde freigegeben.';
$string['approved_all'] = '{$a} Tag-Vorschläge wurden freigegeben.';
$string['block_fragenfilter:addinstance'] = 'Block „Fragenfilter" hinzufügen';
$string['block_fragenfilter:export'] = 'Fragen exportieren';
$string['block_fragenfilter:myaddinstance'] = 'Block „Fragenfilter" zum Dashboard hinzufügen';
$string['block_fragenfilter:reviewtags'] = 'KI-Tag-Vorschläge prüfen und freigeben';
$string['block_fragenfilter:view'] = 'Fragenfilter-Block verwenden';
$string['btn_reset'] = 'Zurücksetzen';
$string['categories'] = 'Fragesammlungen';
$string['col_actions'] = 'Aktionen';
$string['col_question'] = 'Frage';
$string['col_tags'] = 'Tags';
$string['col_type'] = 'Typ';
$string['confirm_approveall'] = 'Wirklich alle ausstehenden Vorschläge freigeben?';
$string['confirm_rejectall'] = 'Wirklich alle ausstehenden Vorschläge ablehnen?';
$string['coresystem'] = 'Systemweit';
$string['ctxbank'] = '{$a->course} › {$a->name}';
$string['ctxcategory'] = 'Kategorie: {$a}';
$string['ctxcategoryid'] = 'Kategorie #{$a}';
$string['ctxcontextid'] = 'Kontext #{$a}';
$string['ctxcoursedefault'] = 'Kurs';
$string['ctxcourseid'] = 'Kurs #{$a}';
$string['ctxmoduleid'] = 'Modul #{$a}';
$string['ctxquiz'] = '{$a->course} › Quiz: {$a->name}';
$string['difficulty'] = 'Niveaustufen';
$string['entertosearch'] = 'Filter setzen, um Fragen zu suchen.';
$string['error_ai_answer_invalid'] = 'KI-Antwort enthält kein gültiges JSON-Objekt: {$a}';
$string['error_ai_key'] = 'Vor Nutzung dieses Anbieters den Claude-API-Schlüssel konfigurieren.';
$string['error_ai_request_failed'] = 'KI-Anfrage fehlgeschlagen: {$a}';
$string['error_ai_request_limit'] = 'KI-Anfrage überschreitet 16 KiB. Titel oder konfigurierte Kategorien/Modell kürzen.';
$string['error_ai_response'] = 'Ungültige KI-Antwortstruktur. Konfigurierten Anbieter prüfen.';
$string['error_ai_response_limit'] = 'KI-Antwort überschreitet 16 KiB. Konfigurierten Anbieter prüfen.';
$string['error_ai_tagging_failed'] = 'KI-Verschlagwortung: {$a->failed} von {$a->total} Fragen konnten nicht verarbeitet werden.';
$string['error_ai_target'] = 'Ungültiges KI-Ziel. Eine HTTP(S)-Dienstadresse ohne Zugangsdaten, Query oder Fragment verwenden.';
$string['error_ai_transport'] = 'KI-Anfrage fehlgeschlagen oder Status ungleich 200. Anbieter, TLS-Zertifikat und Moodle-HTTP-Sicherheitseinstellungen prüfen.';
$string['error_ai_unavailable'] = 'KI-Verschlagwortung ist aktiviert, aber kein KI-Anbieter ist in Moodle konfiguriert (Website-Administration → KI → KI-Anbieter).';
$string['error_preview_needs_post'] = 'Starte die Vorschau über die Vorschau-Schaltfläche.';
$string['error_review_needs_post'] = 'Tag-Prüfaktionen benötigen eine POST-Anfrage.';
$string['error_review_unavailable'] = 'Dieser Vorschlag ist nicht verfügbar oder Sie dürfen seine Frage nicht prüfen.';
$string['event_question_rated'] = 'Frage bewertet';
$string['event_suggestion_reviewed'] = 'KI-Tag-Vorschlag geprüft';
$string['export_csv'] = 'CSV-Tabelle';
$string['export_gift'] = 'GIFT-Format';
$string['export_xml'] = 'Moodle XML';
$string['exportfailed'] = 'Export fehlgeschlagen. Bitte Moodle-Logs prüfen.';
$string['exportformatdisabled'] = 'Das angeforderte Exportformat ist deaktiviert oder unbekannt.';
$string['exportunsupportedquestion'] = 'Eine ausgewählte Frage kann in diesem Format nicht exportiert werden.';
$string['fragenfilter:addinstance'] = 'Block „Fragenfilter" hinzufügen';
$string['fragenfilter:export'] = 'Fragen exportieren';
$string['fragenfilter:myaddinstance'] = 'Block „Fragenfilter" zum Dashboard hinzufügen';
$string['fragenfilter:reviewtags'] = 'KI-Tag-Vorschläge prüfen und freigeben';
$string['fragenfilter:view'] = 'Fragenfilter-Block verwenden';
$string['invalidquestion'] = 'Frage nicht gefunden.';
$string['loading'] = 'Wird geladen …';
$string['loadingcategories'] = 'Sammlungen werden geladen …';
$string['no_tags'] = '(keine Tags)';
$string['nopermission'] = 'Sie haben keine Berechtigung, die Fragebank einzusehen.';
$string['noquestionsselected'] = 'Keine Fragen ausgewählt.';
$string['pluginname'] = 'Fragenfilter';
$string['preview_back_btn'] = 'Zurück zur Startseite';
$string['preview_in_use'] = 'Für diese IP-Adresse läuft bereits eine Vorschau. Bitte zuerst dort abmelden.';
$string['preview_open_btn'] = 'Vorschau öffnen:';
$string['preview_pool_full'] = 'Aktuell sind alle Pool-Konten belegt. Bitte versuchen Sie es in einigen Minuten erneut.';
$string['preview_ready_desc'] = 'Sie sind jetzt angemeldet. Öffnen Sie die Vorschau und kehren Sie danach zur Startseite zurück um Fragen zu exportieren.';
$string['preview_ready_title'] = 'Fragenvorschau bereit';
$string['privacy:metadata'] = 'Der Fragenfilter speichert pseudonyme Bewertungen unter einem Browser-Cookie und je Konto eine temporäre Exportdatei. Bewertungs- und Prüfereignisse können in den Moodle-Logs mit Konto und IP-Adresse verknüpft sein. Die KI-Verschlagwortung sendet Fragedaten an den konfigurierten KI-Anbieter; das Kürzen des Textes anonymisiert ihn nicht.';
$string['privacy:metadata:ai:questiontext'] = 'Fragetext als Klartext, auf 800 Zeichen gekürzt. Personenbezogene Angaben im Text werden nicht entfernt.';
$string['privacy:metadata:ai:questiontitle'] = 'Der Fragename.';
$string['privacy:metadata:ai:questiontype'] = 'Der interne Fragetyp.';
$string['privacy:metadata:ai:tagcategories'] = 'Die vom Administrator konfigurierten Themenkategorien.';
$string['privacy:metadata:anthropic'] = 'Optionales KI-Backend Claude: Bei aktivierter KI-Verschlagwortung wird der Prompt an die Anthropic-API (api.anthropic.com) gesendet. Moodles KI-Subsystem wird nicht verwendet.';
$string['privacy:metadata:cache'] = 'Ein kurzlebiger Zähler zur Anfragebegrenzung je Konto und Netzwerkadresse (etwa zwei Minuten) im Anwendungscache. Der Schlüssel ist ein Hash; die Adresse selbst wird nicht gespeichert.';
$string['privacy:metadata:cache:userid'] = 'Das Konto (zusammen mit einem Hash der Netzwerkadresse), dessen Anfragen gezählt werden.';
$string['privacy:metadata:cookie'] = 'Das Cookie kf_rater_id teilt sich der Fragenfilter mit dem Kursfilter. Es liegt ein Jahr im Browser des Besuchers, wird bei jeder Bewertung verlängert und erkennt vorhandene Bewertungen.';
$string['privacy:metadata:files'] = 'Der letzte Fragenexport eines Kontos liegt als Datei im Moodle-Dateispeicher und wird vom nächsten Export dieses Kontos ersetzt.';
$string['privacy:metadata:files:filename'] = 'Die exportierten Fragen mit dem Zeitpunkt des Exports im Dateinamen.';
$string['privacy:metadata:files:userid'] = 'Das Konto, das den Export erstellt hat. Pool-Konten werden von verschiedenen Besuchern nacheinander verwendet.';
$string['privacy:metadata:logs'] = 'Moodle-Logstores zeichnen Bewertungsereignisse (Frage und Sterne, kein Cookie-Wert) und Prüfungen von Tag-Vorschlägen mit Nutzer-ID, IP-Adresse und Verweis auf die Bewertung bzw. den Vorschlag auf.';
$string['privacy:metadata:logs:ip'] = 'Die vom Logstore aufgezeichnete IP-Adresse.';
$string['privacy:metadata:logs:objectid'] = 'Die ID der Bewertung oder des Tag-Vorschlags, auf die sich das Ereignis bezieht.';
$string['privacy:metadata:logs:other'] = 'Die Sternezahl einer Bewertung oder das Ergebnis einer Prüfung.';
$string['privacy:metadata:logs:userid'] = 'Das Konto, das bewertet oder geprüft hat; bei Besuchern ohne eigenes Konto das verwendete Pool-Konto.';
$string['privacy:metadata:ollama'] = 'Optionales KI-Backend Ollama: Bei aktivierter KI-Verschlagwortung wird der Prompt an den vom Administrator konfigurierten Ollama-Server gesendet. Der Server kann extern sein.';
$string['privacy:metadata:ratings'] = 'Fragenbewertungen, nur über eine pseudonyme Browser-Kennung identifiziert. Es wird kein Konto gespeichert; Bewertungen lassen sich deshalb nicht je Konto exportieren oder löschen. Bewertungsereignisse in den Logs können eine Bewertung mit einem Konto verknüpfen.';
$string['privacy:metadata:ratings:cookiehash'] = 'Zufällige SHA-256-Browser-Kennung, die Cookie und gespeicherte Bewertungen gemeinsam verwenden. Altbewertungen haben keine.';
$string['privacy:metadata:ratings:questionid'] = 'Die bewertete Frage.';
$string['privacy:metadata:ratings:stars'] = 'Die Bewertung von einem bis fünf Sternen.';
$string['privacy:metadata:ratings:timecreated'] = 'Der Zeitpunkt der Bewertung.';
$string['privacy:path:export'] = 'Fragenexporte';
$string['qtypes_existing'] = 'Nur Typen mit vorhandenen Fragen';
$string['qtypes_installed'] = 'Alle installierten Fragetypen';
$string['questiontypes'] = 'Fragetyp';
$string['ratelimitexceeded'] = 'Zu viele Anfragen. Bitte warten Sie {$a->seconds} Sekunden.';
$string['rejected'] = 'Tag-Vorschlag wurde abgelehnt.';
$string['rejected_all'] = 'Alle ausstehenden Tag-Vorschläge wurden abgelehnt.';
$string['review_empty'] = 'Keine ausstehenden Tag-Vorschläge vorhanden.';
$string['review_title'] = 'KI-Tag-Vorschläge prüfen';
$string['role_preview_desc'] = 'Rolle für die Pool-Konten des Kursfilters. Erlaubt die Fragenvorschau (moodle/question:usemine) und den Export (block/fragenfilter:export).';
$string['role_preview_name'] = 'Fragenvorschau';
$string['scope_all'] = 'Alle Fragesammlungen (kursübergreifend)';
$string['scope_course'] = 'Nur aktueller Kurs';
$string['scope_system'] = 'Nur systemweite Fragebank';
$string['searchplaceholder'] = 'Suche … oder #tag eingeben + Enter';
$string['selectall'] = 'Alle wählen';
$string['settings_ai_autoapply'] = 'Vorschläge automatisch übernehmen';
$string['settings_ai_autoapply_help'] = 'Wenn aktiv, werden KI-Vorschläge sofort als Moodle-Tags eingetragen. Andernfalls werden sie zur manuellen Prüfung gespeichert.';
$string['settings_ai_backend'] = 'KI-Backend';
$string['settings_ai_backend_desc'] = 'Wählen Sie, ob Ollama (lokal) oder die Anthropic Claude API für die Tag-Generierung verwendet wird.';
$string['settings_ai_backend_heading'] = 'KI-Backend-Auswahl';
$string['settings_ai_backend_help'] = 'Ollama läuft lokal auf dem Server. Claude API erfordert einen Anthropic-API-Schlüssel.';
$string['settings_ai_batch_size'] = 'Fragen pro Durchlauf';
$string['settings_ai_batch_size_help'] = 'Fragen pro Task-Durchlauf, begrenzt auf 1–20. Zusätzlich Anbieterquoten konfigurieren.';
$string['settings_ai_claude_apikey'] = 'Claude API-Schlüssel';
$string['settings_ai_claude_apikey_help'] = 'Anthropic-API-Schlüssel. In der Moodle-Plugin-Konfiguration gespeichert; für Website-Administratoren und Datenbank-/Backup-Verantwortliche zugänglich. Nie in eine Ollama-URL aufnehmen.';
$string['settings_ai_claude_desc'] = 'Geben Sie Ihren Anthropic API-Schlüssel und das gewünschte Modell ein. Fragentitel und -text werden minimiert (kein Autorenname, keine Moodle-IDs), aber nicht anonymisiert übermittelt.';
$string['settings_ai_claude_heading'] = 'Claude API-Einstellungen';
$string['settings_ai_claude_model'] = 'Claude-Modell';
$string['settings_ai_claude_model_help'] = 'Modellbezeichnung, z. B. claude-haiku-4-5-20251001. Haiku ist schnell und kostengünstig für Tag-Generierung.';
$string['settings_ai_desc'] = 'Direkte KI-Tag-Vorschläge über Ollama oder Anthropic. Fragentext wird minimiert, nicht anonymisiert. Die Freigabe durch den Administrator gilt für alle Fragensammlungen.';
$string['settings_ai_enabled'] = 'KI-Verschlagwortung aktivieren';
$string['settings_ai_enabled_help'] = 'Gibt Titel und bis zu 800 Zeichen Fragentext aus allen Fragensammlungen an den konfigurierten KI-Anbieter frei. Freitext kann personenbezogene Daten enthalten; vor Aktivierung Inhalte, Anbieter und HTTP-Sicherheitseinstellungen prüfen. Standardmäßig deaktiviert.';
$string['settings_ai_heading'] = 'KI-Verschlagwortung';
$string['settings_ai_max_tags'] = 'Maximale Tag-Anzahl';
$string['settings_ai_max_tags_help'] = 'Wie viele Tags die KI pro Frage maximal vorschlagen darf (Standard: 5, begrenzt auf 1–10).';
$string['settings_ai_ollama_heading'] = 'Ollama-Einstellungen';
$string['settings_ai_ollama_model'] = 'Ollama-Modell';
$string['settings_ai_ollama_model_help'] = 'Name des Ollama-Modells, z. B. gemma3:4b oder llama3.2. Das Modell muss auf dem Ollama-Server verfügbar sein.';
$string['settings_ai_ollama_url'] = 'Ollama-URL';
$string['settings_ai_ollama_url_help'] = 'Basis-URL des Ollama-Servers, z. B. http://localhost:11434. Kein abschließender Schrägstrich. Nur HTTP(S)-URLs ohne Zugangsdaten oder Query werden verwendet, Weiterleitungen werden nicht verfolgt; interne Ziele in den HTTP-Sicherheitseinstellungen von Moodle begrenzen (gesperrte Hosts, erlaubte Ports).';
$string['settings_ai_tag_categories'] = 'Fachgebiete';
$string['settings_ai_tag_categories_help'] = 'Mögliche Fachgebiete für die KI (eine pro Zeile). Die KI wählt die passenden aus und ignoriert nicht zutreffende.';
$string['settings_ai_tagging_heading'] = 'Tagging-Optionen';
$string['settings_custom_tags'] = 'Vorgeschlagene Tags';
$string['settings_custom_tags_help'] = 'Kommagetrennte Liste von Tags, die im Block als Schnellfilter angeboten werden.';
$string['settings_customfields_heading'] = 'Benutzerdefinierte Tags';
$string['settings_difficulty_desc'] = 'Niveaustufen werden als Tag-Filter angeboten. Erweiterbar – eine Stufe pro Zeile.';
$string['settings_difficulty_heading'] = 'Niveaustufen';
$string['settings_difficulty_levels'] = 'Niveaustufen';
$string['settings_difficulty_levels_help'] = 'Eine Stufe pro Zeile, z. B. Leicht, Mittel, Schwer. Beliebig erweiterbar.';
$string['settings_export_heading'] = 'Export-Formate';
$string['settings_exportformats'] = 'Verfügbare Formate';
$string['settings_exportformats_help'] = 'Welche Export-Formate im Block angezeigt werden.';
$string['settings_questiontypes_heading'] = 'Fragetypen';
$string['settings_questiontypes_source'] = 'Angezeigte Fragetypen';
$string['settings_questiontypes_source_help'] = 'Alle installierten: zeigt alle qtype-Plugins. Nur vorhandene: zeigt nur Typen die tatsächlich Fragen in der Bank haben.';
$string['settings_resultlimit'] = 'Max. Ergebnisse';
$string['settings_resultlimit_help'] = 'Maximale Anzahl Fragen, die pro Suchanfrage zurückgegeben werden (Standard: 200).';
$string['settings_review_heading'] = 'Tag-Vorschläge prüfen';
$string['settings_review_link'] = 'Zur Prüf- und Freigabe-Seite';
$string['settings_searchscope'] = 'Suchbereich';
$string['settings_searchscope_desc'] = 'Legt fest, über welche Fragesammlungen der Block sucht.';
$string['settings_searchscope_heading'] = 'Suchbereich';
$string['settings_searchscope_help'] = 'Alle: system- und kursübergreifend. Kurs: nur der aktuelle Kurs. System: nur die systemweite Fragebank.';
$string['stat_approved'] = 'Freigegeben: {$a}';
$string['stat_pending'] = 'Ausstehend: {$a}';
$string['stat_rejected'] = 'Abgelehnt: {$a}';
$string['tagplaceholder'] = 'Tag eingeben und Enter …';
$string['tags'] = 'Tags';
$string['task_cleanup_pool_sessions'] = 'Fragenfilter: Inaktive Pool-Sitzungen bereinigen';
$string['task_generate_tags'] = 'KI-Verschlagwortung für Fragen';
