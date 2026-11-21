<?php

namespace Icinga\Module\Director_rollback;

use DomainException;
use Icinga\Module\Director\Core\CoreApi;
use Icinga\Module\Director\Db;
use Icinga\Module\Director\Objects\DirectorDeploymentLog;
use RuntimeException;

final class PendingChanges
{
    public const PAGE_SIZE = 100;

    public function __construct(private Db $db, private CoreApi $api)
    {
    }

    public function preview(int $page = 1): array
    {
        $snapshot = $this->snapshot();
        $plan = $this->plan($snapshot);
        $count = count($plan);
        $pages = max(1, (int) ceil($count / self::PAGE_SIZE));
        $page = max(1, min($page, $pages));
        $rows = array_slice($plan, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        return [
            'deploymentId' => $snapshot['deploymentId'],
            'baseline' => $snapshot['baseline'],
            'token' => $snapshot['token'],
            'canApply' => $rows !== [] && ! in_array('review', array_column($plan, 'operation'), true),
            'canSelect' => array_filter($plan, PartialRollbackPlan::isSelectable(...)) !== [],
            'count' => $count,
            'rows' => $rows,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    public function plan(array $snapshot): array
    {
        $rows = RollbackPlan::fromActivities($this->activities($snapshot));
        $objects = new RollbackObjects($this->db);
        foreach ($rows as &$row) {
            if ($row['operation'] !== 'review' && $row['kind'] === 'apply') {
                try {
                    $objects->findId($row);
                } catch (DomainException) {
                    $row['operation'] = 'review';
                }
            }
        }
        unset($row);
        return $rows;
    }

    public function objectSnapshots(array $rows): array
    {
        $ids = array_unique(array_merge(array_column($rows, 'firstId'), array_column($rows, 'lastId')));
        if ($ids === []) {
            return [];
        }

        $db = $this->db->getDbAdapter();
        $snapshots = $db->fetchAssoc($db->select()
            ->from('director_activity_log', ['id', 'old_properties', 'new_properties'])
            ->where('id IN (?)', $ids));
        $objects = [];
        foreach ($rows as $row) {
            $first = $snapshots[$row['firstId']] ?? null;
            $last = $snapshots[$row['lastId']] ?? null;
            if ($first === null || $last === null) {
                throw new RuntimeException('An activity in the rollback plan is missing.');
            }
            $objects[$row['firstId']] = ['old' => $first['old_properties'], 'new' => $last['new_properties']];
        }
        return $objects;
    }

    public function diffs(array $rows): array
    {
        return array_map(
            static fn ($snapshot) => RollbackDiff::between($snapshot['new'], $snapshot['old']),
            $this->objectSnapshots($rows)
        );
    }

    public function snapshot(): array
    {
        $deployment = DirectorDeploymentLog::requireRelatedToActiveStage($this->api, $this->db);
        $activityChecksum = $deployment->get('last_activity_checksum');
        if (! $deployment->succeeded() || ! $activityChecksum) {
            throw new RuntimeException('The active deployment has no confirmed activity log checkpoint.');
        }

        $baseline = (int) $this->db->fetchActivityLogIdByChecksum($activityChecksum);
        if ($baseline === 0) {
            throw new RuntimeException('The activity log checkpoint for the active deployment is missing.');
        }

        $adapter = $this->db->getDbAdapter();
        $tip = $adapter->fetchRow($adapter->select()->from('director_activity_log', [
            'id',
            'hex_checksum' => $this->db->dbHexFunc('checksum'),
        ])->order('id DESC')->limit(1));
        if ($tip === false || (int) $tip->id < $baseline) {
            throw new RuntimeException('The activity log ends before the active deployment checkpoint.');
        }

        $snapshot = [
            'deploymentId' => (int) $deployment->get('id'),
            'stage' => $deployment->get('stage_name'),
            'configChecksum' => $deployment->getConfigHexChecksum(),
            'baseline' => $baseline,
            'lastId' => (int) $tip->id,
        ];
        $snapshot['token'] = hash('sha256', json_encode([
            $snapshot,
            $tip->hex_checksum,
            $this->db->getDbAdapter()->getConfig()['dbname'] ?? '',
        ], JSON_THROW_ON_ERROR));

        return $snapshot;
    }

    public function activities(array $snapshot): iterable
    {
        $adapter = $this->db->getDbAdapter();
        $statement = $adapter->query(
            $adapter->select()
                ->from('director_activity_log', [
                    'id', 'object_type', 'object_name', 'action_name', 'old_properties', 'new_properties',
                ])
                ->where('id > ?', $snapshot['baseline'])
                ->where('id <= ?', $snapshot['lastId'])
                ->order('id ASC')
        );

        try {
            while ($row = $statement->fetch(\Zend_Db::FETCH_OBJ)) {
                yield $row;
            }
        } finally {
            $statement->closeCursor();
        }
    }
}
