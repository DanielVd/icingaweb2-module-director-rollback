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
        $lastId = (int) $adapter->fetchOne(
            $adapter->select()->from('director_activity_log', 'MAX(id)')
        );
        $statement = $adapter->query(
            $adapter->select()
                ->from('director_activity_log', [
                    'id', 'object_type', 'object_name', 'action_name', 'old_properties', 'new_properties',
                ])
                ->where('id > ?', $baseline)
                ->where('id <= ?', $lastId)
                ->order('id ASC')
        );
        $activities = (static function () use ($statement): iterable {
            while ($row = $statement->fetch(\Zend_Db::FETCH_OBJ)) {
                yield $row;
            }
        })();

        $plan = RollbackPlan::fromActivities($activities);
        $count = count($plan);
        $pages = max(1, (int) ceil($count / self::PAGE_SIZE));
        $page = max(1, min($page, $pages));
        $rows = array_slice($plan, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        return [
            'deploymentId' => (int) $deployment->get('id'),
            'baseline' => $baseline,
            'count' => $count,
            'rows' => $rows,
            'page' => $page,
            'pages' => $pages,
        ];
    }
}
