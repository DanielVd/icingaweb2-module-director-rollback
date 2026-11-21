# Repository guidance

Icinga Director Rollback is a source-only Icinga Web 2 module. It reads the Director Activity Log and applies reviewed rollback plans to Director's working configuration. Read the [architecture overview](docs/architecture-and-operations.md) before changing rollback behavior.

## Repository map

- `application/` contains the Icinga Web 2 controller and PHP views.
- `library/Director_rollback/` contains snapshot, planning, dependency, diff, and transaction logic.
- `configuration.php` integrates the module menu and permission. `module.info` declares the module ID, version, and Director requirement.
- `public/` contains JavaScript and LESS loaded by Icinga Web 2.
- `test/` contains standalone checks and the disposable MariaDB integration suite.
- `.github/` contains validation workflows and contribution templates.

## Development and validation

Follow the [contributor commands](CONTRIBUTING.md#local-checks) for PHP syntax, JavaScript, Bash, and standalone tests. There is no build or project package installation. Use the verified setup in the [README](README.md#requirements) for end-to-end work. Use a dedicated test instance for [integration tests](CONTRIBUTING.md#integration-tests).

- Inspect the worktree, relevant code paths, and tests before writing. Preserve existing changes and keep edits scoped.
- Use four spaces in PHP, PHTML, JavaScript, and LESS. Match adjacent code and follow [EditorConfig](.editorconfig). Keep PHP namespaces and Icinga module paths consistent.
- Reuse Icinga Web 2 and Director interfaces already in use. Add runtime dependencies only for a demonstrated need and document the dependency and why it is needed.
- Keep permission checks, Icinga form validation, stale-plan checks, dependency validation, and transactional safeguards in place. Client-side JavaScript assists selection. The server controls what is applied.
- Keep credentials, private reports, raw configuration exports, and production data out of commits, examples, and logs.
- Ask for approval before changing live deployments, live application data, repository settings, license terms, or release state, or performing destructive operations. Handle GitHub writes only within the current task's authorization.
- Update docs and the unreleased changelog when the user-facing behavior changes. Add focused regression coverage when existing tests miss the issue.

## Git and completion

Base new feature work on `main` and use a focused branch. Follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/). For commits created by Codex on behalf of the maintainer, use author `Daniel Vedovato <daniel.vedovato@gmail.com>` and trailer `Co-authored-by: Codex <267193182+codex@users.noreply.github.com>`. Contributors retain their own authorship.

Use the [pull request checklist](CONTRIBUTING.md#pull-requests). Work is ready for review when the diff is scoped, applicable checks have run, documentation matches behavior, and skipped checks and remaining risks are reported. Record only results actually observed. Follow [SECURITY.md](SECURITY.md) for private reports.
