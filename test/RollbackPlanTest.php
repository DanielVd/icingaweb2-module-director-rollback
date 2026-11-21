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
expect(array_fill(0, 7, 'review'), array_column($plan, 'operation'));
expect([], RollbackPlan::fromActivities([
    activity(1, $host, 'restored', 'modify', snapshot('restored'), snapshot('restored') + ['display_name' => 'Test']),
    activity(2, $host, 'restored', 'modify', snapshot('restored') + ['display_name' => 'Test'], snapshot('restored')),
]));
expect([], RollbackPlan::fromActivities([]));

echo "Rollback plan tests passed\n";
