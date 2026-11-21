# Contributing

## Report a bug or propose a feature

Use [GitHub Issues](https://github.com/DanielVd/icingaweb2-module-director-rollback/issues) for reproducible bugs, project-specific usage questions, and feature requests. Describe what you were trying to do and provide the relevant versions. For a feature, explain the operator workflow and expected behavior for pending changes and the active deployment. Follow the [security policy](SECURITY.md) for suspected vulnerabilities. Redact credentials, monitoring targets, and customer data from public reports.

## Local checks

Use PHP, Node.js, Python 3.9 or later, Bash, and Git from your development workstation. There are no Composer or npm install steps. The documented [tested stack](README.md#requirements) applies to integration and GUI testing.

From the repository root, run the same commands as the validation workflow.

```sh
php -l configuration.php
find application library test -type f \( -name '*.php' -o -name '*.phtml' \) -exec php -l '{}' +
node --check public/js/module.js
bash -n test/run-mariadb.sh
php test/RollbackPlanTest.php
node test/PartialSelectionTest.js
python3 test/check-doc-links.py
```

Match [EditorConfig](.editorconfig) and surrounding code. PHP uses the Icinga module namespace and views. Keep changes to selection logic consistent across the PHP planner and the JavaScript UI. Use the server-side planner as the authority for dependencies and ordering.

## Integration tests

Use a dedicated test installation with Icinga Web 2, Director, a reachable Icinga 2 deployment endpoint, and MariaDB socket access with permission to copy the source database and create and drop the test schema. The source must have a successful active deployment and an empty rollback plan. Run from a checkout available on that host and substitute the source database name if needed.

```sh
test/run-mariadb.sh director
```

The runner copies the source database into a temporary schema and drops it on exit. It uses the configured Director deployment endpoint to read the active stage. It covers full and partial rollback, dependency handling, stale confirmation, transactional aborts, and object restoration. Hosted CI runs the standalone checks. Use the integration suite and browser checks when changing planning, forms, redirects, or persistence. Record the tested stack and explain any skipped checks.

## Pull requests

1. Start a focused branch from `main`. Use [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) and keep unrelated changes out of the diff.
2. Update the relevant documentation and [unreleased changelog](CHANGELOG.md#unreleased) for user-facing changes.
3. Use the pull request template. Explain why the change is needed, how you verified it, and any permissions, data, or compatibility implications.
4. Reviewers should check rollback scope, authorization, dependency handling, and failure paths when they are affected. Follow the [release process](docs/release-process.md) for version and publication changes.
