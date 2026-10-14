<?php

declare(strict_types=1);

namespace BrewPHP\Db;

use BrewPHP\Core\AltersData;
use BrewPHP\Core\Application;
use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Fields\Field;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Fields\OneToOneField;
use BrewPHP\Support\Str;

/**
 * The base class of every model. A model is a PHP class mapped to a table:
 *
 *     final class Post extends Model
 *     {
 *         public static function fields(): array
 *         {
 *             return [
 *                 'title' => new CharField(maxLength: 200),
 *                 'author' => new ForeignKey(Author::class, relatedName: 'posts'),
 *             ];
 *         }
 *     }
 *
 *     $post = Post::create(['title' => 'Hello', 'author' => $ann]);
 *     $post->title = 'Hello, world';
 *     $post->save();
 *     Post::objects()->filter(title__icontains: 'hello')->orderBy('-id');
 *
 * Field values are read and written as properties (`$post->title`). They
 * live in an internal array, so a model class only declares fields().
 *
 * @property mixed $pk the primary key value, whatever the field is called
 *
 * @phpstan-consistent-constructor
 */
abstract class Model implements \JsonSerializable
{
    /** @var array<class-string<Model>, ModelMeta> */
    private static array $metas = [];

    /** @var array<string, mixed> field values by attribute name (e.g. "author_id") */
    private array $attributes = [];

    /** @var array<string, Model|null> loaded related objects, by field name */
    private array $relatedCache = [];

    /** @var array<string, mixed> values of non-field properties */
    private array $extra = [];

    private bool $persisted = false;

    /**
     * A new, unsaved object. Missing fields get their default value.
     *
     * @param array<string, mixed> $attributes field values by name
     */
    public function __construct(array $attributes = [])
    {
        foreach (static::meta()->fields as $field) {
            if (!array_key_exists($field->name, $attributes) && !array_key_exists($field->attname, $attributes)) {
                $this->attributes[$field->attname] = $field->getDefault();
            }
        }
        foreach ($attributes as $name => $value) {
            $this->__set($name, $value);
        }
    }

    // Model definition --------------------------------------------------------

    /**
     * The fields of the model, by name. An `id` primary key is added
     * automatically unless a field has `primaryKey: true`.
     *
     * @return array<string, Field>
     */
    public static function fields(): array
    {
        return [];
    }

    /**
     * The table name; by default "<app label>_<model name>", e.g. "blog_post".
     */
    public static function table(): string
    {
        return static::appLabel() . '_' . Str::snake(self::shortName(static::class));
    }

    /**
     * The label of the app the model belongs to (see Registry::appLabelFor()).
     */
    public static function appLabel(): string
    {
        return Registry::appLabelFor(static::class);
    }

    /**
     * The default ordering of querysets, e.g. `['-created_at', 'title']`.
     *
     * @return list<string>
     */
    public static function ordering(): array
    {
        return [];
    }

    /**
     * Extra indexes, including multi-column unique ones.
     *
     * @return list<Index>
     */
    public static function indexes(): array
    {
        return [];
    }

    /**
     * A human-readable name, e.g. "blog post" for BlogPost.
     */
    public static function verboseName(): string
    {
        return str_replace('_', ' ', Str::snake(self::shortName(static::class)));
    }

    public static function verboseNamePlural(): string
    {
        return static::verboseName() . 's';
    }

    /**
     * The metadata built from the methods above (cached per class).
     */
    public static function meta(): ModelMeta
    {
        return self::$metas[static::class] ??= new ModelMeta(
            static::class,
            static::table(),
            static::fields(),
            static::appLabel(),
            self::shortName(static::class),
            static::ordering(),
            static::indexes(),
            static::verboseName(),
            static::verboseNamePlural(),
        );
    }

    // Queries -----------------------------------------------------------------

    /**
     * A QuerySet of every object, the starting point of every query
     * (Django's `Post.objects`).
     *
     * @return QuerySet<static>
     */
    public static function objects(): QuerySet
    {
        return new QuerySet(static::class);
    }

