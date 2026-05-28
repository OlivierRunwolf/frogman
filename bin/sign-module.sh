#!/usr/bin/env bash
#
# Produce a FreePBX-compatible module.sig for a module directory.
#
# Usage:   sign-module.sh <module-dir> <keyid>
# Example: sign-module.sh . 021979A8A1002442
#
# FALLBACK / LOCAL-DEV ONLY. The canonical signing tool is
# FreePBX/devtools/sign.php (https://github.com/FreePBX/devtools), which
# is what the release workflow uses. This script reproduces sign.php's
# output byte-for-byte (version=2, signedby=sign.php, repo=manual,
# type=public, `;# End` marker) for cases where PHP isn't available.

set -euo pipefail

MODULE_DIR="${1:-}"
KEYID="${2:-}"

if [[ -z "$MODULE_DIR" || -z "$KEYID" ]]; then
  echo "Usage: $0 <module-dir> <keyid>" >&2
  exit 1
fi

if [[ ! -d "$MODULE_DIR" ]]; then
  echo "Not a directory: $MODULE_DIR" >&2
  exit 1
fi

if [[ ! -f "$MODULE_DIR/module.xml" ]]; then
  echo "module.xml not found in $MODULE_DIR" >&2
  exit 1
fi

MODULE_DIR="$(cd "$MODULE_DIR" && pwd)"

sha256_of() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | awk '{print $1}'
  else
    shasum -a 256 "$1" | awk '{print $1}'
  fi
}

SIG_FILE="$MODULE_DIR/module.sig"
TMPBODY="$(mktemp)"
trap 'rm -f "$TMPBODY"' EXIT

{
  echo ";################################################"
  echo ";#        FreePBX Module Signature File         #"
  echo ";################################################"
  echo ";# Do not alter the contents of this file!  If  #"
  echo ";# this file is tampered with, the module will  #"
  echo ";# fail validation and be marked as invalid!    #"
  echo ";################################################"
  echo ""
  echo "[config]"
  echo "version=2"
  echo "hash=sha256"
  echo "signedwith=$KEYID"
  echo "signedby=sign.php"
  echo "repo=manual"
  echo "type=public"
  echo "[hashes]"

  # Walk files relative to MODULE_DIR, skip any path with a dot-prefixed
  # component (mirrors GPG.class.php::recurseDirectory) and skip module.sig.
  (cd "$MODULE_DIR" && find . -type f \
      ! -path '*/.*' \
      ! -name 'module.sig' \
      ! -name '._*' \
      ! -name '.DS_Store' \
      -print0 \
    | sort -z \
    | while IFS= read -r -d '' f; do
        rel="${f#./}"
        echo "$rel = $(sha256_of "$MODULE_DIR/$rel")"
      done)

  echo ";# End"
} > "$TMPBODY"

gpg --yes \
    --local-user "$KEYID" \
    --clearsign \
    --output "$SIG_FILE" \
    "$TMPBODY"

echo "Signed: $SIG_FILE"
