<?php

declare(strict_types=1);

namespace BrewPHP\Support;

/**
 * String helpers used across the framework.
 */
final class Str
{
    /**
     * "blog_posts", "blog-posts", "blog posts" -> "BlogPosts".
     */
    public static function studly(string $value): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_map(static fn (string $w): string => ucfirst($w), $words));
    }

    /**
     * "BlogPosts" -> "blogPosts".
     */
    public static function camel(string $value): string
    {
        return lcfirst(self::studly($value));
    }

    /**
     * "BlogPosts" -> "blog_posts".
     */
    public static function snake(string $value, string $delimiter = '_'): string
    {
        $value = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1' . $delimiter . '$2', $value);
        $value = (string) preg_replace('/[^A-Za-z0-9]+/', $delimiter, $value);

        return strtolower(trim($value, $delimiter));
    }

    /**
     * "SendReport" -> "send-report".
     */
    public static function kebab(string $value): string
    {
        return self::snake($value, '-');
    }

    /**
     * "Hello World!" -> "hello-world". Non-ASCII letters are transliterated
     * when the iconv extension is available.
     */
    public static function slug(string $value, string $separator = '-'): string
    {
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }
        $value = strtolower($value);
        $value = (string) preg_replace('/[^a-z0-9]+/', $separator, $value);

        return trim($value, $separator);
    }

    /**
     * A cryptographically secure random string of URL-safe characters.
     */
    public static function random(int $length = 32): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    /**
     * Shorten a string to $limit characters, adding $end when cut.
     */
    public static function limit(string $value, int $limit, string $end = '...'): string
    {
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length <= $limit) {
            return $value;
        }
        $cut = function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);

        return rtrim($cut) . $end;
    }
}
