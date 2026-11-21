<?php

use Icinga\Application\Cli;
use Icinga\Application\Config;
use Icinga\Data\ConfigObject;
use Icinga\Module\Director\Db;
use Icinga\Module\Director\IcingaConfig\IcingaConfig;
use Icinga\Module\Director\Objects\IcingaHost;
use Icinga\Module\Director\Objects\IcingaObject;
use Icinga\Module\Director_rollback\PartialRollbackPlan;
use Icinga\Module\Director_rollback\PendingChanges;
use Icinga\Module\Director_rollback\RollbackExecutor;

require '/usr/share/php/Icinga/Application/Cli.php';
require __DIR__ . '/FixtureObjects.php';

$database = $argv[1] ?? '';
if (! preg_match('/\Adirector_rollback_test_[0-9]+\z/', $database)) {
    throw new InvalidArgumentException('Provide a disposable local database named director_rollback_test_<digits>.');
}

$application = Cli::start('/usr/share/icingaweb2');
$manager = $application->getModuleManager();
$manager->loadModule('director');
$manager->loadModule('director_rollback', dirname(__DIR__));
$config = new ConfigObject([
    'type' => 'db', 'db' => 'mysql', 'host' => 'localhost', 'dbname' => $database, 'username' => 'root',
]);
$db = new Db($config);
$director = Db::fromResourceName(Config::module('director')->get('db', 'resource'));
$api = $director->getDeploymentEndpoint()->api();
$pending = new PendingChanges($db, $api);
$executor = new RollbackExecutor($db, $api);
$planner = new PartialRollbackPlan($db);

