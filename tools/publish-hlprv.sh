#!/usr/bin/env bash
# A kész Mandala témát (dist/mandala-tema.zip) a hlprv „claude/mandala-site” ágára tükrözi (2026-11-02, egységes
# szállítás: hlprv deploy/README.md). Onnan a hlprv site-releases.yml kiadja (mandala-v<verzió>, mandala.zip), és a
# mandala.hu alap témája (iu_theme ≥ 0.3.0) a deploy/sites.json mandala.live.version szerint frissíti.
#   python3 tools/build-theme.py && tools/publish-hlprv.sh
# HLPRV_DIR: a hlprv klónja (alapból ../hlprv). Az élesbe menő verziót a sites.json-ban külön kell átírni.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
HLPRV=${HLPRV_DIR:-$ROOT/../hlprv}
ZIP=$ROOT/dist/mandala-tema.zip
BRANCH=claude/mandala-site
[ -f "$ZIP" ] || { echo "Nincs $ZIP – előbb: python3 tools/build-theme.py" >&2; exit 1; }
VERSION=$(unzip -p "$ZIP" mandala/style.css | sed -nE 's/^[[:space:]*]*Version:[[:space:]]*([0-9.]+).*/\1/p' | head -1)
echo "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$' || { echo "A verzió nem X.Y.Z: $VERSION" >&2; exit 1; }
WORK=$(mktemp -d)
trap 'git -C "$HLPRV" worktree remove --force "$WORK" >/dev/null 2>&1 || rm -rf "$WORK"' EXIT
git -C "$HLPRV" fetch -q origin "$BRANCH"
git -C "$HLPRV" worktree add -q --detach "$WORK" "origin/$BRANCH"
rm -rf "$WORK/mandala/wp-theme/mandala"
mkdir -p "$WORK/mandala/wp-theme"
unzip -q "$ZIP" -d "$WORK/mandala/wp-theme"
cd "$WORK"
git add -A mandala
if git diff --cached --quiet; then echo "Nincs változás ($VERSION)."; exit 0; fi
git commit -q -m "Mandala $VERSION (forrás: mdn $(git -C "$ROOT" rev-parse --short HEAD))"
for i in 1 2 3 4; do git push -q origin "HEAD:$BRANCH" && break; sleep $((2 ** i)); done
echo "Tükrözve: $BRANCH ← $VERSION. Kiadás: óránként, vagy hlprv Actions → „Ügyfél-témák kiadása” (ág: $BRANCH)."
