<?php

namespace Icinga\Module\Director_rollback;

use DomainException;
use Icinga\Module\Director\Db;
use Icinga\Module\Director\Objects\IcingaHost;
use Icinga\Module\Director\Objects\IcingaObject;
use Icinga\Module\Director\Objects\IcingaService;
use Icinga\Module\Director\Objects\IcingaServiceSet;
use Zend_Db_Select;

final class RollbackObjects
{
    private const MODELS = [
        'icinga_host' => IcingaHost::class,
        'icinga_service' => IcingaService::class,
        'icinga_service_set' => IcingaServiceSet::class,
    ];

    public function __construct(private Db $db)
    {
    }

    public function findId(array $row): ?int
    {
        $db = $this->db->getDbAdapter();
        $query = $db->select()->from($row['type'], 'id')
            ->where('object_name = ?', $row['name'])->where('object_type = ?', $row['kind']);
        if ($row['type'] !== 'icinga_host') {
            if (! $this->scope($query, 'host_id', 'icinga_host', $row['host'])) {
                return null;
            }
            if ($row['type'] === 'icinga_service'
                && ! $this->scope($query, 'service_set_id', 'icinga_service_set', $row['serviceSet'])
            ) {
                return null;
            }
        }

        return $this->uniqueId($query);
    }

    public function load(string $type, int $id): IcingaObject
    {
        return self::MODELS[$type]::loadWithAutoIncId($id, $this->db);
    }

    private function scope(Zend_Db_Select $query, string $column, string $table, ?string $name): bool
    {
        if ($name === null) {
            $query->where("$column IS NULL");
            return true;
        }

        $db = $this->db->getDbAdapter();
        $lookup = $db->select()->from($table, 'id')->where('object_name = ?', $name);
        if ($table === 'icinga_service_set') {
            $lookup->where('host_id IS NULL')->where('object_type = ?', 'template');
        }
        $id = $this->uniqueId($lookup);
        if ($id === null) {
            return false;
        }
        $query->where("$column = ?", $id);
        return true;
    }

    private function uniqueId(Zend_Db_Select $query): ?int
    {
        $ids = $this->db->getDbAdapter()->fetchCol($query->limit(2));
        if (count($ids) > 1) {
            throw new DomainException('Multiple Director objects match the same activity log identity.');
        }
        return $ids === [] ? null : (int) $ids[0];
    }

    public function dependents(string $type, int $id): array
    {
        $db = $this->db->getDbAdapter();
        $relations = match ($type) {
            'icinga_host' => [
                ['icinga_service', 'host_id', 'id'],
                ['icinga_service_set', 'host_id', 'id'],
                ['icinga_host_inheritance', 'parent_host_id', 'host_id', 'icinga_host'],
            ],
            'icinga_service' => [
                ['icinga_service_inheritance', 'parent_service_id', 'service_id', 'icinga_service'],
            ],
            'icinga_service_set' => [
                ['icinga_service', 'service_set_id', 'id'],
                ['icinga_service_set_inheritance', 'parent_service_set_id', 'service_set_id', 'icinga_service_set'],
            ],
        };

        $keys = [];
        foreach ($relations as $relation) {
            [$table, $foreignKey, $key] = $relation;
            $target = $relation[3] ?? $table;
            foreach ($db->fetchCol($db->select()->from($table, $key)->where("$foreignKey = ?", $id)) as $child) {
                $keys[] = "$target:$child";
            }
        }

        return array_unique($keys);
    }

    public function assertNoExternalReferences(string $type, int $id): void
    {
        $references = match ($type) {
            'icinga_host' => [
                'icinga_dependency' => ['child_host_id', 'parent_host_id'],
                'icinga_host_service' => ['host_id'],
                'icinga_host_service_blacklist' => ['host_id'],
                'icinga_host_template_choice' => ['required_template_id'],
                'icinga_notification' => ['host_id'],
            ],
            'icinga_service' => [
                'icinga_dependency' => ['child_service_id', 'parent_service_id'],
                'icinga_host_service' => ['service_id'],
                'icinga_host_service_blacklist' => ['service_id'],
                'icinga_notification' => ['service_id'],
                'icinga_service_template_choice' => ['required_template_id'],
            ],
            'icinga_service_set' => [],
        };

        $db = $this->db->getDbAdapter();
        foreach ($references as $table => $columns) {
            $query = $db->select()->from($table, new \Zend_Db_Expr('1'))->limit(1);
            foreach ($columns as $column) {
                $query->orWhere("$column = ?", $id);
            }
            if ($db->fetchOne($query) !== false) {
                throw new DomainException('A selected object has dependencies outside the supported rollback types.');
            }
        }
    }
}
