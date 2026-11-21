<?php

namespace Icinga\Module\Director_rollback;

final class RollbackDiff
{
    private const VISIBLE_FIELDS = [
        'address', 'address6', 'assign_filter', 'check_command', 'check_interval', 'check_period',
        'check_timeout', 'description', 'disabled', 'display_name', 'groups', 'host', 'imports',
        'max_check_attempts', 'object_name', 'object_type', 'retry_interval', 'service_set',
    ];

    public static function between(?string $current, ?string $restored): array
    {
        $current = $current === null ? [] : json_decode($current, true, 512, JSON_THROW_ON_ERROR);
        $restored = $restored === null ? [] : json_decode($restored, true, 512, JSON_THROW_ON_ERROR);
        $fields = array_unique(array_merge(array_keys($current), array_keys($restored)));
        sort($fields);
        $diff = [];
        foreach ($fields as $field) {
            $hasCurrent = array_key_exists($field, $current);
            $hasRestored = array_key_exists($field, $restored);
            if ($hasCurrent === $hasRestored && ($current[$field] ?? null) === ($restored[$field] ?? null)) {
                continue;
            }
            $visible = in_array($field, self::VISIBLE_FIELDS, true);
            $diff[] = [
                'field' => $field,
                'current' => ! $hasCurrent || ! $visible ? null : self::format($current[$field]),
                'restored' => ! $hasRestored || ! $visible ? null : self::format($restored[$field]),
                'hidden' => ! $visible,
            ];
        }
        return $diff;
    }

    private static function format(mixed $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