    /**
     * Create and save an object in one step.
     *
     * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes = []): static
    {
        $object = new static($attributes);
        $object->save();

        return $object;
    }

    /**
     * The connection models use (the "default" database).
     */
    public static function connection(): Connection
    {
        return Application::getInstance()->db();
    }

    /**
     * Build an object from a database row (values by attribute name),
     * without calling the constructor.
     *
     * @internal used by QuerySet
     * @param array<string, mixed> $row
     */
    public static function fromDb(array $row): static
    {
        $object = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
        foreach (static::meta()->fields as $field) {
            if (array_key_exists($field->attname, $row)) {
                $object->attributes[$field->attname] = $field->fromDb($row[$field->attname]);
            }
        }
        $object->persisted = true;

        return $object;
    }

    // Saving and deleting -----------------------------------------------------

    /**
     * Insert the object (when new) or update its row.
     */
    #[AltersData]
    public function save(): void
    {
        $meta = static::meta();
        $adding = !$this->persisted;
        $this->prepareRelatedForSave();
        $this->beforeSave();

        $values = [];
        foreach ($meta->fields as $field) {
            $value = $field->preSave($this, $adding);
            if ($field->isAutoIncrement() && $value === null) {
                continue;
            }
            $values[$field->column] = $field->toDb($value);
        }

        $connection = static::connection();
        if ($adding) {
            $id = $connection->insert($meta->table, $values, $meta->pk->isAutoIncrement() ? $meta->pk->column : null);
            if ($meta->pk->isAutoIncrement() && $id !== null) {
                $this->attributes[$meta->pk->attname] = $meta->pk->fromDb($id);
            }
        } else {
            unset($values[$meta->pk->column]);
            if ($values !== []) {
                $grammar = $connection->grammar();
                $sets = array_map(static fn (string $column): string => $grammar->quoteIdentifier($column) . ' = ?', array_keys($values));
                $connection->statement(
                    sprintf('UPDATE %s SET %s WHERE %s = ?', $grammar->quoteIdentifier($meta->table), implode(', ', $sets), $grammar->quoteIdentifier($meta->pk->column)),
                    [...array_values($values), $meta->pk->toDb($this->pk)],
                );
            }
        }
        $this->persisted = true;

        $this->afterSave($adding);
    }

    /**
     * Delete the object, applying each foreign key's onDelete rule to
     * the objects that point to it. Returns how many rows were deleted
     * (including cascades).
     */
    #[AltersData]
    public function delete(): int
    {
        if (!$this->persisted || $this->pk === null) {
            throw new \LogicException(sprintf('%s object cannot be deleted because it has not been saved.', self::shortName(static::class)));
        }
        $collector = new Collector(static::connection());
        $collector->collect([$this]);

        return $collector->delete();
    }

    /**
     * Reload the field values from the database.
     */
    #[AltersData]
    public function refresh(): void
    {
        $fresh = static::objects()->get(pk: $this->pk);
        $this->attributes = $fresh->attributes;
        $this->relatedCache = [];
    }

    /**
     * Whether the object was loaded from or saved to the database.
     */
    public function isPersisted(): bool
    {
        return $this->persisted;
    }

    // Hooks -------------------------------------------------------------------

    /** Called before every save. */
    #[AltersData]
    public function beforeSave(): void
    {
    }

    /** Called after every save; `$created` is true for an INSERT. */
    #[AltersData]
    public function afterSave(bool $created): void
    {
    }

    /** Called before the object is deleted (also for cascades and QuerySet::delete()). */
    #[AltersData]
    public function beforeDelete(): void
    {
    }

    /** Called after the object is deleted. */
    #[AltersData]
    public function afterDelete(): void
    {
    }

    // Validation --------------------------------------------------------------

