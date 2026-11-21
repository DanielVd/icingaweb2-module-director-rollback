# Architecture and operations

## Request and data flow

1. Icinga Web 2 authenticates the operator and loads the module. The controller requires Director access and audit permission. Confirmations also require the module's apply permission and use the framework's form and CSRF validation.
2. The module uses Director's database resource and deployment endpoint to resolve the active stage. It reads the Activity Log entries after the deployment checkpoint and groups them into proposed object operations.
3. The review shows proposed changes and, if authorized, field-level diffs. The partial planner resolves dependencies and orders the operations. Browser selection mirrors linked objects, and the server independently validates the approved selection.
4. Apply rechecks the snapshot and selection within a Director database transaction. It uses Director object APIs, renders the resulting working configuration, checks the active stage and remaining plan, and compares the rendered result to the active deployment for a full rollback.

Director owns persistence and the deployment endpoint. The module has PostgreSQL-specific transaction handling in the source. The end-to-end suite covers MariaDB. Rollback form success opens a web page and does not send a deploy request to Icinga 2.

## Operating the module

Grant apply permission to operators authorized to change the identified Director database. Consider what each review reveals before granting audit or configuration view access. Diff values are allowlisted, but visible fields can still contain operational data. Use sanitized excerpts in support reports.

Serve Icinga Web 2 over HTTPS and maintain the database and deployment endpoint transport settings in the host application. Keep the Icinga packages and their PHP and database dependencies on patched versions. The module adds routes to the existing web application and runs within its authentication and network boundary.

Before evaluating the preview on a new stack, take a Director database backup using the site's existing procedure and use a separate test instance for fixtures. Changes made by a concurrent editor or deployment can invalidate an open review. After applying a partial rollback, review the remaining changes separately before allowing them to deploy.

To remove the module from the menu and disable its routes, use the Icinga Web 2 module manager or run `sudo icingacli module disable director_rollback` on the host. Disabling the module does not undo writes previously applied to Director.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Rollback is absent from the menu | Check that both modules are enabled and the role has Director module and audit access. Check the module directory name. |
| The preview is unavailable | Check Director's database access, configured deployment endpoint, active stage, and matching Activity Log checkpoint. Review Icinga Web 2 logs. |
| The plan contains **Review required** | Check for renames, incomplete activities, indistinguishable apply rules, or service set members without individual activities. Resolve these in Director. |
| Apply was aborted | Reload and review the plan. Check for concurrent edits, an in-progress deployment, dependencies outside the plan, or a mismatch in the rendered configuration. Check Icinga Web 2 logs for the cause. |
| Director counts more pending changes than the module | Director can count Activity Log rows while this module groups them into net object operations. Review the plan and use the Activity Log to inspect individual entries. |

Use [the security policy](../SECURITY.md) for suspected authorization or data exposure issues.
