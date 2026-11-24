<?php

declare(strict_types=1);

namespace Keyslip;

/**
 * How PHP actually treats map keys. The dump is for humans; the loss check
 * calls array_merge() so the tool cannot drift from the runtime.
 */
final class Keys
{
    public static function dump(array $array): string
    {
        if ($array === []) {
            return '[]';
        }

        $parts = [];
        foreach ($array as $key => $value) {
            $parts[] = self::dumpKey($key) . ' => ' . self::dumpValue($value);
        }

        return '[' . implode(', ', $parts) . ']';
    }

    public static function json(array $array): string
    {
        return json_encode(
            $array,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /**
     * array_merge() renumbers integer keys. String keys survive.
     * A list (0..n-1) stays a list, so that renumber is not a loss.
     */
    public static function losesKeys(array $array): bool
    {
        return array_keys($array) !== array_keys(array_merge($array));
    }

    public static function isMapName(string $name): bool
    {
        $name = ltrim($name, '$');
        if ($name === '') {
            return false;
        }
        if (preg_match('/ById$/', $name) === 1) {
            return true;
        }
        if (preg_match('/(?:^|_)by_?id$/i', $name) === 1) {
            return true;
        }

        if (str_contains($name, '_')) {
            $last = strtolower(substr($name, (int) strrpos($name, '_') + 1));
        } elseif (preg_match('/[A-Z]/', $name) === 1 && preg_match('/[a-z]/', $name) === 1) {
            preg_match_all('/[A-Z]?[a-z0-9]+|[A-Z]+(?![a-z])/', $name, $parts);
            $piece = $parts[0][count($parts[0]) - 1] ?? $name;
            $last = strtolower($piece);
        } else {
            $last = strtolower($name);
        }

        return in_array($last, ['map', 'lookup', 'keyed', 'dict', 'dictionary', 'index'], true);
    }

    private static function dumpKey(int|string $key): string
    {
        if (is_int($key)) {
            return (string) $key;
        }

        return '"' . self::escape($key) . '"';
    }

    private static function dumpValue(mixed $value): string
    {
        if (is_array($value)) {
            return self::dump($value);
        }
        if (is_string($value)) {
            return '"' . self::escape($value) . '"';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        return '"?"';
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $text);
    }
}
