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

    public function selectionLinks(array $plan, array $snapshots): array
    {
        [$byId, $byKey, $removals] = $this->index($plan);
        $links = $this->ownershipLinks($byId);
        foreach ($byId as $id => $row) {
            try {
                [$dependencies] = $this->dependencies($row, $byKey, $removals, $snapshots);
                $links[$id] = array_values(array_unique(array_merge($links[$id] ?? [], array_keys($dependencies))));
            } catch (DomainException) {
                // The server reports why the selection is blocked if this object is requested.
            }
        }
        return $links;
    }

    public function select(array $snapshot, array $plan, array $requested, array $snapshots): array
    {
        $ids = self::normalizeIds($requested);
        if ($ids === []) {
            throw new DomainException('Select at least one object.');
        }

        [$byId, $byKey, $removals] = $this->index($plan);
        foreach ($ids as $id) {
            if (! isset($byId[$id])) {
                throw new DomainException('A selected object is no longer eligible for partial rollback.');
            }
        }

        $owners = $this->ownershipLinks($byId);
        $included = array_fill_keys($ids, true);
        $queue = $ids;
        $edges = $reasons = $conflicts = [];
        for ($index = 0; $index < count($queue); ++$index) {
            $id = $queue[$index];
            $row = $byId[$id];
            [$dependencies, $blocked] = $this->dependencies($row, $byKey, $removals, $snapshots);
            $edges[$id] = array_keys($dependencies);
            $conflicts += $blocked;
            $linked = $dependencies + array_fill_keys($owners[$id] ?? [], 'linked');

            foreach ($linked as $target => $operation) {
                if ($operation !== 'linked' || ! isset($included[$target])) {
                    $reasons[$target][$id] = ['operation' => $operation, 'name' => $this->label($row)];
                }
                if (! isset($included[$target])) {
                    $included[$target] = true;
                    $queue[] = $target;
                }
            }
        }
        if (array_intersect_key($included, $conflicts)) {
            throw new DomainException('The selection would remove an object needed by another selected restoration.');
        }

        $visiting = $ordered = [];
        $visit = function (int $id) use (&$visit, &$visiting, &$ordered, $byId, $edges): void {
            if (isset($ordered[$id])) {
                return;
            }
            if (isset($visiting[$id])) {
                throw new DomainException('The selection contains a cyclic dependency.');
            }
            $visiting[$id] = true;
            foreach ($edges[$id] as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$id]);
            $ordered[$id] = $byId[$id];
        };
        foreach ($queue as $id) {
            $visit($id);
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

    private function index(array $plan): array
    {
        $byId = $byKey = $removals = [];
        foreach ($plan as $row) {
            if (! self::isSelectable($row)) {
                continue;
            }
            $byId[$row['firstId']] = $row;
            $byKey[$this->key($row)] = $row;
            if ($row['operation'] !== 'remove') {
                continue;
            }
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
        return [$byId, $byKey, $removals];
    }

    private function ownershipLinks(array $rows): array
    {
        $hosts = $sets = $links = [];
        foreach ($rows as $id => $row) {
            if ($row['type'] === 'icinga_host') {
                $hosts[$row['name']] = $id;
            } elseif ($row['type'] === 'icinga_service_set' && $row['host'] === null) {
                $sets[$row['name']] = $id;
            }
        }
        foreach ($rows as $id => $row) {
            $parent = $row['host'] !== null ? ($hosts[$row['host']] ?? null) : null;
            if ($row['serviceSet'] !== null) {
                $parent = $sets[$row['serviceSet']] ?? null;
            }
            if ($parent !== null) {
                $links[$id][] = $parent;
                $links[$parent][] = $id;
            }
        }
        return $links;
    }

    private function dependencies(array $row, array $byKey, array $removals, array $snapshots): array
    {
        $dependencies = $conflicts = [];
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
                $dependencies[$dependent['firstId']] = 'remove';
            }
        } else {
            foreach ($this->prerequisites($row, $snapshots[$row['firstId']]['old']) as $prerequisite) {
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
                $dependencies[$candidate['firstId']] = 'recreate';
            }
        }
        return [$dependencies, $conflicts];
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
