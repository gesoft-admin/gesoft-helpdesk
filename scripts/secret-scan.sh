#!/usr/bin/env bash
# Look for things that must not reach a public repository.
#
#   ./scripts/secret-scan.sh              # what is staged, or the whole fork
#   ./scripts/secret-scan.sh --all        # every tracked file
#
# It scans this fork's own changes, not upstream's tree: a public FreeScout is
# already public, and scanning 20k vendored files only teaches people to ignore
# the output.
#
# Install it as a guard if you want one:
#   ln -s ../../scripts/secret-scan.sh .git/hooks/pre-commit
#
# Exit status is 1 if anything matched, so it fails a hook or a CI step.

set -uo pipefail
cd "$(dirname "$0")/.."

BASE=${BASE:-upstream-1.8.239}
if [[ "${1:-}" == "--all" ]]; then
    FILES=$(git ls-files)
elif ! git diff --cached --quiet 2>/dev/null; then
    FILES=$(git diff --cached --name-only --diff-filter=ACM)
else
    FILES=$(git diff --name-only --diff-filter=ACM "$BASE"..HEAD 2>/dev/null; git ls-files -mo --exclude-standard)
fi

hits=0
report() { printf '  %-30s %s\n' "$1" "$2"; hits=$((hits+1)); }

# A committed .env is the whole game, so it is checked by name rather than by
# content: an empty one today is a full one tomorrow.
for f in $FILES; do
    case "$f" in
        .env|.env.local|.env.production|.env.*.local)
            report "environment file" "$f" ;;
    esac
done

# Patterns worth stopping for. Assigned values only: naming a variable is fine,
# giving it a value is not. Empty assignments, env() lookups and obvious
# placeholders are skipped — including upstream's own `APP_KEY=SomeRandomString7`
# in .env.travis, which is a template value and has been since before this fork.
while IFS= read -r f; do
    [[ -f "$f" ]] || continue
    # This file is skipped because its own patterns look exactly like what it
    # hunts for — it reported itself as a private key until this line existed.
    case "$f" in
        vendor/*|node_modules/*|*.min.js|*.min.css|scripts/secret-scan.sh) continue ;;
    esac

    # Process substitution, not a pipe: a `while` on the right of a pipe runs in
    # a subshell, and the finding count set there is lost when it exits. The
    # scanner reported "clean" while printing findings until this was fixed.
    while read -r l; do report "private key" "$f:${l%%:*}"; done \
        < <(grep -nE "BEGIN [A-Z ]*PRIVATE KEY|ssh-rsa AAAA" "$f" 2>/dev/null)

    while read -r l; do report "assigned credential" "$f:${l%%:*}"; done \
        < <(grep -nE "(OPS_TOKEN|API_KEY|SECRET|APP_KEY|DB_PASSWORD|MAIL_PASSWORD)[A-Z_]*[=:][[:space:]]*[\"']?[A-Za-z0-9+/_-]{8,}" "$f" 2>/dev/null \
            | grep -vE "=[[:space:]]*(''|\"\"|$)|env\(|placeholder|example|your-|<.*>|SomeRandomString")
done < <(printf "%s\n" $FILES)

if [[ $hits -gt 0 ]]; then
    echo
    echo "secret-scan: $hits finding(s). Nothing was committed."
    exit 1
fi
echo "secret-scan: clean ($(wc -w <<< "$FILES") files checked)"
