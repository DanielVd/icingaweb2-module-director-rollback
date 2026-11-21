# Icinga Director Rollback

An Icinga Web 2 module that rolls back pending Icinga Director changes to the state of the active deployment. Review the proposed changes, then restore the full plan or a selection of objects. Rollback updates Director's working configuration and leaves the running Icinga 2 deployment in place.

## Requirements

- Icinga Web 2 with Icinga Director 1.12.1 or later enabled.
- A successful deployment that Director can associate with an Activity Log checkpoint.
- Tested with Icinga Web 2 2.14.1, Director 1.12.1, PHP 8.4 and MariaDB.

## Install the development preview

The implementation is currently on the [preview branch](https://github.com/DanielVd/icingaweb2-module-director-rollback/pull/1). Use the standard module directory or substitute the module path configured on your host.

```sh
sudo git clone --branch feat/rollback-preview --single-branch \
  https://github.com/DanielVd/icingaweb2-module-director-rollback.git \
  /usr/share/icingaweb2/modules/director_rollback
sudo icingacli module enable director_rollback
icingacli module list
```

The checkout directory must be named `director_rollback` to match the Icinga Web 2 module ID. After enabling it, open **Icinga Director → Rollback**.

| Permission | Access |
| --- | --- |
| `module/director`, `module/director_rollback`, `director/audit` | View the pending rollback plan. |
| `director_rollback/apply` | Review and apply a full plan or a partial selection. |
| `director/showconfig` | Expand **Show changes** to compare **Current** with **After rollback**. |

The diff displays an allowlist of fields. It hides custom variable values.

## Use the module

### Full rollback

1. Open **Full rollback** and review the affected objects and their proposed operations.
2. Expand **Show changes** on an object to inspect its diff.
3. Select **Apply full rollback** to confirm. The module rechecks the plan and commits only if the rendered configuration matches the active deployment. The comparison allows ordering differences for equivalent service declarations and tied legacy imports whose database weights stayed unchanged.

### Partial rollback

1. Open **Partial rollback**, select the objects to restore and click **Review selection**.
2. Review the proposed operations and the reasons for any automatically included dependencies. For example, removing a pending new host also requires removing its pending services.
3. Select **Apply partial rollback**. The transaction preserves unrelated pending operations and leaves the active deployment in place.

### Unsupported or ambiguous changes

The module supports hosts, services, host and service templates, service sets and service apply rules with an unambiguous identity. Renames, incomplete Activity Log entries and indistinguishable apply rules are marked **Review required**. Deleting a populated service set can also require review when Director logged the members only inside the deleted set rather than as separate activities. A dependency outside the supported types can block rollback.

If the plan changes between review and confirmation, reload the preview and review it again. If testing with fixture objects on an instance that deploys automatically, keep the automatic deployment paused while they remain pending.

## Development and tests

Run PHP lint and the planner tests from the repository root.

```sh
php -l configuration.php
find application library test -type f \( -name '*.php' -o -name '*.phtml' \) -exec php -l '{}' \;
php test/RollbackPlanTest.php
```

For database integration tests, use a host with Icinga Web 2, Director, MariaDB root socket access and a Director database with a successful active deployment and an empty rollback plan. Replace `director` if the database has a different name.

```sh
test/run-mariadb.sh director
```

The runner copies the source database into a temporary schema and drops it on exit. It covers dependency ordering, stale confirmation, transaction cancellation, full rollback, empty service set recreation, apply rule restoration and field-level diffs.

See [AGENTS.md](AGENTS.md) for the repository's Conventional Commits and pull request workflow.
