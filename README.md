# Icinga Web 2 Director Rollback

An Icinga Web 2 module for reviewing pending Icinga Director changes against the active deployment. The read-only preview groups activity for hosts and services into proposed rollback operations. Entries with ambiguous identity or unsupported object types are flagged for review. Full rollback is available when every pending operation is supported. Partial rollback supports concrete hosts and services attached directly to a host.

## Development

The repository is named `icingaweb2-module-director-rollback`. Icinga Web 2 uses `director_rollback` as the module identifier and installation directory. The module requires Icinga Director 1.12.1 or later.

Run PHP syntax checks from the repository root.

```sh
php -l configuration.php
find application library test -type f \( -name '*.php' -o -name '*.phtml' \) -exec php -l '{}' \;
php test/RollbackPlanTest.php
```

On a development instance, make the checkout available in the Icinga Web 2 module path as `director_rollback`, then enable it with `icingacli module enable director_rollback`. A user with access to Director, access to the module and the `director/audit` permission can open **Icinga Director → Rollback**.

## Full rollback

Applying the plan requires `director_rollback/apply` in addition to the preview permissions. The module rechecks the active deployment and activity log, reverses the supported host and service changes in a database transaction, and commits only when the regenerated checksum matches the active deployment. The operation does not deploy a configuration to Icinga 2.

Entries requiring review block full rollback. This includes renamed objects, apply rules, older entries with incomplete snapshots, and object types outside the currently supported hosts and services.

## Partial rollback

Choose **Partial rollback**, select the hosts or services to restore, then review and confirm the selection. The review includes services that must be removed with a pending new host and hosts that must be recreated before restoring a selected service. Dependencies outside the supported selection can block the operation.

The module rechecks the selected objects and dependencies during confirmation. It renders the resulting configuration and verifies that the other pending operations remain unchanged before it commits. Apply rules, templates and services scoped to a service set are outside the partial rollback scope.
