#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

echo "== PHP syntax =="
find . -maxdepth 5 -name '*.php' -not -path './reference/*' -print0 | xargs -0 -n1 php -l

echo
echo "== Offline mapper smoke =="
php tests/xml_mapper_smoke.php

echo
echo "Validation passed."
