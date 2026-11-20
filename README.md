# Icinga Web 2 Director Rollback

An Icinga Web 2 module for reviewing pending Icinga Director changes against the active deployment. The first implementation provides a read-only preview. Applying a full or selective rollback is planned.

## Development

The repository is named `icingaweb2-module-director-rollback`. Icinga Web 2 uses `director_rollback` as the module identifier and installation directory. The module requires Icinga Director 1.12.1 or later.

Run PHP syntax checks from the repository root.

```sh
php -l configuration.php
find application library -type f \( -name '*.php' -o -name '*.phtml' \) -exec php -l '{}' \;
```

On a development instance, make the checkout available in the Icinga Web 2 module path as `director_rollback`, then enable it with `icingacli module enable director_rollback`. A user with access to Director, access to the module and the `director/audit` permission can open **Icinga Director → Rollback**.
