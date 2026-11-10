#!/bin/sh
# Guard: block_fragenfilter must not depend on block_kursfilter (ADR 0004).
# Fails if version.php declares dependencies or any tracked file outside docs/
# mentions the component name block_kursfilter.
set -eu
cd "$(git rev-parse --show-toplevel)"
fail=0
if grep -nE 'dependencies|kursfilter' version.php; then
    echo "::error file=version.php::version.php darf keine Abhängigkeit auf block_kursfilter enthalten."
    fail=1
fi
if git grep -nI 'block_kursfilter' -- . ':!docs' ':!.github/scripts/check-no-kursfilter.sh'; then
    echo "::error::block_kursfilter darf ausserhalb von docs/ nicht vorkommen."
    fail=1
fi
exit $fail
