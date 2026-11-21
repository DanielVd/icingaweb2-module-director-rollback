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
            $plan = $pending->plan($snapshot);
            $full = $requested === null;
            if ($full) {
                $this->assertCurrent($snapshot['token'], $token);
                if ($plan === [] || in_array('review', array_column($plan, 'operation'), true)) {
                    throw new RuntimeException('The full rollback plan is empty or has unresolved entries.');
                }
                $requested = array_column($plan, 'firstId');
            }

            $states = $pending->objectSnapshots($plan);
            $selection = (new PartialRollbackPlan($this->db))->select($snapshot, $plan, $requested, $states);
            if (! $full) {
                $this->assertCurrent($selection['token'], $token);
            }
            $unweightedBefore = $full ? $this->unweightedImports() : [];

            foreach ($selection['rows'] as $row) {
                $state = $states[$row['firstId']];
                $this->undo($row['type'], $state['old'], $state['new']);
            }

            DbObject::clearAllPrefetchCaches();
            $rendered = self::renderConfig($this->db);
            if ($full && ! $this->matchesDeployment($rendered, $snapshot['configChecksum'], $unweightedBefore)) {
                throw new RuntimeException('The result does not match the active Director deployment.');
            }
            if ($this->api->getActiveStageName() !== $snapshot['stage']) {
                throw new RuntimeException('The active Director deployment changed during rollback.');
            }
            $selected = array_fill_keys(array_column($selection['rows'], 'firstId'), true);
            $expected = array_values(array_filter($plan, static fn ($row) => ! isset($selected[$row['firstId']])));
            $updated = $pending->plan($pending->snapshot());
            if ($expected !== $updated) {
                throw new RuntimeException('The remaining Director changes do not match the approved selection.');
            }
        });
    }

    private function assertCurrent(string $expected, string $token): void
    {
        if (! hash_equals($expected, $token)) {
            throw new RuntimeException('The rollback preview is no longer current.');
        }
    }

    private function undo(string $type, ?string $old, ?string $new): void
    {
        $identity = RollbackPlan::identify($type, $new ?? $old);
        if ($identity === null) {
            throw new RuntimeException('The activity does not contain a usable object identity.');
        }
        $objects = new RollbackObjects($this->db);
        $id = $objects->findId($identity + ['type' => $type]);
        $before = $old === null ? null : $this->object($type, $old);
        $after = $new === null ? null : $this->object($type, $new);

        if ($after === null) {
            if ($id !== null) {
                throw new RuntimeException('The object to recreate already exists.');
            }
            $before->store();
            return;
        }

        if ($id === null) {
            throw new RuntimeException('The object to roll back no longer exists.');
        }
        $current = $objects->load($type, $id);
        if (! RollbackPlan::sameState($current->toJson(false, true), $after->toJson(false, true))) {
            throw new RuntimeException(sprintf('Object %s (%s) no longer matches the activity log.', $identity['name'], $type));
        }

        if ($before === null) {
            $objects->assertNoExternalReferences($type, $id);
            if ($objects->dependents($type, $id) !== []) {
                throw new RuntimeException('The object still has dependents that are not part of the applied rollback.');
            }
            $current->delete();
        } else {
            $current->replaceWith($before)->store();
        }
    }

    private function object(string $type, string $snapshot): IcingaObject
    {
        $properties = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        if ($type === 'icinga_service_set') {
            unset($properties['services']);
        }
        return IcingaObject::createByType($type, $properties, $this->db);
    }

    private function matchesDeployment(IcingaConfig $rendered, string $checksum, array $unweightedBefore): bool
    {
        if ($rendered->getHexChecksum() === $checksum) {
            return true;
        }

        $active = IcingaConfig::load(hex2bin($checksum), $this->db)->getFiles();
        $generated = $rendered->getFiles();
        if (array_diff_key($active, $generated) || array_diff_key($generated, $active)) {
            return false;
        }

        $unordered = [];
        foreach ($this->unweightedImports() as $key => $imports) {
            if (($unweightedBefore[$key] ?? null) === $imports) {
                $unordered[$key] = $imports['templates'];
            }
        }
        foreach ($active as $path => $file) {
            $before = $file->getContent();
            $after = $generated[$path]->getContent();
            if ($before === $after) {
                continue;
            }
            if (! preg_match('~\Azones\.d/[^/]+/services\.conf\z~', $path)
                || ! ServiceFileComparison::equivalent($before, $after, $unordered)
            ) {
                return false;
            }
        }

        return true;
    }

    private function unweightedImports(): array
    {
        $db = $this->db->getDbAdapter();
        $rows = $db->fetchAll($db->select()->from(['i' => 'icinga_service_inheritance'], [])
            ->join(['s' => 'icinga_service'], 's.id = i.service_id', ['id', 'service' => 's.object_name'])
            ->join(['h' => 'icinga_host'], 'h.id = s.host_id', ['host' => 'h.object_name'])
            ->join(['p' => 'icinga_service'], 'p.id = i.parent_service_id', ['template' => 'p.object_name'])
            ->where('s.object_type = ?', 'object')->where('s.service_set_id IS NULL')->where('i.weight IS NULL')
            ->order('s.id')->order('p.object_name'));
        $imports = [];
        foreach ($rows as $row) {
            $key = json_encode([$row->host, $row->service], JSON_THROW_ON_ERROR);
            $imports[$key]['id'] = $row->id;
            $imports[$key]['templates'][] = $row->template;
        }
        return array_filter($imports, static fn ($group) => count($group['templates']) > 1);
    }

    public static function renderChecksum(Db $db): string
    {
        return self::renderConfig($db)->getHexChecksum();
    }

    private static function renderConfig(Db $db): IcingaConfig
    {
        $config = new class ($db) extends IcingaConfig {
            public function render(): void
            {
                $this->generateFromDb();
                $this->collectExtraFiles();
                $this->calculateChecksum();
            }
        };

        $config->render();
        return $config;
    }
}
