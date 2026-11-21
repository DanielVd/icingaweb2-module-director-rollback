<?php

namespace Icinga\Module\Director_rollback;

use Icinga\Module\Director\Core\CoreApi;
use Icinga\Module\Director\Data\Db\DbObject;
use Icinga\Module\Director\Db;
use Icinga\Module\Director\IcingaConfig\IcingaConfig;
use Icinga\Module\Director\Objects\DirectorDeploymentLog;
use Icinga\Module\Director\Objects\IcingaObject;
use RuntimeException;

final class RollbackExecutor
{
    public function __construct(private Db $db, private CoreApi $api)
    {
    }

    public function apply(string $token): void
    {
        $adapter = $this->db->getDbAdapter();
        if ($this->db->isMysql()) {
            $adapter->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        }

        $this->db->runFailSafeTransaction(function () use ($adapter, $token): void {
            if ($this->db->isPgsql()) {
                $adapter->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
                $adapter->query('LOCK TABLE director_deployment_log, director_activity_log IN SHARE ROW EXCLUSIVE MODE');
            } else {
                // The open-ended range also locks the gap where new log entries would be inserted.
                foreach (['director_deployment_log', 'director_activity_log'] as $table) {
                    $lastId = (int) $adapter->fetchOne(
                        $adapter->select()->from($table, 'id')->order('id DESC')->limit(1)->forUpdate()
                    );
                    $adapter->fetchCol($adapter->select()->from($table, 'id')->where('id >= ?', $lastId)->forUpdate());
                }
            }

            if (DirectorDeploymentLog::hasUncollected($this->db)) {
                throw new RuntimeException('A Director deployment is still in progress.');
            }

            $pending = new PendingChanges($this->db, $this->api);
            $snapshot = $pending->snapshot();
            if (! hash_equals($snapshot['token'], $token)) {
                throw new RuntimeException('The rollback preview is no longer current.');
            }

            $plan = RollbackPlan::fromActivities($pending->activities($snapshot));
            if ($plan === [] || in_array('review', array_column($plan, 'operation'), true)) {
                throw new RuntimeException('The full rollback plan is empty or has unresolved entries.');
            }

            // Reverse the original order so dependent objects are usually undone before their prerequisites.
            $selected = array_fill_keys(array_merge(...array_column($plan, 'activityIds')), true);
            $activities = iterator_to_array($pending->activities($snapshot, true), false);
            foreach ($activities as $activity) {
                if (isset($selected[$activity->id])) {
                    $this->undo($activity);
                }
            }

            DbObject::clearAllPrefetchCaches();
            if (self::renderChecksum($this->db) !== $snapshot['configChecksum']
                || $this->api->getActiveStageName() !== $snapshot['stage']
            ) {
                throw new RuntimeException('The result does not match the active Director deployment.');
            }
        });
    }

    private function undo(object $activity): void
    {
        $type = $activity->object_type;
        $before = $activity->old_properties === null ? null : $this->object($type, $activity->old_properties);
        $after = $activity->new_properties === null ? null : $this->object($type, $activity->new_properties);

        if ($after === null) {
            $before->store();
            return;
        }

        $current = IcingaObject::loadByType($type, $after->getKeyParams(), $this->db);
        if (! RollbackPlan::sameState($current->toJson(false, true), $after->toJson(false, true))) {
            throw new RuntimeException(sprintf('Object state no longer matches activity #%d.', $activity->id));
        }

        if ($before === null) {
            $current->delete();
        } else {
            $current->replaceWith($before)->store();
        }
    }

    private function object(string $type, string $snapshot): IcingaObject
    {
        return IcingaObject::createByType($type, json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR), $this->db);
    }

    public static function renderChecksum(Db $db): string
    {
        $config = new class ($db) extends IcingaConfig {
            public function renderChecksum(): string
            {
                $this->generateFromDb();
                $this->collectExtraFiles();
                return bin2hex($this->calculateChecksum());
            }
        };

        return $config->renderChecksum();
    }
}