    /**
     * Validate every field, then clean(), then unique constraints. Values
     * are replaced by their cleaned form ("42" becomes 42). Throws a
     * ValidationError with messages per field.
     *
     * @param list<string> $exclude field names to skip
     */
    #[AltersData]
    public function fullClean(array $exclude = [], bool $validateUnique = true): void
    {
        $errors = [];
        foreach (static::meta()->fields as $name => $field) {
            $raw = $this->attributes[$field->attname] ?? null;
            // Like Django, empty values of blank fields are not validated
            // (autoNow dates, for example, are only set when saving).
            if (in_array($name, $exclude, true) || $field->isAutoIncrement() || ($field->blank && in_array($raw, [null, '', []], true))) {
                continue;
            }
            try {
                $this->attributes[$field->attname] = $field->clean($raw, $this);
            } catch (ValidationError $e) {
                $errors[$name] = $e->messages();
            }
        }
        try {
            $this->clean();
        } catch (ValidationError $e) {
            foreach ($e->errors() as $name => $messages) {
                $errors[$name] = array_merge($errors[$name] ?? [], $messages);
            }
        }
        if ($validateUnique) {
            foreach ($this->uniqueErrors(array_merge($exclude, array_keys($errors))) as [$name, $message]) {
                $errors[$name][] = $message;
            }
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }
    }

    /**
     * Validation of the whole object, e.g. comparing two fields. Throw a
     * ValidationError; a message for one field: `new ValidationError(['end' => '...'])`.
     */
    public function clean(): void
    {
    }

    // Values ------------------------------------------------------------------

    /**
     * A raw value by attribute name, without loading related objects.
     */
    public function getAttribute(string $attname): mixed
    {
        return $this->attributes[$attname] ?? null;
    }

    /**
     * Set a raw value by attribute name.
     */
    #[AltersData]
    public function setAttribute(string $attname, mixed $value): void
    {
        $this->attributes[$attname] = $value;
    }

    /**
     * The field values by attribute name (`author_id`, not `author`).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $values = [];
        foreach (static::meta()->fields as $field) {
            $values[$field->attname] = $this->attributes[$field->attname] ?? null;
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_map(static fn (mixed $value): mixed => match (true) {
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof \BackedEnum => $value->value,
            default => $value,
        }, $this->toArray());
    }

    /**
     * Objects that point to this one through a foreign key, by the
     * relation's relatedName (or "<model>_set"): `$author->related('posts')`.
     *
     * @return QuerySet<Model>
     */
    public function related(string $name): QuerySet
    {
        foreach (Registry::reverseRelations(static::class) as $field) {
            if ($field->relatedAccessorName() === $name || $field->relatedQueryName() === $name) {
                $model = $field->model;
                if (!is_subclass_of($model, Model::class)) {
                    break;
                }

                return $model::objects()
                    ->filter(...[$field->name => $this->pk])
                    ->withCreateDefaults([$field->attname => $this->pk]);
            }
        }

        throw new FieldError(sprintf('%s has no reverse relation named "%s".', self::shortName(static::class), $name));
    }

    /**
     * Forget the primary key after the row was deleted, like Django.
     *
     * @internal used by Collector
     */
    #[AltersData]
    public function markDeleted(): void
    {
        $this->attributes[static::meta()->pk->attname] = null;
        $this->persisted = false;
    }

    /**
     * Remember a related object (used by QuerySet::selectRelated()).
     *
     * @internal
     */
    #[AltersData]
    public function setRelatedCache(string $field, ?Model $object): void
    {
        $this->relatedCache[$field] = $object;
    }

    public function __get(string $name): mixed
    {
        $meta = static::meta();
        if ($name === 'pk') {
            return $this->attributes[$meta->pk->attname] ?? null;
        }
        if (isset($meta->fields[$name])) {
            $field = $meta->fields[$name];

            return $field instanceof ForeignKey ? $this->relatedObject($field) : ($this->attributes[$field->attname] ?? null);
        }
        if (array_key_exists($name, $this->attributes)) {
            return $this->attributes[$name];
        }
        if (array_key_exists($name, $this->extra)) {
            return $this->extra[$name];
        }
        foreach (Registry::reverseRelations(static::class) as $field) {
            if ($field->relatedAccessorName() === $name) {
                return $field instanceof OneToOneField ? $this->related($name)->get() : $this->related($name);
            }
        }

        throw new FieldError(sprintf('%s has no field or relation named "%s".', self::shortName(static::class), $name));
    }

