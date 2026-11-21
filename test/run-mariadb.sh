#!/usr/bin/env bash
set -euo pipefail

source_db=${1:-}
if [[ ! $source_db =~ ^[a-zA-Z0-9_]+$ ]]; then
    echo 'Pass the local Director database name to copy.' >&2
    exit 2
fi

test_db="director_rollback_test_$(date +%s%N)"
mariadb -e "CREATE DATABASE \`$test_db\`"
trap 'mariadb -e "DROP DATABASE \`$test_db\`"' EXIT
mariadb-dump --single-transaction --skip-lock-tables "$source_db" | mariadb "$test_db"

for step in prepare full prepare partial stale atomic full prepare-restore restore; do
    php "$(dirname "$0")/DirectorIntegrationTest.php" "$test_db" "$step"
done
