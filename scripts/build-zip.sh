#!/usr/bin/env bash
# Builds dist/mindful-import-<version>.zip with a top-level mindful-import/ folder (the WordPress slug).
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' plugin/mindful-import.php | head -n1)
[ -n "$VERSION" ] || { echo "No Version: header in plugin/mindful-import.php" >&2; exit 1; }

OUT="dist/mindful-import-$VERSION.zip"
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

mkdir -p dist "$STAGE/mindful-import"
rsync -a --exclude 'INTERFACES.md' --exclude '.DS_Store' plugin/ "$STAGE/mindful-import/"
rm -f "$OUT"
(cd "$STAGE" && zip -qr -X "$OLDPWD/$OUT" mindful-import)
echo "$OUT"
