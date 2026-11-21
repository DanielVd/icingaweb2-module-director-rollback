<?php

namespace Icinga\Module\Director_rollback;

use Icinga\Module\Director\Db;
use DomainException;

final class PartialRollbackPlan
{
    public function __construct(private Db $db)
    {
    }

    public static function isSelectable(array $row): bool
    {
        return $row['operation'] !== 'review' && $row['kind'] === 'object'
            && ($row['type'] === 'icinga_host' || ($row['host'] !== null && $row['serviceSet'] === null));
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

    public function select(array $snapshot, array $plan, array $requested): array
    {
        $ids = self::normalizeIds($requested);
        if ($ids === []) {
            throw new DomainException('Select at least one object.');
        }

        $hosts = $services = $selectable = $selected = [];
        foreach (array_filter($plan, self::isSelectable(...)) as $row) {
            $selectable[$row['firstId']] = $row;
            if ($row['type'] === 'icinga_host') {
                $hosts[$row['name']] = $row;
            } else {
                $services[$row['host']][$row['name']] = $row;
            }
        }

        foreach ($ids as $id) {
            if (! isset($selectable[$id])) {
                throw new DomainException('A selected object is no longer eligible for partial rollback.');
            }
            $selected[$id] = $selectable[$id];
        }

        foreach ($selected as $row) {
            if ($row['type'] === 'icinga_host' && $row['operation'] === 'remove') {
                $hostId = $this->hostId($row['name']);
                foreach ($this->servicesOnHost($hostId) as $service) {
                    $dependency = $services[$row['name']][$service->object_name] ?? null;
                    if ($service->object_type !== 'object' || $service->service_set_id !== null
                        || $dependency === null || $dependency['operation'] !== 'remove'
                    ) {
                        throw new DomainException('Removing a selected host would also remove a service outside the plan.');
                    }
                    $selected[$dependency['firstId']] = $dependency;
                }
            } elseif ($row['type'] === 'icinga_service' && $row['operation'] !== 'remove') {
                $host = $hosts[$row['host']] ?? null;
                if ($host !== null && $host['operation'] === 'remove' && isset($selected[$host['firstId']])) {
                    throw new DomainException('A selected service cannot be restored while removing its host.');
                }
                if ($this->hostId($row['host']) === null) {
                    if ($host === null || $host['operation'] !== 'recreate') {
                        throw new DomainException('The host required to restore a selected service is missing.');
                    }
                    $selected[$host['firstId']] = $host;
                }
            }
        }

        foreach ($selected as $row) {
            if ($row['operation'] === 'remove') {
                $this->assertRemovable($row);
            } else {
                $this->assertTemplatesPresent($row);
            }
        }

        ksort($selected, SORT_NUMERIC);
        return [
            'requested' => $ids,
            'rows' => array_values($selected),
            'token' => hash('sha256', json_encode([$snapshot['token'], $ids, array_keys($selected)], JSON_THROW_ON_ERROR)),
        ];
    }

    private function hostId(string $name): ?int
    {
        $db = $this->db->getDbAdapter();
        $id = $db->fetchOne(
            $db->select()->from('icinga_host', 'id')->where('object_name = ?', $name)->where('object_type = ?', 'object')
        );
        return $id === false ? null : (int) $id;
    }

    private function servicesOnHost(?int $hostId): array
    {
        if ($hostId === null) {
            throw new DomainException('A selected host no longer exists.');
        }

        $db = $this->db->getDbAdapter();
        return $db->fetchAll(
            $db->select()->from('icinga_service', ['object_name', 'object_type', 'service_set_id'])
                ->where('host_id = ?', $hostId)
        );
    }

    private function assertRemovable(array $row): void
    {
        $db = $this->db->getDbAdapter();
        if ($row['type'] === 'icinga_host') {
            $id = $this->hostId($row['name']);
            $references = [
                'icinga_dependency' => ['child_host_id', 'parent_host_id'],
                'icinga_host_inheritance' => ['parent_host_id'],
                'icinga_host_service' => ['host_id'],
                'icinga_host_service_blacklist' => ['host_id'],
                'icinga_host_template_choice' => ['required_template_id'],
                'icinga_notification' => ['host_id'],
                'icinga_service_set' => ['host_id'],
            ];
        } else {
            $hostId = $this->hostId($row['host']);
            $id = $hostId === null ? false : $db->fetchOne(
                $db->select()->from('icinga_service', 'id')->where('host_id = ?', $hostId)
                    ->where('object_name = ?', $row['name'])->where('object_type = ?', 'object')
                    ->where('service_set_id IS NULL')
            );
            $references = [
                'icinga_dependency' => ['child_service_id', 'parent_service_id'],
                'icinga_host_service' => ['service_id'],
                'icinga_host_service_blacklist' => ['service_id'],
                'icinga_notification' => ['service_id'],
                'icinga_service_inheritance' => ['parent_service_id'],
                'icinga_service_template_choice' => ['required_template_id'],
            ];
        }

        if ($id === false || $id === null) {
            throw new DomainException('A selected object to remove no longer exists.');
        }
        foreach ($references as $table => $columns) {
            $query = $db->select()->from($table, new \Zend_Db_Expr('1'))->limit(1);
            foreach ($columns as $column) {
                $query->orWhere("$column = ?", $id);
            }
            if ($db->fetchOne($query) !== false) {
                throw new DomainException('A selected object is referenced by another object outside the plan.');
            }
        }
    }

    private function assertTemplatesPresent(array $row): void
    {
        $db = $this->db->getDbAdapter();
        $old = $db->fetchOne($db->select()->from('director_activity_log', 'old_properties')
            ->where('id = ?', $row['firstId']));
        $properties = json_decode($old, true, 512, JSON_THROW_ON_ERROR);
        foreach ($properties['imports'] ?? [] as $name) {
            $query = $db->select()->from($row['type'], 'id')->where('object_type = ?', 'template')
                ->where('object_name = ?', $name);
            if ($row['type'] === 'icinga_service') {
                $query->where('host_id IS NULL')->where('service_set_id IS NULL');
            }
            if ($db->fetchOne($query) === false) {
                throw new DomainException('A required template is missing. Partial rollback cannot restore templates.');
            }
        }
    }
}
