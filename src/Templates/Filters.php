<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

use BrewPHP\Db\QuerySet;
use BrewPHP\Support\Str;

/**
 * The built-in filters. Each one is a plain static method, so they are
 * easy to read and to reuse in your own filters.
 */
final class Filters
{
    /** Default format of the `date` filter (PHP date() characters). */
    public const DATE_FORMAT = 'M j, Y';

    /** Default format of the `time` filter. */
    public const TIME_FORMAT = 'H:i';

    public static function register(Library $library): void
    {
        $safe = ['capfirst', 'floatformat', 'length', 'lower', 'slice', 'slugify', 'striptags', 'title', 'truncatechars', 'truncatewords', 'wordcount'];
        $methods = [
            'add' => self::add(...), 'capfirst' => self::capfirst(...), 'cut' => self::cut(...),
            'date' => self::date(...), 'default' => self::default(...), 'default_if_none' => self::defaultIfNone(...),
            'dictsort' => self::dictsort(...), 'dictsortreversed' => self::dictsortreversed(...),
            'divisibleby' => self::divisibleby(...), 'escape' => self::escape(...), 'first' => self::first(...),
            'floatformat' => self::floatformat(...), 'json_script' => self::jsonScript(...), 'last' => self::last(...),
            'length' => self::length(...), 'lower' => self::lower(...), 'pluralize' => self::pluralize(...),
            'safe' => self::safe(...), 'slice' => self::slice(...), 'slugify' => self::slugify(...),
            'striptags' => self::striptags(...), 'time' => self::time(...), 'timesince' => self::timesince(...),
            'title' => self::title(...), 'truncatechars' => self::truncatechars(...),
            'truncatewords' => self::truncatewords(...), 'upper' => self::upper(...), 'urlencode' => self::urlencode(...),
            'wordcount' => self::wordcount(...), 'yesno' => self::yesno(...),
        ];
        foreach ($methods as $name => $filter) {
            $library->filter($name, $filter, isSafe: in_array($name, $safe, true));
        }
        $library->filter('join', self::join(...), needsAutoescape: true);
        $library->filter('linebreaks', self::linebreaks(...), needsAutoescape: true);
        $library->filter('linebreaksbr', self::linebreaksbr(...), needsAutoescape: true);
    }

    // Strings -------------------------------------------------------------------

    public static function capfirst(mixed $value): string
    {
        $text = Runtime::toString($value);

        return self::upperString(self::substr($text, 0, 1)) . self::substr($text, 1);
    }

    public static function lower(mixed $value): string
    {
        $text = Runtime::toString($value);

        return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
    }

    public static function upper(mixed $value): string
    {
        return self::upperString(Runtime::toString($value));
    }

    public static function title(mixed $value): string
    {
        $text = self::lower($value);

        return (string) preg_replace_callback('/(^|[^\p{L}\p{N}\'])(\p{L})/u', static fn (array $m): string => $m[1] . self::upperString($m[2]), $text);
    }

    /**
     * Remove every occurrence of $search: `{{ value|cut:" " }}`.
     */
    public static function cut(mixed $value, mixed $search = ''): string
    {
        $search = Runtime::toString($search);

        return $search === '' ? Runtime::toString($value) : str_replace($search, '', Runtime::toString($value));
    }

    public static function slugify(mixed $value): string
    {
        return Str::slug(Runtime::toString($value));
    }

    public static function striptags(mixed $value): string
    {
        return strip_tags(Runtime::toString($value));
    }

    /**
     * Cut to $length characters, the last one being "…".
     */
    public static function truncatechars(mixed $value, mixed $length = 0): string
    {
        $text = Runtime::toString($value);
        $length = (int) $length;
        if ($length <= 0 || self::strlen($text) <= $length) {
            return $length <= 0 ? '' : $text;
        }

        return self::substr($text, 0, $length - 1) . '…';
    }

