<?php

declare(strict_types=1);

namespace BrewPHP\Support;

/**
 * Small array helpers with "dot" access for nested keys.
 */
final class Arr
{
    /**
     * @param array<mixed> $array
     */
    public static function get(array $array, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }

        return $array;
    }

    /**
     * @param array<mixed> $array
     */
    public static function has(array $array, string $key): bool
    {
        $missing = new \stdClass();

        return self::get($array, $key, $missing) !== $missing;
    }

    /**
     * @param array<mixed> $array
     */
    public static function set(array &$array, string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $current = &$array;
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $current[$segment] = $value;
                break;
            }
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }
            $current = &$current[$segment];
        }
    }

    /**
     * @template K of array-key
     * @template V
     * @param array<K, V> $array
     * @param list<array-key> $keys
     * @return array<K, V>
     */
    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /**
     * @template K of array-key
     * @template V
     * @param array<K, V> $array
     * @param list<array-key> $keys
     * @return array<K, V>
     */
    public static function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    /**
     * Wrap a value in an array unless it already is one.
     *
     * @return array<mixed>
     */
    public static function wrap(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }
}
