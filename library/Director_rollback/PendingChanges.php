<?php

namespace Icinga\Module\Director_rollback;

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
        $plan = RollbackPlan::fromActivities($this->activities($snapshot));
        $count = count($plan);
        $pages = max(1, (int) ceil($count / self::PAGE_SIZE));
        $page = max(1, min($page, $pages));
        $rows = array_slice($plan, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        return [
            'deploymentId' => $snapshot['deploymentId'],
            'baseline' => $snapshot['baseline'],
            'token' => $snapshot['token'],
            'canApply' => $rows !== [] && ! in_array('review', array_column($plan, 'operation'), true),
            'count' => $count,
            'rows' => $rows,
            'page' => $page,
            'pages' => $pages,
        ];
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

    public function activities(array $snapshot, bool $reverse = false): iterable
    {
        $adapter = $this->db->getDbAdapter();
        $statement = $adapter->query(
            $adapter->select()
                ->from('director_activity_log', [
                    'id', 'object_type', 'object_name', 'action_name', 'old_properties', 'new_properties',
                ])
                ->where('id > ?', $snapshot['baseline'])
                ->where('id <= ?', $snapshot['lastId'])
                ->order($reverse ? 'id DESC' : 'id ASC')
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
