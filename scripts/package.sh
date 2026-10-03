#!/usr/bin/env bash
#
# Build the OpenCart 4.x artifacts, reproducibly: dist/tack.ocmod.zip (the
# installer) and dist/tack-opencart-source.zip (source archive only).
#
# Split out of the hub repository's scripts/package-all.sh
# (https://github.com/ackm04/tack-ecommerce-extensions) when this extension moved
# to its own repository.
#
# The developer guide is explicit: "you must not zip the folder `Test module/`
# but the inside files directly (so when you open your zip file you will see
# install.json, admin/, catalog/)". There is NO `upload/` wrapper in 4.x -- that
# is the 3.x/core-distribution convention and is not stripped by the 4.x
# installer.
#
# The FILENAME is load-bearing: "a folder will be created into the extension/
# directory based on the name of your file". Every namespace and event action
# hard-codes `tack`, so this MUST stay `tack.ocmod.zip` -- any other name installs
# cleanly and then 404s every route with nothing in the log.
#
# marketplace/ is a listing kit for opencart.com; no doc says the installer reads
# it, and extension/ is web-served, so 1.5 MB of PNGs are not shipped.
#
# Usage: scripts/package.sh [outdir]     (default: dist)

set -Eeuo pipefail

OUT="${1:-dist}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
rm -rf "$OUT" && mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# Excluded from every artifact. `-x` patterns are matched by zip against the
# paths as it stores them, so they are relative to the staged tree.
COMMON_EX=( -x '*/.git/*' -x '*/.gitignore' -x '*/.DS_Store' -x '*/__MACOSX/*' -x '*/._*' )

say() { printf '  %s
' "$*"; }

# stage_repo <dest> -- copy this repository's working tree to $STAGE/<dest>,
# minus the repository scaffolding that was never part of the extension when it
# lived in the hub monorepo (git metadata, build output, this script, the CI
# workflows, the repo-level LICENSE and .gitignore). Keeps the
# artifact to the same file set the monorepo's package-all.sh shipped.
stage_repo() {
  local d="$STAGE/$1"
  rm -rf "$d"; mkdir -p "$(dirname "$d")"
  cp -R "$ROOT" "$d"
  rm -rf "$d/.git" "$d/dist" "$d/scripts" "$d/.github/workflows" "$d/LICENSE" "$d/.gitignore" "$d/vendor"
  rmdir "$d/.github" 2>/dev/null || true
  find "$d" -name '.DS_Store' -delete 2>/dev/null || true
}

pack() { # pack <zipname> <top-level-dir> [extra zip -x args...]
  local name="$1" top="$2"; shift 2
  ( cd "$STAGE" && zip -q -r -X "$OUT/$name" "$top" "${COMMON_EX[@]}" "$@" )
  say "$name  $(wc -c < "$OUT/$name" | tr -d ' ') bytes"
}

say "opencart"
rm -rf "$STAGE/oc" && mkdir -p "$STAGE/oc"
for p in admin catalog system install.json; do cp -R "$ROOT/$p" "$STAGE/oc/"; done
find "$STAGE/oc" -name '.DS_Store' -delete 2>/dev/null || true
( cd "$STAGE/oc" && zip -q -r -X "$OUT/tack.ocmod.zip" . -x '.DS_Store' -x '__MACOSX/*' )
say "tack.ocmod.zip  $(wc -c < "$OUT/tack.ocmod.zip" | tr -d ' ') bytes"

# Source archive only -- CANNOT be installed (the guide requires the name to end
# in .ocmod.zip). Named `-source` so it cannot be mistaken for the installer,
# which matters precisely because a wrongly-named zip fails silently. Wrapped in
# an `opencart/` folder, as it always has been.
stage_repo opencart
pack tack-opencart-source.zip opencart -x 'opencart/marketplace/*'

echo
echo "artifacts in $OUT:"
ls -1 "$OUT"
