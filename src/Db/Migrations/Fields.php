<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations;

use BrewPHP\Db\Fields\Field;
use BrewPHP\Db\Fields\ForeignKey;

/**
 * Helpers to rebuild fields for migrations.
 *
 * @internal
 */
final class Fields
{
    /**
     * A new, detached field built from what a migration records about
     * `$field`: the same class with the options that change the table.
     */
    public static function forMigration(Field $field): Field
    {
        $deconstructed = $field->deconstructForMigration();

        return self::make($deconstructed['class'], $deconstructed['options']);
    }

    /**
     * The field with some constructor options replaced and others removed.
     *
     * @param array<string, mixed> $replace
     * @param list<string> $remove
     */
    public static function with(Field $field, array $replace = [], array $remove = []): Field
    {
        $deconstructed = $field->deconstructForMigration();
        $options = array_diff_key(array_replace($deconstructed['options'], $replace), array_flip($remove));

        return self::make($deconstructed['class'], $options);
    }

    /**
     * A field that is the same as far as migrations are concerned, as a
     * stable string: two fields with the same signature need no migration.
     */
    public static function signature(Field $field): string
    {
        $deconstructed = $field->deconstructForMigration();
        $options = $deconstructed['options'];
        if ($field instanceof ForeignKey && is_string($options['to'] ?? null)) {
            $options['to'] = strtolower($options['to']);
        }
        ksort($options);

        return $deconstructed['class'] . Writer::export($options);
    }

    /**
     * @param class-string<Field> $class
     * @param array<string, mixed> $options
     */
    public static function make(string $class, array $options): Field
    {
        try {
            return new $class(...$options);
        } catch (\Error $e) {
            throw new MigrationError(sprintf('Cannot rebuild a %s from its options (%s): %s', $class, implode(', ', array_keys($options)), $e->getMessage()), 0, $e);
        }
    }
}
