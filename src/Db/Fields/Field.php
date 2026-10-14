<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Grammars\Grammar;
use BrewPHP\Db\Model;

/**
 * A model field: one column of the table, with its conversion between PHP
 * and the database, and its validation.
 *
 * Every field accepts the same options as named arguments:
 *
 *     new CharField(maxLength: 200, unique: true, helpText: 'Shown in the admin.')
 *
 * Subclasses add their own options first (like `maxLength`) and pass the
 * common ones on with `...$options`.
 */
abstract class Field
{
    /** The field name in the model, e.g. "author" (set by the model). */
    public string $name = '';

    /** The attribute that holds the raw value, e.g. "author_id" for a ForeignKey. */
    public string $attname = '';

    /** The database column. */
    public string $column = '';

    /** @var class-string<Model>|string the model class the field belongs to ('' for detached fields) */
    public string $model = '';

    /**
     * @param bool $null store empty values as NULL in the database
     * @param bool $blank allow empty values in validation (forms, fullClean)
     * @param mixed $default a value, or a closure returning one, for new objects
     * @param bool $unique add a UNIQUE constraint
     * @param bool $dbIndex add an index on the column
     * @param array<int|string, string>|class-string<\BackedEnum>|null $choices value => label pairs, or a backed enum class
     * @param string|null $verboseName human-readable name (default: the field name with spaces)
     * @param string $helpText extra help shown with form widgets
     * @param bool $editable false hides the field from forms and the admin
     * @param bool $primaryKey make this field the primary key (instead of the implicit `id`)
     * @param string|null $dbColumn column name (default: the field name)
     * @param list<callable(mixed): void> $validators callables that throw ValidationError
     */
    public function __construct(
        public readonly bool $null = false,
        public readonly bool $blank = false,
        public readonly mixed $default = null,
        public readonly bool $unique = false,
        public readonly bool $dbIndex = false,
        public readonly array|string|null $choices = null,
        public readonly ?string $verboseName = null,
        public readonly string $helpText = '',
        public readonly bool $editable = true,
        public readonly bool $primaryKey = false,
        public readonly ?string $dbColumn = null,
        public readonly array $validators = [],
    ) {
        if (is_string($choices) && !is_subclass_of($choices, \BackedEnum::class)) {
            throw new \InvalidArgumentException(sprintf('Field choices must be an array or a BackedEnum class, "%s" given.', $choices));
        }
    }

    /**
     * The column type for a grammar, e.g. "varchar(200)".
     */
    abstract public function columnType(Grammar $grammar): string;

    /**
     * Attach the field to a model under a name. Called by the model's metadata.
     */
    public function contributeTo(string $model, string $name): void
    {
        $this->model = $model;
        $this->name = $name;
        $this->attname = $this->attnameFor($name);
        $this->column = $this->dbColumn ?? $this->attname;
    }

    /**
     * The column type of a foreign key that points to this field.
     */
    public function relatedColumnType(Grammar $grammar): string
    {
        return $this->columnType($grammar);
    }

    /**
     * Whether the database generates the value (auto-increment keys).
     */
    public function isAutoIncrement(): bool
    {
        return false;
    }

    /**
     * Whether this field points to another model.
     */
    public function isRelation(): bool
    {
        return false;
    }

    /**
     * Unique fields and primary keys are indexed by their constraint.
     */
    public function needsIndex(): bool
    {
        return $this->dbIndex && !$this->unique && !$this->primaryKey;
    }

    public function label(): string
    {
        return $this->verboseName ?? str_replace('_', ' ', $this->name);
    }

    // Values ------------------------------------------------------------------

    /**
     * Convert a database (or user) value to the PHP value. Raises
     * ValidationError for input that cannot be converted.
     */
    public function toPhp(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $enum = $this->enumClass();
        if ($enum !== null && !$value instanceof \BackedEnum) {
            $isIntEnum = (string) (new \ReflectionEnum($enum))->getBackingType() === 'int';
            if ($isIntEnum ? !is_numeric($value) : !is_scalar($value)) {
                throw new ValidationError(sprintf('Value %s is not a valid choice.', var_export($value, true)), 'invalid_choice');
            }

            return $enum::tryFrom($isIntEnum ? (int) $value : (string) $value) ?? throw new ValidationError(sprintf('Value %s is not a valid choice.', var_export($value, true)), 'invalid_choice');
        }

        return $value;
    }

