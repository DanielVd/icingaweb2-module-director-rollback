<?php

use Icinga\Module\Director_rollback\RollbackPlan;

require __DIR__ . '/../library/Director_rollback/RollbackPlan.php';

function snapshot(string $name, ?string $host = null, string $kind = 'object'): array
{
    return ['object_name' => $name, 'object_type' => $kind, 'host' => $host];
}

function activity(
    int $id,
    string $type,
    string $name,
    string $action,
    ?array $before,
    ?array $after
): object {
    return (object) [
        'id' => $id,
        'object_type' => $type,
        'object_name' => $name,
        'action_name' => $action,
        'old_properties' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
        'new_properties' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
    ];
}

function expect(array $expected, array $actual): void
{
    if ($actual !== $expected) {
        throw new RuntimeException('Unexpected plan: ' . json_encode($actual, JSON_THROW_ON_ERROR));
    }
}

$host = 'icinga_host';
$service = 'icinga_service';
$plan = RollbackPlan::fromActivities([
    activity(1, $host, 'temporary', 'create', null, snapshot('temporary')),
    activity(2, $host, 'temporary', 'delete', snapshot('temporary'), null),
    activity(3, $host, 'new', 'create', null, snapshot('new')),
    activity(4, $host, 'deleted', 'delete', snapshot('deleted'), null),
    activity(5, $service, 'ping', 'modify', snapshot('ping', 'alpha'), snapshot('ping', 'alpha') + ['display_name' => 'Ping']),
    activity(6, $service, 'ping', 'create', null, snapshot('ping', 'beta')),
    activity(7, $service, 'ping', 'modify', snapshot('ping', 'alpha') + ['display_name' => 'Ping'], snapshot('ping', 'alpha') + ['display_name' => 'Ping v2']),
    activity(8, $host, 'rebuilt', 'delete', snapshot('rebuilt'), null),
    activity(9, $host, 'rebuilt', 'create', null, snapshot('rebuilt') + ['address' => '192.0.2.11']),
]);
expect(
    [
        ['rebuilt', '', 'restore', 8, 9, 2],
        ['ping', 'alpha', 'restore', 5, 7, 2],
        ['ping', 'beta', 'remove', 6, 6, 1],
        ['deleted', '', 'recreate', 4, 4, 1],
        ['new', '', 'remove', 3, 3, 1],
    ],
    array_map(
        static fn ($row) => [
            $row['name'], $row['scope'], $row['operation'], $row['firstId'], $row['lastId'], $row['activityCount'],
        ],
        $plan
    )
);

$plan = RollbackPlan::fromActivities([
    activity(1, $host, 'old', 'create', null, snapshot('old')),
    activity(2, $host, 'renamed', 'modify', snapshot('old'), snapshot('renamed')),
    activity(3, $host, 'renamed', 'modify', snapshot('renamed'), snapshot('renamed')),
    activity(4, $service, 'apply', 'create', null, snapshot('apply', null, 'apply')),
    activity(5, $host, 'legacy', 'modify', ['vars' => []], snapshot('legacy')),
    activity(6, $host, 'inconsistent', 'create', null, snapshot('inconsistent')),
    activity(7, $host, 'inconsistent', 'create', null, snapshot('inconsistent')),
    activity(8, 'icinga_command', 'check', 'delete', snapshot('check'), null),
]);
expect(['review', 'review', 'review', 'remove', 'review', 'review', 'review'], array_column($plan, 'operation'));
expect([], RollbackPlan::fromActivities([
    activity(1, $host, 'restored', 'modify', snapshot('restored'), snapshot('restored') + ['display_name' => 'Test']),
    activity(2, $host, 'restored', 'modify', snapshot('restored') + ['display_name' => 'Test'], snapshot('restored')),
]));
expect([], RollbackPlan::fromActivities([]));

require __DIR__ . '/../library/Director_rollback/PartialRollbackPlan.php';

$partial = Icinga\Module\Director_rollback\PartialRollbackPlan::class;
expect([7, 12], $partial::normalizeIds(['12', '7', 12]));
foreach (['0', '1e2', '1.5', ['1']] as $invalid) {
    try {
        $partial::normalizeIds([$invalid]);
        throw new RuntimeException('Invalid selection was accepted.');
    } catch (DomainException) {
        // This input must never reach the planner.
    }
}

$plan = RollbackPlan::fromActivities([
    activity(1, $host, 'host', 'create', null, snapshot('host')),
    activity(2, $host, 'template', 'create', null, snapshot('template', null, 'template')),
    activity(3, $service, 'scoped', 'create', null, snapshot('scoped') + ['service_set' => 'set']),
    activity(4, $service, 'direct', 'create', null, snapshot('direct', 'host')),
]);
expect(['direct', 'scoped', 'template', 'host'], array_column(array_filter($plan, $partial::isSelectable(...)), 'name'));

require __DIR__ . '/../library/Director_rollback/RollbackDiff.php';
$diff = Icinga\Module\Director_rollback\RollbackDiff::between(
    '{"address":"192.0.2.3","vars":{"secret":"current secret"}}',
    '{"address":"192.0.2.2","vars":{"secret":"old secret"}}'
);
expect([
    ['field' => 'address', 'current' => '192.0.2.3', 'restored' => '192.0.2.2', 'hidden' => false],
    ['field' => 'vars', 'current' => null, 'restored' => null, 'hidden' => true],
], $diff);

$plan = RollbackPlan::fromActivities([
    activity(1, 'icinga_service_set', 'set', 'create', null, snapshot('set', null, 'template')),
    activity(2, $service, 'duplicate', 'create', null, snapshot('duplicate', null, 'apply')),
    activity(3, $service, 'duplicate', 'create', null, snapshot('duplicate', null, 'apply')),
    activity(4, 'icinga_service_set', 'populated', 'delete',
        snapshot('populated', null, 'template') + ['services' => ['member']], null),
]);
expect(['review', 'review', 'remove'], array_column($plan, 'operation'));

require __DIR__ . '/../library/Director_rollback/ServiceFileComparison.php';
$compare = Icinga\Module\Director_rollback\ServiceFileComparison::class;
$normal = "object Service \"one\" {\n    host_name = \"node\"\n    import \"A\"\n    import \"B\"\n}\n";
$swapped = "object Service \"one\" {\n    host_name = \"node\"\n    import \"B\"\n    import \"A\"\n}\n";
$other = "object Service \"two\" {\n    host_name = \"node\"\n}\n";
$ties = ['["node","one"]' => ['A', 'B']];
expect([true, false, true, false, false], [
    $compare::equivalent($normal . $other, $other . $normal),
    $compare::equivalent($normal, $swapped),
    $compare::equivalent($normal, $swapped, $ties),
    $compare::equivalent($normal, str_replace('import "A"', 'import "C"', $normal), $ties),
    $compare::equivalent($normal, str_replace('object Service', 'apply Service', $normal), $ties),
]);

echo "Rollback plan tests passed\n";