    /**
     * Keep the first $count words, then " …".
     */
    public static function truncatewords(mixed $value, mixed $count = 0): string
    {
        $words = preg_split('/\s+/u', trim(Runtime::toString($value)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $count = (int) $count;
        if (count($words) <= $count) {
            return implode(' ', $words);
        }

        return $count <= 0 ? '' : implode(' ', array_slice($words, 0, $count)) . ' …';
    }

    public static function wordcount(mixed $value): int
    {
        return count(preg_split('/\s+/u', trim(Runtime::toString($value)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Percent-encode for a URL; $safe characters (default "/") are kept.
     */
    public static function urlencode(mixed $value, mixed $safe = '/'): string
    {
        $encoded = rawurlencode(Runtime::toString($value));
        foreach (str_split(Runtime::toString($safe)) as $char) {
            if ($char !== '') {
                $encoded = str_replace(rawurlencode($char), $char, $encoded);
            }
        }

        return $encoded;
    }

    /**
     * "s" unless the value (a number or a countable) is 1. The argument is
     * a suffix ("es") or "singular,plural" ("y,ies").
     */
    public static function pluralize(mixed $value, mixed $suffixes = 's'): string
    {
        $parts = explode(',', Runtime::toString($suffixes));
        [$singular, $plural] = count($parts) >= 2 ? [$parts[0], $parts[1]] : ['', $parts[0]];
        $count = match (true) {
            is_array($value), $value instanceof \Countable => count($value),
            is_numeric($value) => (float) $value,
            default => null,
        };

        return $count === null ? '' : ($count == 1 ? $singular : $plural);
    }

    /**
     * "yes,no,maybe": the first word for true, the second for false, the
     * third (or the second) for null.
     */
    public static function yesno(mixed $value, mixed $choices = 'yes,no,maybe'): string
    {
        $words = explode(',', Runtime::toString($choices));
        if (count($words) < 2) {
            return Runtime::toString($value);
        }

        return match (true) {
            $value === null => $words[2] ?? $words[1],
            Runtime::truthy($value) => $words[0],
            default => $words[1],
        };
    }

    // Escaping and HTML ---------------------------------------------------------

    /**
     * Escape now (a value that is already safe is left alone).
     */
    public static function escape(mixed $value): SafeString
    {
        return new SafeString(Runtime::escape($value));
    }

    /**
     * Mark the value as safe HTML: it will not be escaped.
     */
    public static function safe(mixed $value): SafeString
    {
        return $value instanceof SafeString ? $value : new SafeString(Runtime::toString($value));
    }

    /**
     * Paragraphs: a blank line starts a new `<p>`, a single newline is a `<br>`.
     */
    public static function linebreaks(mixed $value, bool $autoescape = true): SafeString
    {
        $text = str_replace(["\r\n", "\r"], "\n", self::escapeIf($value, $autoescape));
        $paragraphs = preg_split('/\n{2,}/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return new SafeString(implode("\n\n", array_map(
            static fn (string $p): string => '<p>' . str_replace("\n", '<br>', $p) . '</p>',
            $paragraphs,
        )));
    }

    public static function linebreaksbr(mixed $value, bool $autoescape = true): SafeString
    {
        return new SafeString(str_replace(["\r\n", "\r", "\n"], '<br>', self::escapeIf($value, $autoescape)));
    }

    /**
     * Join a list with $glue. With auto-escaping, items and glue are escaped.
     */
    public static function join(mixed $value, mixed $glue = ', ', bool $autoescape = true): mixed
    {
        if (!is_iterable($value)) {
            return $value;
        }
        $items = [];
        foreach ($value as $item) {
            $items[] = self::escapeIf($item, $autoescape);
        }

        return new SafeString(implode(self::escapeIf($glue, $autoescape), $items));
    }

    /**
     * The value as JSON in a `<script type="application/json">` element,
     * safe to read from JavaScript with
     * `JSON.parse(document.getElementById('id').textContent)`.
     * `<`, `>` and `&` are written as JSON unicode escapes, so the
     * data cannot close the script element.
     */
    public static function jsonScript(mixed $value, mixed $id = null): SafeString
    {
        $json = json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $id = $id === null || $id === '' ? '' : ' id="' . Runtime::escapeHtml(Runtime::toString($id)) . '"';

        return new SafeString('<script' . $id . ' type="application/json">' . $json . '</script>');
    }

    // Values and lists ----------------------------------------------------------

    /**
     * The argument when the value is false (null, '', 0, empty list...).
     */
    public static function default(mixed $value, mixed $default = ''): mixed
    {
        return Runtime::truthy($value) ? $value : $default;
    }

    public static function defaultIfNone(mixed $value, mixed $default = ''): mixed
    {
        return $value ?? $default;
    }

    /**
     * Add numbers, or concatenate strings or lists.
     */
    public static function add(mixed $value, mixed $other = 0): mixed
    {
        if (is_numeric($value) && is_numeric($other)) {
            return $value + $other;
        }
        if (is_array($value) && is_array($other)) {
            return array_merge($value, $other);
        }
        try {
            return Runtime::toString($value) . Runtime::toString($other);
        } catch (TemplateError) {
            return '';
        }
    }

    public static function divisibleby(mixed $value, mixed $divisor = 1): bool
    {
        return (int) $divisor !== 0 && (int) $value % (int) $divisor === 0;
    }

    public static function length(mixed $value): int
    {
        return match (true) {
            $value === null => 0,
            is_array($value), $value instanceof \Countable => count($value),
            is_string($value), $value instanceof \Stringable => self::strlen((string) $value),
            $value instanceof \Traversable => iterator_count($value),
            default => 0,
        };
    }

    public static function first(mixed $value): mixed
    {
        $items = self::items($value);

        return $items === [] ? '' : $items[0];
    }

    public static function last(mixed $value): mixed
    {
        $items = self::items($value);

        return $items === [] ? '' : $items[count($items) - 1];
    }

    /**
     * Python slice syntax: "2", ":3", "1:", "1:-1", "::2".
     */
    public static function slice(mixed $value, mixed $range = ':'): mixed
    {
        $isString = is_string($value) || $value instanceof \Stringable;
        $items = self::items($value);
        $parts = array_map('trim', explode(':', Runtime::toString($range)));
        $count = count($items);
        $normalize = static fn (string $part, int $default): int => $part === ''
            ? $default
            : max(0, min($count, (int) $part < 0 ? $count + (int) $part : (int) $part));
        $start = count($parts) === 1 ? 0 : $normalize($parts[0], 0);
        $stop = count($parts) === 1 ? $normalize($parts[0], $count) : $normalize($parts[1], $count);
        $step = max(1, (int) ($parts[2] ?? 1));

        $result = [];
        for ($i = $start; $i < $stop; $i += $step) {
            $result[] = $items[$i];
        }

        return $isString ? implode('', array_map(Runtime::toString(...), $result)) : $result;
    }

    /**
     * Sort a list of arrays or objects by a key (dotted paths work).
     *
     * @return list<mixed>
     */
    public static function dictsort(mixed $value, mixed $key = ''): array
    {
        $items = self::items($value);
        $path = explode('.', Runtime::toString($key));
        $resolve = static function (mixed $item) use ($path): mixed {
            foreach ($path as $part) {
                $item = Runtime::attribute($item, ctype_digit($part) ? (int) $part : $part);
            }

            return $item instanceof SafeString || $item instanceof \Stringable ? (string) $item : $item;
        };
        usort($items, static fn (mixed $a, mixed $b): int => $resolve($a) <=> $resolve($b));

        return $items;
    }

    /**
     * @return list<mixed>
     */
    public static function dictsortreversed(mixed $value, mixed $key = ''): array
    {
        return array_reverse(self::dictsort($value, $key));
    }

    // Numbers -------------------------------------------------------------------

    /**
     * Round a number. No argument: one decimal, only when needed (34.23 →
     * "34.2", 34.0 → "34"). "2": always two decimals. "-2": up to two,
     * only when needed. A "g" suffix adds thousands separators ("2g").
     */
    public static function floatformat(mixed $value, mixed $argument = -1): string
    {
        if (!is_numeric($value)) {
            return Runtime::toString($value instanceof \Stringable || is_scalar($value) ? $value : '');
        }
        $argument = Runtime::toString($argument);
        $grouping = str_ends_with($argument, 'g');
        $digits = (int) rtrim($argument, 'g');
        $number = (float) $value;
        $decimals = abs($digits);
        if ($digits < 0 && round($number, $decimals) == round($number)) {
            $decimals = 0;
        }

        return number_format($number, $decimals, '.', $grouping ? ',' : '');
    }

    // Dates ---------------------------------------------------------------------

    /**
     * Format a date with PHP date() characters: `{{ post.created_at|date:"Y-m-d" }}`.
     */
    public static function date(mixed $value, mixed $format = self::DATE_FORMAT): string
    {
        $date = self::toDate($value);

        return $date === null ? '' : $date->format(Runtime::toString($format));
    }

    public static function time(mixed $value, mixed $format = self::TIME_FORMAT): string
    {
        return self::date($value, $format);
    }

    /**
     * The time between the value and now (or the argument), e.g. "3 days, 2 hours".
     */
    public static function timesince(mixed $value, mixed $now = null): string
    {
        $date = self::toDate($value);
        if ($date === null) {
            return '';
        }
        $now = self::toDate($now) ?? new \DateTimeImmutable();
        $seconds = $now->getTimestamp() - $date->getTimestamp();
        if ($seconds <= 0) {
            return '0 minutes';
        }

        $units = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];
        foreach ($units as $i => [$name, $size]) {
            $count = intdiv($seconds, $size);
            if ($count === 0) {
                continue;
            }
            $result = $count . ' ' . $name . ($count === 1 ? '' : 's');
            if (isset($units[$i + 1])) {
                [$nextName, $nextSize] = $units[$i + 1];
                $next = intdiv($seconds - $count * $size, $nextSize);
                if ($next > 0) {
                    $result .= ', ' . $next . ' ' . $nextName . ($next === 1 ? '' : 's');
                }
            }

            return $result;
        }

        return '0 minutes';
    }

    // Helpers -------------------------------------------------------------------

    /**
     * A date from a DateTimeInterface, a timestamp or a date string; null otherwise.
     */
    public static function toDate(mixed $value): ?\DateTimeInterface
    {
        try {
            return match (true) {
                $value instanceof \DateTimeInterface => $value,
                is_int($value) => (new \DateTimeImmutable('@' . $value))->setTimezone(new \DateTimeZone(date_default_timezone_get())),
                is_string($value) && trim($value) !== '' => new \DateTimeImmutable($value),
                default => null,
            };
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * A list of the items of an array, iterable or string (its characters).
     *
     * @return list<mixed>
     */
    private static function items(mixed $value): array
    {
        return match (true) {
            is_array($value) => array_values($value),
            $value instanceof QuerySet => $value->toArray(),
            $value instanceof \Traversable => iterator_to_array($value, false),
            is_string($value), $value instanceof \Stringable => preg_split('//u', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            default => [],
        };
    }

    private static function escapeIf(mixed $value, bool $autoescape): string
    {
        return $autoescape ? Runtime::escape($value) : Runtime::toString($value);
    }

    private static function upperString(string $text): string
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($text) : strtoupper($text);
    }

    private static function strlen(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }

    private static function substr(string $text, int $start, ?int $length = null): string
    {
        return function_exists('mb_substr') ? mb_substr($text, $start, $length) : substr($text, $start, $length);
    }
}
