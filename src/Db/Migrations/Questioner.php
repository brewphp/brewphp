<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations;

/**
 * Questions `makemigrations` cannot answer alone: was a field renamed, or
 * removed and another added? What value should existing rows get?
 *
 * Without callbacks (non-interactive, e.g. `--no-interaction` in CI) it
 * never assumes a rename and refuses to guess a default.
 */
class Questioner
{
    /**
     * @param (\Closure(string): bool)|null $confirm asks a yes/no question
     * @param (\Closure(string): ?string)|null $ask asks for a line of text
     */
    public function __construct(private readonly ?\Closure $confirm = null, private readonly ?\Closure $ask = null)
    {
    }

    public function isInteractive(): bool
    {
        return $this->confirm !== null && $this->ask !== null;
    }

    public function askRenameModel(string $app, string $old, string $new): bool
    {
        return $this->confirm !== null && ($this->confirm)(sprintf('Was the model %s.%s renamed to %s?', $app, $old, $new));
    }

    public function askRenameField(string $model, string $old, string $new): bool
    {
        return $this->confirm !== null && ($this->confirm)(sprintf('Was %s.%s renamed to %s.%s?', $model, $old, $model, $new));
    }

    /**
     * A one-off value for existing rows when a non-null field without a
     * default is added.
     */
    public function askNotNullAddition(string $model, string $field): mixed
    {
        if ($this->ask === null) {
            throw new MigrationError(sprintf(
                'Cannot add the non-nullable field "%s" to %s without a default: existing rows need a value. '
                . 'Give the field a default or null: true, or run makemigrations interactively to provide a one-off value.',
                $field,
                $model,
            ));
        }

        return $this->askValue(sprintf('The new field %s.%s cannot be null and has no default. Value for existing rows', $model, $field), required: true);
    }

    /**
     * A one-off value for rows that are NULL when a field becomes
     * non-null, or null to leave them (the migration then fails if any
     * row is NULL).
     */
    public function askNotNullAlteration(string $model, string $field): mixed
    {
        if ($this->ask === null) {
            return null;
        }

        return $this->askValue(sprintf('%s.%s becomes non-nullable. Value for rows that are NULL (empty: none)', $model, $field), required: false);
    }

    /**
     * Read a literal: a number, true/false, or text (quotes optional).
     */
    public static function parseLiteral(string $answer): mixed
    {
        $text = trim($answer);
        if (preg_match('/^-?\d+$/', $text) === 1) {
            return (int) $text;
        }
        if (is_numeric($text)) {
            return (float) $text;
        }
        if (in_array(strtolower($text), ['true', 'false'], true)) {
            return strtolower($text) === 'true';
        }
        if (preg_match('/^([\'"])(.*)\1$/s', $text, $match) === 1) {
            return $match[2];
        }

        return $text;
    }

    private function askValue(string $question, bool $required): mixed
    {
        \assert($this->ask !== null);
        for ($try = 0; $try < 3; $try++) {
            $answer = ($this->ask)($question . ' (a number, true/false or text)');
            if ($answer !== null && trim($answer) !== '') {
                return self::parseLiteral($answer);
            }
            if (!$required) {
                return null;
            }
        }

        throw new MigrationError('No value given; makemigrations stopped.');
    }
}
