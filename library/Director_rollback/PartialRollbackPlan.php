<?php

namespace Icinga\Module\Director_rollback;

use DomainException;
use Icinga\Module\Director\Db;

final class PartialRollbackPlan
{
    private RollbackObjects $objects;

    public function __construct(Db $db)
    {
        $this->objects = new RollbackObjects($db);
    }

    public static function isSelectable(array $row): bool
    {
        return $row['operation'] !== 'review';
    }

    public static function normalizeIds(array $ids): array
    {
        $selection = [];
        foreach ($ids as $id) {
            $valid = is_int($id) || is_string($id)
                ? filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;
            if ($valid === false) {
                throw new DomainException('Invalid selection.');
            }
            $selection[$valid] = $valid;
        }

        ksort($selection, SORT_NUMERIC);
        return array_values($selection);
    }

    public function select(array $snapshot, array $plan, array $requested, array $snapshots): array
    {
        $ids = self::normalizeIds($requested);
        if ($ids === []) {
            throw new DomainException('Select at least one object.');
        }

        $byId = $byKey = $removals = [];
        foreach (array_filter($plan, self::isSelectable(...)) as $row) {
            $byId[$row['firstId']] = $row;
            $byKey[$this->key($row)] = $row;
            if ($row['operation'] === 'remove') {
                try {
                    $id = $this->objects->findId($row);
                } catch (DomainException) {
                    // An ambiguous removal is blocked when selected or encountered as a dependency.
                    continue;
                }
                if ($id !== null) {
                    $removals[$row['type'] . ':' . $id] = $row;
                }
            }
        }

        $visiting = $ordered = $reasons = $conflicts = [];
        $visit = function (array $row) use (&$visit, &$visiting, &$ordered, &$reasons, &$conflicts, $byKey, $removals, $snapshots): void {
            $id = $row['firstId'];
            if (isset($ordered[$id])) {
                return;
            }
            if (isset($visiting[$id])) {
                throw new DomainException('The selection contains a cyclic dependency.');
            }

            $visiting[$id] = true;
            if ($row['operation'] === 'remove') {
                $objectId = $this->objects->findId($row);
                if ($objectId === null) {
                    throw new DomainException('A selected object to remove no longer exists.');
                }

                $this->objects->assertNoExternalReferences($row['type'], $objectId);
                $dependents = $this->objects->dependents($row['type'], $objectId);
                sort($dependents);
                foreach ($dependents as $key) {
                    $dependent = $removals[$key] ?? null;
                    if ($dependent === null) {
                        throw new DomainException('Removing a selected object would affect a dependent object outside the plan.');
                    }

                    $reasons[$dependent['firstId']][$id] = ['operation' => 'remove', 'name' => $this->label($row)];
                    $visit($dependent);
                }
            } else {
                foreach ($this->prerequisites($row, $snapshots[$id]['old']) as $prerequisite) {
                    $candidate = $byKey[$this->key($prerequisite)] ?? null;
                    if ($this->objects->findId($prerequisite) !== null) {
                        if (($candidate['operation'] ?? null) === 'remove') {
                            $conflicts[$candidate['firstId']] = true;
                        }
                        continue;
                    }
                    if (($candidate['operation'] ?? null) !== 'recreate') {
                        throw new DomainException('A required host, template or service set is missing from Director and the rollback plan.');
                    }

                    $reasons[$candidate['firstId']][$id] = ['operation' => 'recreate', 'name' => $this->label($row)];
                    $visit($candidate);
                }
            }
            unset($visiting[$id]);
            $ordered[$id] = $row;
        };

        foreach ($ids as $id) {
            if (! isset($byId[$id])) {
                throw new DomainException('A selected object is no longer eligible for partial rollback.');
            }
            $visit($byId[$id]);
        }
        if (array_intersect_key($ordered, $conflicts)) {
            throw new DomainException('The selection would remove an object needed by another selected restoration.');
        }

        return [
            'requested' => $ids,
            'rows' => array_values($ordered),
            'reasons' => $reasons,
            'token' => hash('sha256', json_encode(
                [$snapshot['token'], $ids, array_keys($ordered), $reasons],
                JSON_THROW_ON_ERROR
            )),
        ];
    }

    private function label(array $row): string
    {
        return $row['scope'] === '' ? $row['name'] : $row['scope'] . ' / ' . $row['name'];
    }

    private function key(array $row): string
    {
        return json_encode(
            [$row['type'], $row['kind'], $row['name'], $row['host'], $row['serviceSet']],
            JSON_THROW_ON_ERROR
        );
    }

    private function prerequisites(array $row, string $before): iterable
    {
        $props = json_decode($before, true, 512, JSON_THROW_ON_ERROR);
        $unscoped = ['host' => null, 'serviceSet' => null];
        if ($row['host'] !== null) {
            yield ['type' => 'icinga_host', 'name' => $row['host'],
                'kind' => $row['kind'] === 'apply' ? 'template' : 'object'] + $unscoped;
        }
        if ($row['serviceSet'] !== null) {
            yield ['type' => 'icinga_service_set', 'name' => $row['serviceSet'], 'kind' => 'template'] + $unscoped;
        }
        foreach ($props['imports'] ?? [] as $name) {
            yield ['type' => $row['type'], 'name' => $name, 'kind' => 'template'] + $unscoped;
        }
    }
}
