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

    public function apply(string $token, ?array $requested = null): void
    {
        $adapter = $this->db->getDbAdapter();
        if ($this->db->isMysql()) {
            $adapter->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        }

        $this->db->runFailSafeTransaction(function () use ($adapter, $token, $requested): void {
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
            $plan = RollbackPlan::fromActivities($pending->activities($snapshot));
            if ($requested === null) {
                $this->assertCurrent($snapshot['token'], $token);
                if ($plan === [] || in_array('review', array_column($plan, 'operation'), true)) {
                    throw new RuntimeException('The full rollback plan is empty or has unresolved entries.');
                }

                $selected = array_fill_keys(array_merge(...array_column($plan, 'activityIds')), true);
                $activities = iterator_to_array($pending->activities($snapshot, true), false);
                foreach ($activities as $activity) {
                    if (isset($selected[$activity->id])) {
                        $this->undo($activity->object_type, $activity->old_properties, $activity->new_properties);
                    }
                }
            } else {
                $selection = (new PartialRollbackPlan($this->db))->select($snapshot, $plan, $requested);
                $this->assertCurrent($selection['token'], $token);
                $this->undoSelection($selection['rows']);
            }

            DbObject::clearAllPrefetchCaches();
            $rendered = self::renderChecksum($this->db);
            if ($requested === null && $rendered !== $snapshot['configChecksum']) {
                throw new RuntimeException('The result does not match the active Director deployment.');
            }
            if ($this->api->getActiveStageName() !== $snapshot['stage']) {
                throw new RuntimeException('The active Director deployment changed during rollback.');
            }
            if ($requested !== null) {
                $selected = array_fill_keys(array_column($selection['rows'], 'firstId'), true);
                $expected = array_values(array_filter($plan, static fn ($row) => ! isset($selected[$row['firstId']])));
                $updated = RollbackPlan::fromActivities($pending->activities($pending->snapshot()));
                if ($expected !== $updated) {
                    throw new RuntimeException('The remaining Director changes do not match the approved selection.');
                }
            }
        });
    }

    private function assertCurrent(string $expected, string $token): void
    {
        if (! hash_equals($expected, $token)) {
            throw new RuntimeException('The rollback preview is no longer current.');
        }
    }

    private function undoSelection(array $rows): void
    {
        $order = static fn (array $row): int => match ([$row['type'], $row['operation']]) {
            ['icinga_host', 'remove'] => 3,
            ['icinga_service', 'remove'] => 2,
            ['icinga_host', 'restore'], ['icinga_host', 'recreate'] => 0,
            default => 1,
        };
        usort($rows, static fn ($left, $right) => $order($left) <=> $order($right));
        $db = $this->db->getDbAdapter();
        $ids = array_unique(array_merge(array_column($rows, 'firstId'), array_column($rows, 'lastId')));
        $snapshots = [];
        foreach ($db->fetchAll($db->select()
            ->from('director_activity_log', ['id', 'old_properties', 'new_properties'])
            ->where('id IN (?)', $ids)) as $activity
        ) {
            $snapshots[$activity->id] = $activity;
        }
        foreach ($rows as $row) {
            $first = $snapshots[$row['firstId']] ?? null;
            $last = $snapshots[$row['lastId']] ?? null;
            if ($first === null || $last === null) {
                throw new RuntimeException('An activity in the selected plan is missing.');
            }
            $this->undo($row['type'], $first->old_properties, $last->new_properties);
        }
    }

    private function undo(string $type, ?string $old, ?string $new): void
    {
        $before = $old === null ? null : $this->object($type, $old);
        $after = $new === null ? null : $this->object($type, $new);

        if ($after === null) {
            $before->store();
            return;
        }

        $current = IcingaObject::loadByType($type, $after->getKeyParams(), $this->db);
        if (! RollbackPlan::sameState($current->toJson(false, true), $after->toJson(false, true))) {
            throw new RuntimeException('Object state no longer matches the activity log.');
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
