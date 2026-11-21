<?php

namespace Icinga\Module\Director_rollback;

final class ServiceFileComparison
{
    public static function equivalent(string $active, string $generated, array $unorderedImports = []): bool
    {
        $before = self::objectBlocks($active, $unorderedImports);
        $after = self::objectBlocks($generated, $unorderedImports);
        return $before !== null && $before === $after;
    }

    private static function objectBlocks(string $content, array $unorderedImports): ?array
    {
        // Discard ordering only for complete, independent Director-rendered Service objects.
        // Refuse files containing apply rules, include statements or any other top-level constructs.
        $pattern = '~\G\n*('
            . '(?:/\* --- This (?:object|services host) has been disabled ---\n)?'
            . 'object Service [^\r\n]+ \{\n(?:[ \t]+[^\n]*\n|\n)*\}'
            . '(?:\n*\*/)?'
            . ')(?=\n|\z)~';
        $blocks = [];
        $offset = 0;
        $length = strlen($content);
        while ($offset < $length) {
            if (! preg_match($pattern, $content, $match, 0, $offset)) {
                if (trim(substr($content, $offset)) !== '') {
                    return null;
                }
                break;
            }
            $commented = str_starts_with($match[1], '/*');
            if ($commented !== str_ends_with($match[1], '*/')) {
                return null;
            }
            $blocks[] = self::normalizeUnweightedImports($match[1], $unorderedImports);
            $offset += strlen($match[0]);
        }
        sort($blocks, SORT_STRING);
        return $blocks;
    }

    private static function normalizeUnweightedImports(string $block, array $unorderedImports): string
    {
        if ($unorderedImports === [] || ! preg_match(
            '~^object Service ("[^\n]+") \{\n    host_name = ("[^\n]+")$~m',
            $block,
            $identity
        )) {
            return $block;
        }

        $key = json_encode([json_decode($identity[2]), json_decode($identity[1])], JSON_THROW_ON_ERROR);
        $unordered = $unorderedImports[$key] ?? null;
        if ($unordered === null) {
            return $block;
        }

        $lines = explode("\n", $block);
        $matches = [];
        foreach ($lines as $index => $line) {
            if (preg_match('~^    import ("[^\n]+")$~', $line, $value)
                && in_array(json_decode($value[1]), $unordered, true)
            ) {
                $matches[$index] = $line;
            }
        }
        if (count($matches) !== count($unordered)) {
            return $block;
        }

        $sorted = array_values($matches);
        sort($sorted, SORT_STRING);
        foreach (array_keys($matches) as $position => $index) {
            $lines[$index] = $sorted[$position];
        }
        return implode("\n", $lines);
    }
}
