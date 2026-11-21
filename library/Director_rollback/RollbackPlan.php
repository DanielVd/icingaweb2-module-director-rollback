<?php

namespace Icinga\Module\Director_rollback;

final class RollbackPlan
{
    /**
     * Fold activity in ascending ID order into the operations that undo it.
     * Entries with ambiguous identity stay visible as requiring manual review.
     */
    public static function fromActivities(iterable $activities): array
    {
        $groups = [];
        $unresolved = [];
        $blockedNames = [];

        foreach ($activities as $activity) {
            $old = self::identity($activity->object_type, $activity->old_properties);
            $new = self::identity($activity->object_type, $activity->new_properties);
            $identity = match ($activity->action_name) {
                'create' => $activity->old_properties === null ? $new : null,
                'modify' => $old === $new ? $new : null,
                'delete' => $activity->new_properties === null ? $old : null,
                default => null,
            };

            if ($identity === null || $identity['name'] !== $activity->object_name) {
                foreach ([$activity->object_name, $old['name'] ?? null, $new['name'] ?? null] as $name) {
                    if ($name !== null) {
                        $blockedNames[$activity->object_type][$name] = true;
                    }
                }
                $unresolved[] = self::row($activity, '', 'review');
                continue;
            }

            $key = json_encode([$activity->object_type, $identity], JSON_THROW_ON_ERROR);
            $existed = $activity->action_name !== 'create';
            $exists = $activity->action_name !== 'delete';
            if (! isset($groups[$key])) {
                $groups[$key] = self::row($activity, $identity['scope'], '') + [
                    'existed' => $existed,
                    'exists' => $exists,
                    'consistent' => true,
                    'before' => $activity->old_properties,
                    'after' => $activity->new_properties,
                ];
            } else {
                $group = &$groups[$key];
                $group['consistent'] = $group['consistent'] && $group['exists'] === $existed;
                $group['exists'] = $exists;
                $group['after'] = $activity->new_properties;
                $group['lastId'] = (int) $activity->id;
                $group['activityIds'][] = (int) $activity->id;
                $group['activityCount'] = count($group['activityIds']);
                unset($group);
            }
        }

        $rows = $unresolved;
        foreach ($groups as $group) {
            if (! $group['consistent'] || isset($blockedNames[$group['type']][$group['name']])) {
                $group['operation'] = 'review';
            } elseif ($group['existed']) {
                if ($group['exists'] && self::sameState($group['before'], $group['after'])) {
                    continue;
                }
                $group['operation'] = $group['exists'] ? 'restore' : 'recreate';
            } elseif ($group['exists']) {
                $group['operation'] = 'remove';
            } else {
                continue;
            }

            unset($group['existed'], $group['exists'], $group['consistent'], $group['before'], $group['after']);
            $rows[] = $group;
        }

        usort($rows, static fn (array $a, array $b): int => $b['lastId'] <=> $a['lastId']);
        return $rows;
    }

    public static function sameState(string $left, string $right): bool
    {
        $normalize = static function (string $snapshot): array {
            $properties = (array) json_decode($snapshot, false, 512, JSON_THROW_ON_ERROR);
            foreach (['groups', 'imports', 'vars', 'custom_properties'] as $property) {
                if (isset($properties[$property]) && (array) $properties[$property] === []) {
                    unset($properties[$property]);
                }
            }
            ksort($properties);
            return array_map(static fn ($value) => json_encode($value, JSON_THROW_ON_ERROR), $properties);
        };

        return $normalize($left) === $normalize($right);
    }

    private static function identity(string $type, ?string $snapshot): ?array
    {
        if ($snapshot === null || ! in_array($type, ['icinga_host', 'icinga_service'], true)) {
            return null;
        }

        $properties = json_decode($snapshot);
        if (! is_object($properties) || ! is_string($properties->object_name ?? null)
            || ! in_array($properties->object_type ?? null, ['object', 'template'], true)
        ) {
            return null;
        }

        $host = $type === 'icinga_service' ? ($properties->host ?? null) : null;
        $serviceSet = $type === 'icinga_service' ? ($properties->service_set ?? null) : null;
        if (($host !== null && ! is_string($host)) || ($serviceSet !== null && ! is_string($serviceSet))) {
            return null;
        }

        return [
            'name' => $properties->object_name,
            'kind' => $properties->object_type,
            'host' => $host,
            'serviceSet' => $serviceSet,
            'scope' => implode(' / ', array_filter([$host, $serviceSet], static fn ($name) => $name !== null)),
        ];
    }

    private static function row(object $activity, string $scope, string $operation): array
    {
        return [
            'type' => $activity->object_type,
            'name' => $activity->object_name,
            'scope' => $scope,
            'operation' => $operation,
            'firstId' => (int) $activity->id,
            'lastId' => (int) $activity->id,
            'activityCount' => 1,
            'activityIds' => [(int) $activity->id],
        ];
    }
}