    /**
     * Convert a value read from the database. Same as toPhp() unless the
     * stored form differs from user input (JSON text, for example).
     */
    public function fromDb(mixed $value): mixed
    {
        return $this->toPhp($value);
    }

    /**
     * Convert a PHP value to what is stored in the database.
     */
    public function toDb(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    public function hasDefault(): bool
    {
        return $this->default !== null;
    }

    /**
     * The default value for a new object (a closure is called each time).
     */
    public function getDefault(): mixed
    {
        if ($this->default !== null) {
            return $this->default instanceof \Closure ? ($this->default)() : $this->default;
        }

        return $this->null ? null : $this->emptyValue();
    }

    /**
     * The value of a new non-null field without default: '' for text fields.
     */
    protected function emptyValue(): mixed
    {
        return null;
    }

    /**
     * The value to save, called just before the model is saved. Fields like
     * `DateTimeField(autoNow: true)` change the model value here.
     */
    public function preSave(Model $model, bool $adding): mixed
    {
        return $model->getAttribute($this->attname);
    }

    // Validation --------------------------------------------------------------

    /**
     * Convert and validate a value; returns the cleaned value or throws
     * ValidationError. Used by `Model::fullClean()` and (later) forms.
     */
    public function clean(mixed $value, ?Model $model = null): mixed
    {
        $value = $this->toPhp($value);
        $this->validate($value, $model);
        foreach ($this->validators as $validator) {
            $validator($value);
        }

        return $value;
    }

    /**
     * The built-in checks: null, blank and choices. Subclasses add theirs.
     */
    public function validate(mixed $value, ?Model $model = null): void
    {
        if ($value === null && !$this->null) {
            throw new ValidationError('This field cannot be null.', 'null');
        }
        if (self::isEmpty($value)) {
            if (!$this->blank) {
                throw new ValidationError('This field cannot be blank.', 'blank');
            }

            return;
        }
        $choices = $this->choices();
        if ($choices !== null) {
            $raw = $value instanceof \BackedEnum ? $value->value : $value;
            if (!is_int($raw) && !is_string($raw) || !array_key_exists($raw, $choices)) {
                throw new ValidationError(sprintf('Value %s is not a valid choice.', var_export($raw, true)), 'invalid_choice');
            }
        }
    }

    /**
     * The choices as value => label, or null when the field has none.
     *
     * @return array<int|string, string>|null
     */
    public function choices(): ?array
    {
        if ($this->choices === null) {
            return null;
        }
        if (is_array($this->choices)) {
            return $this->choices;
        }
        $choices = [];
        foreach (($this->choices)::cases() as $case) {
            $choices[$case->value] = method_exists($case, 'label') ? (string) $case->label() : ucfirst(strtolower(str_replace('_', ' ', $case->name)));
        }

        return $choices;
    }

    /**
     * The backed enum class used as choices, if any. Values are then
     * enum cases in PHP and their backing values in the database.
     *
     * @return class-string<\BackedEnum>|null
     */
    public function enumClass(): ?string
    {
        return is_string($this->choices) ? $this->choices : null;
    }

    // Migrations --------------------------------------------------------------

    /**
     * The field as its class and the constructor arguments that differ from
     * their defaults; enough to write it back as PHP code (used by migrations).
     *
     * @return array{class: class-string<Field>, options: array<string, mixed>}
     */
    public function deconstruct(): array
    {
        $options = [];
        $seen = [];
        for ($class = new \ReflectionClass($this); $class !== false; $class = $class->getParentClass()) {
            $constructor = $class->getConstructor();
            if ($constructor === null || $constructor->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }
            foreach ($constructor->getParameters() as $parameter) {
                $name = $parameter->getName();
                if ($parameter->isVariadic() || isset($seen[$name]) || !property_exists($this, $name)) {
                    continue;
                }
                $seen[$name] = true;
                $value = $this->{$name};
                if (!$parameter->isDefaultValueAvailable() || $parameter->getDefaultValue() !== $value) {
                    $options[$name] = $value;
                }
            }
        }

        return ['class' => static::class, 'options' => $options];
    }

    protected function attnameFor(string $name): string
    {
        return $name;
    }

    protected static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
