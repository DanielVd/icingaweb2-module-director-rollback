# Icinga Web 2 Director Rollback

Preview and roll back pending Icinga Director changes against the active deployment. This module supports hosts, services, host and service templates, service sets and uniquely identifiable service apply rules.

## Installation

The repository is named `icingaweb2-module-director-rollback`. Icinga Web 2 uses `director_rollback` as the installation directory and module identifier. Make the checkout available in the Icinga Web 2 module path under that directory name, then run `icingacli module enable director_rollback`. The module requires Icinga Director 1.12.1 or later.

Users with access to both modules and the `director/audit` permission can open **Icinga Director → Rollback**. Review and apply actions require `director_rollback/apply`. Field-level differences additionally require `director/showconfig`. Custom variable values and other fields outside the display allowlist are hidden.

## Rollback

Choose **Full rollback** to open the review page, then use **Apply full rollback** to confirm. The module rechecks the approved plan and commits only when the configuration it renders matches the active deployment. The comparison tolerates different ordering of otherwise identical service declarations and tied legacy imports with unchanged database weights.

Choose **Partial rollback** to select objects and review the dependencies that the module would include. The module explains each dependency, verifies the result and preserves unrelated pending operations. Both actions use a database transaction and leave the active Icinga 2 deployment unchanged.

Ambiguous activity entries require review. This includes renamed objects, multiple indistinguishable apply rules, incomplete historical snapshots, and deletion of populated service sets where Director recorded members inside the set snapshot without separate member activities. Dependencies outside the supported types can block an operation.

## Development

Run PHP syntax checks and the planner tests from the repository root.

```sh
php -l configuration.php
find application library test -type f \( -name '*.php' -o -name '*.phtml' \) -exec php -l '{}' \;
php test/RollbackPlanTest.php
```

Run the database integration tests from a host with Icinga Web 2, Icinga Director, local MariaDB root socket access and a Director database with an active deployment and no pending changes.

```sh
test/run-mariadb.sh director
```

The integration runner copies the named database to a temporary schema and drops it on exit. It exercises dependency ordering, stale confirmation, atomic failure, full rollback, service set recreation, apply rule restoration and field-level diffs. It reads the running deployment from the configured Director endpoint without deploying anything.
