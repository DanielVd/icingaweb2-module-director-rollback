# Changelog

This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and uses [Semantic Versioning](https://semver.org/). Versions before 1.0.0 are preview versions.

## [Unreleased]

### Added

- Preview pending Director changes against the active deployment and review field-level diffs.
- Apply a full rollback or a dependency-aware partial selection in a Director database transaction.
- Group pending hosts with their services and service sets with their members during selection.
- Add contributor, security, and release guidance and GitHub Actions validation.

### Changed

- Start object diffs collapsed in both rollback reviews.
- Open the Director **Deployments** list after either rollback succeeds when the operator has `director/deploy`. Applying a rollback leaves the active deployment in place.

[Unreleased]: https://github.com/DanielVd/icingaweb2-module-director-rollback/compare/main...feat/rollback-preview
