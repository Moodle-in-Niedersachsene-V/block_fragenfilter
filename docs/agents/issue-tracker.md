# Issue tracker: GitHub

Issues und Spezifikationen liegen als GitHub Issues in `Moodle-in-Niedersachsene-V/block_questionfilter` (origin). `gh` CLI für alle Operationen nutzen.

## Conventions

- **Issue anlegen**: `gh issue create --title "..." --body "..."`. Heredoc für mehrzeilige Bodies.
- **Issue lesen**: `gh issue view <number> --comments`.
- **Issues listen**: `gh issue list --state open --json number,title,body,labels,comments --jq '[.[] | {number, title, body, labels: [.labels[].name], comments: [.comments[].body]}]'` mit passenden `--label`/`--state` Filtern.
- **Kommentieren**: `gh issue comment <number> --body "..."`
- **Labels setzen/entfernen**: `gh issue edit <number> --add-label "..."` / `--remove-label "..."`
- **Schließen**: `gh issue close <number> --comment "..."`

`gh` erkennt das Repo automatisch über `git remote -v` (origin).

## Pull requests as a triage surface

**PRs as a request surface: no.** _(Auf `yes` setzen, wenn externe PRs als Anfragen gelten; `/triage` liest dieses Flag.)_

## "publish to the issue tracker"

GitHub Issue im origin-Repo anlegen.

## "fetch the relevant ticket"

`gh issue view <number> --comments`
