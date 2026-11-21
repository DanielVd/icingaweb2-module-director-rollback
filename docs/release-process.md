# Release process

The module version is recorded in [module.info](../module.info). Follow [Semantic Versioning](https://semver.org/) and keep user-facing changes under **Unreleased** in the [changelog](../CHANGELOG.md) until a release is approved. Preserve existing version history and note any compatibility changes and operator actions.

## Before tagging

- Confirm authorization to distribute the project. Resolve the license and any upstream attribution obligations before adding SPDX identifiers or applying the [REUSE specification](https://reuse.software/spec/). Verify the applicable upstream license files and provenance of any reused material.
- Confirm a private reporting channel and the support status of the intended release. Verify the metadata version and update installation instructions for the approved ref.
- Run the [local checks and integration suite](../CONTRIBUTING.md). Smoke-test installation, permissions, both rollback modes and their redirects, and the effect on pending and active configuration. Record the tested versions and known limitations in the release notes.
- Review the source archive contents and move the appropriate changelog entries into the version section with the actual release date. Confirm the release commit on the default branch.

## Publish an approved release

1. Tag the verified commit with the matching `vMAJOR.MINOR.PATCH` tag. Use GitHub Releases and mark development versions as prereleases when appropriate.
2. Publish release notes covering install instructions, verified versions, changes, and known limitations. Use the tag as the source of the installable tree. No compiled artifacts are required.
3. If an installable archive is needed, replace `v0.5.2` below with the approved tag. Verify that the archive includes the approved license and any required notices before uploading.

```sh
git archive --format=tar.gz --prefix=director_rollback/ \
  -o director_rollback-v0.5.2.tar.gz v0.5.2
tar -tzf director_rollback-v0.5.2.tar.gz
```

The archive can be recreated from the tag. This process has no formal reproducibility or provenance claim. If the project ships a separate artifact from CI, evaluate artifact attestations using [SLSA](https://slsa.dev/spec/) and an artifact inventory in [SPDX](https://spdx.dev/) or [CycloneDX](https://cyclonedx.org/). An instance inventory must also capture the separately installed Icinga, PHP, and database packages.