    public function __set(string $name, mixed $value): void
    {
        $meta = static::meta();
        if ($name === 'pk') {
            $name = $meta->pk->name;
        }
        $field = $meta->fields[$name] ?? null;
        if ($field instanceof ForeignKey) {
            if ($value instanceof Model) {
                $this->relatedCache[$name] = $value;
                $this->attributes[$field->attname] = $value->pk;
            } else {
                $this->relatedCache[$name] = null;
                $this->attributes[$field->attname] = $value;
                if ($value !== null) {
                    unset($this->relatedCache[$name]);
                }
            }
        } elseif ($field !== null) {
            $this->attributes[$field->attname] = $value;
        } elseif (($field = $meta->fieldByAttname($name)) !== null) {
            if (($this->attributes[$name] ?? null) !== $value) {
                unset($this->relatedCache[$field->name]);
            }
            $this->attributes[$name] = $value;
        } else {
            $this->extra[$name] = $value;
        }
    }

    public function __isset(string $name): bool
    {
        $meta = static::meta();
        if ($name === 'pk') {
            return isset($this->attributes[$meta->pk->attname]);
        }
        if (isset($meta->fields[$name])) {
            return isset($this->attributes[$meta->fields[$name]->attname]);
        }

        return isset($this->attributes[$name]) || isset($this->extra[$name]);
    }

    public function __toString(): string
    {
        return sprintf('%s object (%s)', self::shortName(static::class), $this->pk === null ? 'unsaved' : (is_scalar($this->pk) ? (string) $this->pk : '?'));
    }

    // Internals ---------------------------------------------------------------

    private function relatedObject(ForeignKey $field): ?Model
    {
        if (array_key_exists($field->name, $this->relatedCache)) {
            return $this->relatedCache[$field->name];
        }
        $id = $this->attributes[$field->attname] ?? null;
        $object = $id === null ? null : $field->target()::objects()->get(pk: $id);

        return $this->relatedCache[$field->name] = $object;
    }

    /**
     * Copy the ids of related objects that were saved after being assigned.
     */
    private function prepareRelatedForSave(): void
    {
        foreach (static::meta()->foreignKeys() as $name => $field) {
            $object = $this->relatedCache[$name] ?? null;
            if ($object === null) {
                continue;
            }
            if ($object->pk === null) {
                throw new \LogicException(sprintf('save() prohibited to prevent data loss due to unsaved related object "%s".', $name));
            }
            if (($this->attributes[$field->attname] ?? null) === null) {
                $this->attributes[$field->attname] = $object->pk;
            }
        }
    }

    /**
     * Unique fields and unique indexes whose values are already taken by
     * another row.
     *
     * @param list<string> $exclude
     * @return list<array{0: string, 1: string}> [field name or "__all__", message] pairs
     */
    private function uniqueErrors(array $exclude): array
    {
        $meta = static::meta();
        $checks = [];
        foreach ($meta->fields as $name => $field) {
            if (($field->unique || $field->primaryKey) && !$field->isAutoIncrement() && !in_array($name, $exclude, true)) {
                $checks[] = [$name, [$name]];
            }
        }
        foreach ($meta->indexes as $index) {
            if ($index->unique && array_intersect($index->fields, $exclude) === []) {
                $checks[] = [ValidationError::NON_FIELD, $index->fields];
            }
        }

        $errors = [];
        foreach ($checks as [$key, $names]) {
            $lookup = [];
            foreach ($names as $name) {
                $value = $this->attributes[$meta->field($name)->attname] ?? null;
                if ($value === null) {
                    continue 2;
                }
                $lookup[$name] = $value;
            }
            $query = static::objects()->filter($lookup);
            if ($this->persisted && $this->pk !== null) {
                $query = $query->exclude(pk: $this->pk);
            }
            if ($query->exists()) {
                $labels = array_map(static fn (string $name): string => $meta->field($name)->label(), $names);
                $errors[] = [$key, sprintf('%s with this %s already exists.', ucfirst($meta->verboseName), implode(' and ', $labels))];
            }
        }

        return $errors;
    }

    private static function shortName(string $class): string
    {
        return substr((string) strrchr('\\' . $class, '\\'), 1);
    }
}
