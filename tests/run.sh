#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
php -d zend.assertions=1 -d assert.exception=1 "$ROOT/tests/run.php"
