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
        $pending = $adapter->select()
            ->where('id > ?', $baseline)
            ->where('id <= ?', $lastId);
        $count = (int) $adapter->fetchOne(
            (clone $pending)->from('director_activity_log', 'COUNT(*)')
        );
        $pages = max(1, (int) ceil($count / self::PAGE_SIZE));
        $page = max(1, min($page, $pages));
        $rows = $adapter->fetchAll(
            $pending
                ->from('director_activity_log', ['id', 'object_type', 'object_name', 'action_name', 'author', 'change_time'])
                ->order('id DESC')
                ->limit(self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE)
        );

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