function requireThat(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function expectAbort(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException $error) {
        requireThat(str_contains($error->getMessage(), $message), $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rollback to abort: ' . $message);
}

$select = static function (array $names) use ($pending, $planner): array {
    $snapshot = $pending->snapshot();
    $rows = $pending->plan($snapshot);
    $ids = array_column($rows, 'firstId', 'name');
    $chosen = array_map(static fn ($name) => $ids[$name], $names);
    return $planner->select($snapshot, $rows, $chosen, $pending->objectSnapshots($rows));
};

switch ($argv[2] ?? '') {
    case 'prepare':
        requireThat($pending->preview()['count'] === 0, 'Start with a copy of a Director database without pending changes.');
        createRollbackFixtures($db, 'director-rollback-integration');
        requireThat($pending->preview()['count'] === 9 && $pending->preview()['canApply'], 'Expected nine supported fixtures.');
        RollbackExecutor::renderChecksum($db);
        break;

    case 'partial':
        $snapshot = $pending->snapshot();
        $plan = $pending->plan($snapshot);
        $states = $pending->objectSnapshots($plan);
        $host = $plan[array_search('director-rollback-integration-host-01', array_column($plan, 'name'), true)];
        $service = array_values(array_filter($plan, static fn ($row) => $row['host'] === $host['name']))[0];
        $links = $planner->selectionLinks($plan, $states);
        requireThat(in_array($service['firstId'], $links[$host['firstId']], true)
            && in_array($host['firstId'], $links[$service['firstId']], true), 'Host and service are not linked in the selector.');
        $childSelection = $planner->select($snapshot, $plan, [$service['firstId']], $states);
        requireThat(array_column($childSelection['rows'], 'firstId') === [$service['firstId'], $host['firstId']],
            'Selecting the service must include its pending host and order the removal child first.');
        try {
            $planner->select($snapshot, [$host], [$host['firstId']], $states);
            throw new RuntimeException('Host removal was accepted without its dependent service.');
        } catch (DomainException $error) {
            requireThat(str_contains($error->getMessage(), 'outside the plan'), $error->getMessage());
        }

        $selection = $select(['director-rollback-integration-host-template', 'director-rollback-integration-set-member']);
        requireThat(count($selection['rows']) === 5 && count($selection['reasons']) >= 3, 'Dependency closure is incorrect.');
        requireThat(in_array('director-rollback-integration-set', array_column($selection['rows'], 'name'), true),
            'Selecting a member must include the pending service set.');
        $executor->apply($selection['token'], $selection['requested']);
        requireThat($pending->preview()['count'] === 4, 'Partial rollback must preserve four unrelated operations.');
        break;

    case 'stale':
        $preview = $pending->preview();
        $other = new Db($config);
        IcingaHost::create([
            'object_name' => 'director-rollback-concurrent', 'object_type' => 'object',
            'address' => '192.0.2.160', 'check_command' => 'hostalive',
        ], $other)->store();
        expectAbort(static fn () => $executor->apply($preview['token']), 'no longer current');
        requireThat($pending->preview()['count'] === 5, 'Stale confirmation must preserve every pending change.');
        break;

    case 'atomic':
        $preview = $pending->preview();
        $adapter = $db->getDbAdapter();
        $baselineHost = $adapter->fetchRow($adapter->select()->from('icinga_host', ['id', 'display_name'])
            ->where('object_type = ?', 'object')->where('disabled = ?', 'n')
            ->where('object_name NOT LIKE ?', 'director-rollback-%')->limit(1));
        requireThat($baselineHost !== false, 'The copied configuration must contain an enabled baseline host.');
        $where = ['id = ?' => $baselineHost->id];
        $adapter->update('icinga_host', ['display_name' => 'Unlogged test drift'], $where);
        try {
            expectAbort(static fn () => $executor->apply($preview['token']), 'does not match');
            requireThat($pending->preview()['token'] === $preview['token'], 'An aborted transaction changed the audit log.');
            requireThat(IcingaHost::exists('director-rollback-concurrent', $db), 'An aborted transaction deleted a pending object.');
        } finally {
            $adapter->update('icinga_host', ['display_name' => $baselineHost->display_name], $where);
        }
        break;

    case 'full':
        $executor->apply($pending->preview()['token']);
        requireThat($pending->preview()['count'] === 0, 'Full rollback did not clear the plan.');
        break;

    case 'independent':
        $adapter = $db->getDbAdapter();
        $parent = $adapter->fetchOne($adapter->select()->from('icinga_host', 'object_name')
            ->where('object_type = ?', 'object')->where('disabled = ?', 'n')->limit(1));
        requireThat(is_string($parent), 'The copied configuration must contain an enabled baseline host.');
        $service = IcingaObject::createByType('icinga_service', [
            'object_name' => 'director-rollback-independent-service', 'object_type' => 'object',
            'host' => $parent, 'disabled' => true, 'check_command' => 'dummy',
        ], $db);
        $service->store();
        $selection = $select(['director-rollback-independent-service']);
        requireThat(count($selection['rows']) === 1, 'A parent without pending changes must stay out of the selection.');
        $executor->apply($selection['token'], $selection['requested']);
        requireThat(IcingaHost::exists($parent, $db) && $pending->preview()['count'] === 0,
            'Independent service rollback deleted its deployed parent or left pending operations.');
        break;

    case 'prepare-restore':
        $set = IcingaObject::createByType('icinga_service_set', [
            'object_name' => 'director-rollback-empty-set', 'object_type' => 'template', 'description' => 'Before',
        ], $db);
        $set->store();
        $rule = IcingaObject::createByType('icinga_service', [
            'object_name' => 'director-rollback-edit-rule', 'object_type' => 'apply', 'check_command' => 'dummy',
            'assign_filter' => 'host.name=%22director-rollback-absent%22', 'display_name' => 'Before', 'disabled' => true,
        ], $db);
        $rule->store();
        $stage = $pending->snapshot()['stage'];
        $generated = IcingaConfig::generate($db);
        $db->getDbAdapter()->insert('director_deployment_log', [
            'config_checksum' => $db->quoteBinary($generated->getChecksum()),
            'last_activity_checksum' => $db->quoteBinary(hex2bin($db->getLastActivityChecksum())),
            'peer_identity' => 'isolated-test', 'stage_name' => $stage, 'stage_collected' => 'y',
            'startup_succeeded' => 'y', 'dump_succeeded' => 'y',
        ]);
        $rule->set('display_name', 'After')->store();
        $set->delete();
        break;

    case 'restore':
        $snapshot = $pending->snapshot();
        $rows = $pending->plan($snapshot);
        $diffs = $pending->diffs($rows);
        $ids = array_column($rows, 'firstId', 'name');
        requireThat(in_array([
            'field' => 'display_name', 'current' => 'After', 'restored' => 'Before', 'hidden' => false,
        ], $diffs[$ids['director-rollback-edit-rule']], true), 'The diff has incorrect current and restored values.');
        $selection = $select(['director-rollback-empty-set', 'director-rollback-edit-rule']);
        $executor->apply($selection['token'], $selection['requested']);
        requireThat($pending->preview()['count'] === 0, 'The set recreation and rule restoration did not clear the plan.');
        break;

    default:
        throw new InvalidArgumentException('Choose prepare, partial, stale, atomic, full, independent, prepare-restore, or restore.');
}
echo 'Director integration test passed: ' . $argv[2] . PHP_EOL;
