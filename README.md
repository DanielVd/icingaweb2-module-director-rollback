# Icinga Director Rollback

Review and undo pending changes in Icinga Director. The module can discard the full pending plan or roll back selected objects and their required dependencies. It updates Director's working configuration and leaves the active Icinga 2 deployment in place.

This is a **development preview**. See the [changelog](CHANGELOG.md) for work on the preview branch and the [licensing status](#licensing) before distributing it.

## Requirements

| Component | Requirement or verification |
| --- | --- |
| Icinga Director | The module declares 1.12.1 as its minimum. Only 1.12.1 has been verified. |
| Icinga Web 2 | An existing installation with Director enabled. Verified with 2.14.1. |
| PHP | The module uses syntax that requires PHP 8.1 or later. Use a PHP version supported by the host packages. End-to-end testing used PHP 8.4. |
| Database and deployment | A working Director database and an active deployment that Director can associate with its Activity Log. End-to-end testing used MariaDB. |

The Icinga Web 2 and Director installations provide the runtime libraries. The module has no separate build, package install, database migration, or environment variables. See [architecture and operations](docs/architecture-and-operations.md) for the integration boundaries.

## Install the development preview

Use the standard module directory or substitute the module path configured on your host. The checkout directory must be named `director_rollback`, matching the Icinga Web 2 module ID.

```sh
sudo git clone --branch feat/rollback-preview --single-branch \
  https://github.com/DanielVd/icingaweb2-module-director-rollback.git \
  /usr/share/icingaweb2/modules/director_rollback
sudo icingacli module enable director_rollback
icingacli module list
```

In Icinga Web 2, open **Icinga Director → Rollback**. An administrator can configure the following permissions on existing Icinga Web 2 roles.

| Permission | Access |
| --- | --- |
| `module/director`, `module/director_rollback`, `director/audit` | View the rollback plan. |
| `director_rollback/apply` | Review and apply a full or partial rollback. |
| `director/showconfig` | Open **Show changes**. The diff displays an allowlist of values and hides custom variable values. |
| `director/deploy` | Open Director's **Deployments** list after a successful rollback. Users without this permission return to the rollback overview. |

## Use the module

- **Full rollback** lists all pending operations. Review the objects and optional diffs, then select **Apply full rollback**. The transaction verifies that the rendered result matches the active deployment, allowing specific ordering differences.
- **Partial rollback** lets you select objects and open **Review selection**. Pending hosts and their services, and pending service sets and their members, are selected together. Review each inclusion before selecting **Apply partial rollback**. Unrelated changes stay pending.

Supported objects include hosts, services, host and service templates, service sets, and identifiable service apply rules. **Review required** marks ambiguous or unsupported changes. See [troubleshooting](docs/architecture-and-operations.md#troubleshooting) for common causes.

## Project resources

- [Contributing and running tests](CONTRIBUTING.md)
- [Agent instructions](AGENTS.md)
- [Reporting security issues](SECURITY.md)
- [Release process](docs/release-process.md)

## Licensing

License selection and attribution review are pending. The repository currently has no project license file or SPDX license declaration. See the [release checklist](docs/release-process.md#before-tagging).
