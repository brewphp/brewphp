<?php

declare(strict_types=1);

namespace BrewPHP\Forms;

/**
 * Small helpers that build HTML for forms. Every value is escaped and
 * attribute names are checked, so no form data can inject markup.
 *
 * @internal
 */
final class Html
{
    public static function escape(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return htmlspecialchars(is_scalar($value) || $value instanceof \Stringable ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * ` name="title" required`: true gives a bare attribute, false and
     * null leave it out.
     *
     * @param array<string, mixed> $attributes
     */
    public static function attributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            if (preg_match('/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', $name) !== 1) {
                throw new \InvalidArgumentException(sprintf('Invalid HTML attribute name "%s".', $name));
            }
            if ($value === null || $value === false) {
                continue;
            }
            $html .= $value === true ? ' ' . $name : sprintf(' %s="%s"', $name, self::escape($value));
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function tag(string $name, array $attributes, ?string $content = null): string
    {
        return '<' . $name . self::attributes($attributes) . '>' . ($content === null ? '' : $content . '</' . $name . '>');
    }
}
