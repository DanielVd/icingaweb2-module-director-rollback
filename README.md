# Icinga Director Rollback

[![Latest release](https://img.shields.io/github/v/release/DanielVd/icingaweb2-module-director-rollback?include_prereleases)](https://github.com/DanielVd/icingaweb2-module-director-rollback/releases)
![PHP 8.1+](https://img.shields.io/badge/php-8.1%2B-blue)

Icinga Web 2 module for reviewing and undoing pending changes in Icinga Director. Apply the full plan or select objects and their required dependencies. Both modes update Director's working configuration and leave the active Icinga 2 deployment in place.

| Rollback mode | Typical use | Effect on pending changes |
| --- | --- | --- |
| Full | Discard the pending plan | Revert all pending changes in the plan |
| Partial | Undo selected objects | Revert the selection and its required dependencies. Unrelated changes stay pending. |

The project publishes prereleases on GitHub and is currently in the SemVer `0.x` series. See the [changelog](CHANGELOG.md) before upgrading.

## Quick start

### Requirements

| Component | Requirement or verification |
| --- | --- |
| Icinga Director | The module declares 1.12.1 as its minimum. Only 1.12.1 has been verified. |
| Icinga Web 2 | An existing installation with Director enabled. Verified with 2.14.1. |
| PHP | The module uses syntax that requires PHP 8.1 or later. Use a PHP version supported by the host packages. End-to-end testing used PHP 8.4. |
| Database and deployment | A working Director database and an active deployment that Director can associate with its Activity Log. End-to-end testing used MariaDB. |

The Icinga Web 2 and Director installations provide the runtime libraries. The module has no separate build, package install, database migration, or environment variables.

### Install and enable

1. Clone the prerelease and enable the module. Use the standard module directory or substitute the module path configured on your host. The checkout directory must be named `director_rollback`, matching the Icinga Web 2 module ID.

   ```sh
   sudo git clone --branch v0.5.2 --single-branch \
     https://github.com/DanielVd/icingaweb2-module-director-rollback.git \
     /usr/share/icingaweb2/modules/director_rollback
   sudo icingacli module enable director_rollback
   icingacli module list
   ```

2. Configure the permissions below on existing Icinga Web 2 roles.
3. Open **Icinga Director → Rollback** to view the pending plan.

| Permission | Access |
| --- | --- |
| `module/director`, `module/director_rollback`, `director/audit` | View the rollback plan. |
| `director_rollback/apply` | Review and apply a full or partial rollback. |
| `director/showconfig` | Open **Show changes**. |
| `director/deploy` | Open Director's **Deployments** list after a successful rollback. Users without this permission return to the rollback overview. |

The [release page](https://github.com/DanielVd/icingaweb2-module-director-rollback/releases/tag/v0.5.2) also provides an installable archive with the `director_rollback` directory.

## What you can do

- Review all pending operations and optional diffs in **Full rollback**, then select **Apply full rollback**. The transaction verifies that the rendered result matches the active deployment, allowing specific ordering differences.
- Select objects in **Partial rollback** and open **Review selection**. Pending hosts and their services, and pending service sets and their members, are selected together. Review each inclusion before selecting **Apply partial rollback**.
- Roll back supported hosts, services, host and service templates, service sets, and identifiable service apply rules. **Review required** marks ambiguous or unsupported changes. See [troubleshooting](docs/architecture-and-operations.md#troubleshooting) for common causes.

## Documentation

- [Architecture, operations and troubleshooting](docs/architecture-and-operations.md)
- [Development and tests](CONTRIBUTING.md#local-checks)
- [Releasing and supply-chain records](docs/release-process.md)
- [Security reporting](SECURITY.md)
- [Repository guidance for coding agents](AGENTS.md)

## Security and licensing

Grant apply permission to operators authorized to change the Director database. The diff displays an allowlist of values and hides custom variable values. See [operating the module](docs/architecture-and-operations.md#operating-the-module) for transport settings and permission guidance.

The module is licensed under the [GNU General Public License v3.0 or later](LICENSE), SPDX identifier `GPL-3.0-or-later`. The Icinga Web 2, Director, and PHP libraries supplied by the host installation retain their own licenses.
