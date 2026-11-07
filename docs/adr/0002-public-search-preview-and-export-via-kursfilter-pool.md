# Öffentliche Suche; Vorschau und Export über Kursfilter-Pool-Konten

Besucher ohne Konto **suchen** Fragen anonym (Capability `block/fragenfilter:view`, Archetyp `guest`). **Vorschau** und **Export** laufen über ein Pool-Konto des Kursfilters: Die Pool-Konten tragen zusätzlich die Fragenfilter-Vorschaurolle mit `block/fragenfilter:export`. Wer eine Vorschau startet, kann danach exportieren. Das ist gewollt: Der Materialien-Hub ist zum Teilen von Material da; die Schutzgrenze ist das Pool-Konto, nicht ein echtes Konto.

Fragenfilter baut dafür **keinen eigenen Pool-Mechanismus**, sondern nutzt den des Kursfilters (dort ADR 0002: Belegung je Besucher, nur `POST` mit Sesskey, höchstens eine Vorschau je IP, Sperre von Profil-/Kennwortseiten). Fragenfilter hängt deshalb von `block_kursfilter` ab. Beide Plugins doppelt zu bauen hätte zwei Schutzlogiken mit getrennten Fehlern bedeutet; ein gemeinsames Plugin hätte zwei getrennte Funktionen vermischt. Die Richtung der Abhängigkeit ist praktisch gleichgültig, weil beide nur gemeinsam im Materialien-Hub laufen ([0001](0001-fragenfilter-runs-only-in-the-materials-hub.md)).

- Ohne Pool-Konto oder echte Anmeldung ist kein Export möglich.
- Eine echte Anmeldung wird nie durch ein Pool-Konto ersetzt.
- Die eigene Pool-Auswahl in `guest_preview.php` und das Präfix `questionfilter_guest` entfallen.

## Consequences

Ein Fehler in der Pool-Logik des Kursfilters betrifft beide Plugins; Negativtests für Pool-Grenzen liegen beim Kursfilter, Fragenfilter testet nur die eigenen Grenzen (Export ohne Pool-Konto, fremder Kontext). Soll Export nur noch echten Konten offenstehen, verliert die Vorschaurolle `block/fragenfilter:export` und diese ADR wird abgelöst.
