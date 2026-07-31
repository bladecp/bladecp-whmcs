#!/usr/bin/env bash
# Standalone public-package hygiene and structure gate.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

for command in find grep php python3; do
  command -v "$command" >/dev/null 2>&1 || {
    echo "error: $command is required" >&2
    exit 2
  }
done

required=(
  LICENSE README.md README.pt-BR.md README.es.md CHANGELOG.md SECURITY.md CONTRIBUTING.md
  assets/screenshots/README.md
  modules/servers/bladecp/bladecp.php
  modules/servers/bladecp/whmcs.json
  modules/addons/bladecp/bladecp.php
  modules/addons/bladecp/webhook.php
  tests/run.php
)
for path in "${required[@]}"; do
  [ -f "$path" ] || { echo "error: required public file is missing: $path" >&2; exit 1; }
done

screenshot_languages=(en pt-BR es)
screenshot_views=(overview configuration plans pricing marketplace operations-diagnostics client-service)
expected_screenshots=()
for language in "${screenshot_languages[@]}"; do
  for view in "${screenshot_views[@]}"; do
    expected_screenshots+=("assets/screenshots/$language/$view.jpg")
  done
done
for path in "${expected_screenshots[@]}"; do
  [ -f "$path" ] || { echo "error: required localized screenshot is missing: $path" >&2; exit 1; }
  signature="$(od -An -tx1 -N3 "$path" | tr -d '[:space:]')"
  [ "$signature" = "ffd8ff" ] || {
    echo "error: screenshot is not a JPEG file: $path" >&2
    exit 1
  }
done

actual_screenshots="$(find assets/screenshots -type f -name '*.jpg' -print | sed 's#^./##' | sort)"
expected_screenshot_list="$(printf '%s\n' "${expected_screenshots[@]}" | sort)"
if [ "$actual_screenshots" != "$expected_screenshot_list" ]; then
  echo "error: the public tree must contain exactly the 21 approved localized screenshots" >&2
  echo "expected:" >&2
  printf '%s\n' "$expected_screenshot_list" >&2
  echo "actual:" >&2
  printf '%s\n' "$actual_screenshots" >&2
  exit 1
fi

python3 - "${expected_screenshots[@]}" <<'PY'
import pathlib
import sys

for raw_path in sys.argv[1:]:
    path = pathlib.Path(raw_path)
    data = path.read_bytes()
    if not data.startswith(b'\xff\xd8'):
        raise SystemExit(f'error: screenshot is not a JPEG file: {path}')

    offset = 2
    while offset + 1 < len(data):
        if data[offset] != 0xFF:
            offset += 1
            continue
        while offset < len(data) and data[offset] == 0xFF:
            offset += 1
        if offset >= len(data):
            break

        marker = data[offset]
        offset += 1
        if marker in (0xD8, 0xD9) or 0xD0 <= marker <= 0xD7:
            continue
        if marker == 0xDA:
            break
        if offset + 2 > len(data):
            raise SystemExit(f'error: truncated JPEG marker in {path}')

        length = int.from_bytes(data[offset:offset + 2], 'big')
        if length < 2 or offset + length > len(data):
            raise SystemExit(f'error: invalid JPEG marker length in {path}')
        if marker in (0xE1, 0xED, 0xFE):
            kind = {0xE1: 'EXIF/XMP', 0xED: 'IPTC/Photoshop', 0xFE: 'comment'}[marker]
            raise SystemExit(f'error: prohibited {kind} metadata in {path}')
        offset += length
PY

if find . -type l -print -quit | grep -q .; then
  echo "error: symbolic links are not allowed in the public tree" >&2
  find . -type l -print >&2
  exit 1
fi

if grep -RInE --exclude-dir=.git --exclude='check-public-tree.sh' \
  '(^|[^[:alnum:]_])(DRAFT|TODO|FIXME)([^[:alnum:]_]|$)' .; then
  echo "error: editorial markers are not allowed in the public tree" >&2
  exit 1
fi

# Public prose and image metadata must never point readers to an unpublished
# environment. The module code may retain sandbox support, but public docs and
# screenshots are production-only.
documentation_files=(README.md README.pt-BR.md README.es.md CHANGELOG.md SECURITY.md CONTRIBUTING.md docs assets/screenshots/README.md)
if grep -RIniE --exclude-dir=.git '(api\\.bladecp\\.cloud|sandbox|valuehost|ulisses)' "${documentation_files[@]}"; then
  echo "error: public documentation contains a prohibited environment, third-party, or personal reference" >&2
  exit 1
fi

if grep -RInE --exclude-dir=.git --exclude='check-public-tree.sh' \
  '(bcp|vh)_(live|sand)_[A-Za-z0-9._-]{8,}|whsec_[A-Za-z0-9._-]{8,}|Authorization[[:space:]]*[:=][[:space:]]*(Bearer|Basic)[[:space:]]+[A-Za-z0-9._-]+' .; then
  echo "error: credential-like value found in the public tree" >&2
  exit 1
fi

python3 - "$ROOT" <<'PY'
import os
import re
import sys

root = os.path.abspath(sys.argv[1])
markdown = []
for base, _, names in os.walk(root):
    if '.git' in base.split(os.sep):
        continue
    for name in names:
        if name.lower().endswith('.md'):
            markdown.append(os.path.join(base, name))

pattern = re.compile(r'!?(?:\[[^\]]*\])\(([^)\s]+)(?:\s+"[^"]*")?\)')
missing = []
for path in markdown:
    text = open(path, encoding='utf-8').read()
    for match in pattern.finditer(text):
        target = match.group(1).strip('<>')
        if (not target or target.startswith('#') or target.startswith('http://')
                or target.startswith('https://') or target.startswith('mailto:')
                or target.startswith('tel:')):
            continue
        target = target.split('#', 1)[0]
        if not target:
            continue
        candidate = os.path.normpath(os.path.join(os.path.dirname(path), target))
        if not candidate.startswith(root + os.sep) or not os.path.exists(candidate):
            missing.append(f'{os.path.relpath(path, root)} -> {match.group(1)}')

if missing:
    print('error: broken local Markdown links:', file=sys.stderr)
    for item in missing:
        print(f'  {item}', file=sys.stderr)
    raise SystemExit(1)
PY

php -r '
$metadata = json_decode((string) file_get_contents("modules/servers/bladecp/whmcs.json"), true);
if (!is_array($metadata) || !isset($metadata["version"]) || !is_string($metadata["version"])) {
    fwrite(STDERR, "error: invalid module metadata\n");
    exit(1);
}
'

echo "Public package structure and hygiene checks passed."
