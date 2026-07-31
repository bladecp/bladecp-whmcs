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
  LICENSE README.md README.pt-BR.md CHANGELOG.md SECURITY.md CONTRIBUTING.md
  modules/servers/bladecp/bladecp.php
  modules/servers/bladecp/whmcs.json
  modules/addons/bladecp/bladecp.php
  modules/addons/bladecp/webhook.php
  tests/run.php
)
for path in "${required[@]}"; do
  [ -f "$path" ] || { echo "error: required public file is missing: $path" >&2; exit 1; }
done

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
