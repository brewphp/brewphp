<?php

declare(strict_types=1);

namespace BrewPHP\Db;

use BrewPHP\Pagination\Page;
use BrewPHP\Pagination\Paginator;

/**
 * A lazy, immutable database query for one model, like Django's QuerySet.
 *
 *     $posts = Post::objects()
 *         ->filter(published: true, author__name: 'Ann')
 *         ->exclude(title__icontains: 'draft')
 *         ->orderBy('-created_at')
 *         ->limit(10);
 *
 *     foreach ($posts as $post) { ... }   // the query runs here, once
 *
 * Every method returns a new QuerySet; nothing touches the database until
 * the results are needed (iteration, count(), get(), first()...). The rows
 * are then cached, so iterating twice runs one query.
 *
 * Lookups use Django's double-underscore syntax as PHP named arguments:
 * `filter(views__gte: 10)`, or an array: `filter(['views__gte' => 10])`.
 *
 * @template TModel of Model
 * @implements \IteratorAggregate<int, TModel>
 */
final class QuerySet implements \IteratorAggregate, \Countable
{
    /** How many rows get() reads at most to report MultipleObjectsReturned. */
    private const MAX_GET_RESULTS = 21;

    private Query $query;

    /** @var list<TModel>|null the evaluated results */
    private ?array $cache = null;

    /** @var array<string, mixed> values create() adds, set by Model::related() */
    private array $createDefaults = [];

    /**
     * @param class-string<TModel> $model
     */
    public function __construct(public readonly string $model)
    {
        $this->query = new Query($model::meta());
    }

    public function __clone()
    {
        $this->query = clone $this->query;
        $this->cache = null;
    }

    // Building ----------------------------------------------------------------

    /**
     * A copy of this QuerySet (Django's `all()`).
     *
     * @return static
     */
    public function all(): static
    {
        return clone $this;
    }

    /**
     * Keep the rows that match every lookup.
     *
     * @return static
     */
    public function filter(mixed ...$lookups): static
    {
        return $this->addCondition(new Q(...$lookups), 'filter');
    }

    /**
     * Remove the rows that match all the lookups.
     *
     * @return static
     */
    public function exclude(mixed ...$lookups): static
    {
        return $this->addCondition((new Q(...$lookups))->negate(), 'exclude');
    }

    /**
     * Keep the rows that match Q conditions: `where(Q::or(new Q(a: 1), new Q(b: 2)))`.
     *
     * @return static
     */
    public function where(Q ...$conditions): static
    {
        return $this->addCondition(Q::and(...$conditions), 'where');
    }

    /**
     * Order by fields; a leading "-" sorts descending. Relations can be
     * followed: `orderBy('author__name', '-created_at')`. Without
     * arguments, any ordering (including the model default) is removed.
     *
     * @return static
     */
    public function orderBy(string ...$fields): static
    {
        $this->assertNotSliced('orderBy');
        $copy = clone $this;
        $copy->query->ordering = array_values($fields);

        return $copy;
    }

    /**
     * Reverse the current ordering.
     *
     * @return static
     */
    public function reverse(): static
    {
        $copy = clone $this;
        $copy->query->ordering = array_map(
            static fn (string $field): string => str_starts_with($field, '-') ? substr($field, 1) : '-' . $field,
            $this->orderingOrPk(),
        );

        return $copy;
    }

    /**
     * @return static
     */
    public function limit(int $limit): static
    {
        $copy = clone $this;
        $copy->query->limit = max(0, $limit);

        return $copy;
    }

    /**
     * @return static
     */
    public function offset(int $offset): static
    {
        $copy = clone $this;
        $copy->query->offset = max(0, $offset);

        return $copy;
    }

    /**
     * Rows `$offset` to `$offset + $limit`, like Python's `qs[offset:offset + limit]`.
     *
     * @return static
     */
    public function slice(int $offset, ?int $limit = null): static
    {
        $copy = $this->offset($offset);
        $copy->query->limit = $limit === null ? null : max(0, $limit);

        return $copy;
    }

    /**
     * Remove duplicate rows (useful after filtering through a reverse relation).
     *
     * @return static
     */
    public function distinct(): static
    {
        $copy = clone $this;
        $copy->query->distinct = true;

        return $copy;
    }

    /**
     * Load related objects in the same query with a JOIN, so that
     * `$post->author` does not run one more query per post.
     *
     * @return static
     */
    public function selectRelated(string ...$fields): static
    {
        $copy = clone $this;
        $copy->query->selectRelated = array_values(array_unique([...$this->query->selectRelated, ...$fields]));

        return $copy;
    }

    /**
     * A QuerySet that is always empty and never queries the database.
     *
     * @return static
     */
    public function none(): static
    {
        $copy = clone $this;
        $copy->query->empty = true;

        return $copy;
    }

    /**
     * Values that create() adds; used by reverse relations so that
     * `$author->related('posts')->create([...])` sets the author.
     *
     * @internal
     * @param array<string, mixed> $values
     * @return static
     */
    public function withCreateDefaults(array $values): static
    {
        $copy = clone $this;
        $copy->createDefaults = $values;

        return $copy;
    }

    // Fetching ----------------------------------------------------------------

    /**
     * Exactly one object matching the lookups.
     *
     * @return TModel
     * @throws ObjectDoesNotExist when nothing matches
     * @throws MultipleObjectsReturned when more than one row matches
     */
    public function get(mixed ...$lookups): Model
    {
        $query = $lookups === [] ? clone $this : $this->filter(...$lookups);
        if (!$query->query->isSliced()) {
            $query->query->ordering = [];
            $query->query->limit = self::MAX_GET_RESULTS;
        }
        $results = $query->toArray();

        return match (count($results)) {
            1 => $results[0],
            0 => throw new ObjectDoesNotExist($this->model),
            default => throw new MultipleObjectsReturned($this->model, count($results)),
        };
    }

    /**
     * The first object (by the ordering, or the primary key), or null.
     *
     * @return TModel|null
     */
    public function first(): ?Model
    {
        $query = clone $this;
        $query->query->ordering = $this->orderingOrPk();
        $query->query->limit = 1;

        return $query->toArray()[0] ?? null;
    }

    /**
     * The last object (by the ordering, or the primary key), or null.
     *
     * @return TModel|null
     */
    public function last(): ?Model
    {
        $query = $this->reverse();
        $query->query->limit = 1;

        return $query->toArray()[0] ?? null;
    }

    /**
     * The results as a list (runs the query once and caches the rows).
     *
     * @return list<TModel>
     */
    public function toArray(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        if ($this->query->empty) {
            return $this->cache = [];
        }

        $model = $this->model;
        $compiler = new Compiler($this->query, $model::connection()->grammar());
        [$sql, $bindings, $related] = $compiler->compileSelect();
        $meta = $this->query->meta;
        $attnames = array_values(array_map(static fn (Fields\Field $field): string => $field->attname, $meta->fields));

        $objects = [];
        foreach ($model::connection()->selectList($sql, $bindings) as $row) {
            $object = $model::fromDb(array_combine($attnames, array_slice($row, 0, count($attnames))));
            $loaded = ['' => $object];
            foreach ($related as $step) {
                $relatedMeta = $step['meta'];
                $values = array_combine(
                    array_values(array_map(static fn (Fields\Field $field): string => $field->attname, $relatedMeta->fields)),
                    array_slice($row, $step['offset'], count($relatedMeta->fields)),
                );
                $relatedModel = $relatedMeta->model;
                // A LEFT JOIN without a match gives NULLs: there is no related object.
                $child = is_subclass_of($relatedModel, Model::class) && $values[$relatedMeta->pk->attname] !== null
                    ? $relatedModel::fromDb($values)
                    : null;
                $path = $step['path'];
                $name = (string) array_pop($path);
                $parent = $loaded[implode('__', $path)] ?? null;
                $parent?->setRelatedCache($name, $child);
                $loaded[implode('__', $step['path'])] = $child;
            }
            $objects[] = $object;
        }

        return $this->cache = $objects;
    }

    /**
     * @return \ArrayIterator<int, TModel>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->toArray());
    }

    /**
     * The number of matching rows. Uses the cached results when the query
     * already ran, otherwise SELECT COUNT(*).
     */
    public function count(): int
    {
        if ($this->cache !== null) {
            return count($this->cache);
        }
        if ($this->query->empty) {
            return 0;
        }
        [$sql, $bindings] = $this->compiler()->compileCount();

        return max(0, (int) $this->model::connection()->scalar($sql, $bindings));
    }

    /**
     * Whether at least one row matches (cheaper than count()).
     */
    public function exists(): bool
    {
        if ($this->cache !== null) {
            return $this->cache !== [];
        }
        if ($this->query->empty) {
            return false;
        }
        if ($this->query->isSliced()) {
            return $this->count() > 0;
        }
        [$sql, $bindings] = $this->compiler()->compileExists();

        return $this->model::connection()->scalar($sql, $bindings) !== null;
    }

    /**
     * Rows as associative arrays instead of objects. Without field names,
     * every field is returned by attribute name (`author_id`).
     * Relations can be followed: `values('title', 'author__name')`.
     *
     * @return list<array<string, mixed>>
     */
    public function values(string ...$fields): array
    {
        if ($this->query->empty) {
            return [];
        }
        [$sql, $bindings, $resolved] = $this->compiler()->compileValues(array_values($fields));
        $keys = array_keys($resolved);
        $rows = [];
        foreach ($this->model::connection()->selectList($sql, $bindings) as $row) {
            $values = [];
            foreach ($keys as $i => $key) {
                $values[$key] = $resolved[$key]->fromDb($row[$i]);
            }
            $rows[] = $values;
        }

        return $rows;
    }

    /**
     * Rows as lists of values: `valuesList('id', 'title')`. With
     * `flat: true` and one field, a plain list: `valuesList('id', flat: true)`.
     *
     * @return list<mixed>
     */
    public function valuesList(string|bool ...$fields): array
    {
        $flat = (bool) ($fields['flat'] ?? false);
        unset($fields['flat']);
        $names = [];
        foreach ($fields as $field) {
            if (!is_string($field)) {
                throw new \InvalidArgumentException('valuesList() takes field names, and the named argument flat.');
            }
            $names[] = $field;
        }
        if ($flat && count($names) !== 1) {
            throw new \InvalidArgumentException('valuesList(flat: true) needs exactly one field.');
        }
        $rows = array_map('array_values', $this->values(...$names));

        return $flat ? array_column($rows, 0) : $rows;
    }

    /**
     * Objects by primary key (or another unique field): `inBulk([1, 2, 3])`.
     * Without ids, every object.
     *
     * @param list<mixed>|null $ids
     * @return array<int|string, TModel>
     */
    public function inBulk(?array $ids = null, string $fieldName = 'pk'): array
    {
        $meta = $this->query->meta;
        $field = $meta->field($fieldName);
        if (!$field->unique && !$field->primaryKey) {
            throw new FieldError(sprintf('inBulk()\'s field "%s" must be unique.', $fieldName));
        }
        if ($ids === []) {
            return [];
        }
        $query = $ids === null ? $this : $this->filter(...[$field->name . '__in' => $ids]);
        $objects = [];
        foreach ($query as $object) {
            $key = $field->toDb($object->getAttribute($field->attname));
            $objects[is_int($key) ? $key : (is_scalar($key) ? (string) $key : '')] = $object;
        }

        return $objects;
    }

    /**
     * Summary values over the matching rows:
     * `aggregate(new Count('id'), total: new Sum('views'))` gives
     * `['id__count' => 4, 'total' => 120]`.
     *
     * @return array<string, mixed>
     */
    public function aggregate(Aggregate ...$aggregates): array
    {
        $named = [];
        foreach ($aggregates as $key => $aggregate) {
            $named[is_string($key) ? $key : $aggregate->defaultAlias()] = $aggregate;
        }
        if ($named === []) {
            return [];
        }
        if ($this->query->empty) {
            return array_map(static fn (Aggregate $aggregate): mixed => $aggregate instanceof Count ? 0 : null, $named);
        }
        [$sql, $bindings, $fields] = $this->compiler()->compileAggregate($named);
        $row = array_values($this->model::connection()->selectOne($sql, $bindings) ?? []);
        $result = [];
        $i = 0;
        foreach ($named as $key => $aggregate) {
            $result[$key] = $aggregate->convert($row[$i++] ?? null, $fields[$key]);
        }

        return $result;
    }

    /**
     * One page of results; `$page` may come straight from the query string.
     * Invalid numbers give the first page, too large ones the last page.
     *
     * @return Page<TModel>
     */
    public function paginate(int $perPage, mixed $page = 1): Page
    {
        return (new Paginator($this, $perPage))->getPage($page);
    }

    /**
     * Objects from your own SQL. Select every column of the table.
     *
     *     Post::objects()->raw('SELECT * FROM blog_post WHERE views > ?', [10]);
     *
     * @param list<mixed> $bindings
     * @return list<TModel>
     */
    public function raw(string $sql, array $bindings = []): array
    {
        $model = $this->model;
        $meta = $this->query->meta;
        $objects = [];
        foreach ($model::connection()->select($sql, $bindings) as $row) {
            $values = [];
            foreach ($meta->fields as $field) {
                if (array_key_exists($field->column, $row)) {
                    $values[$field->attname] = $row[$field->column];
                }
            }
            $objects[] = $model::fromDb($values);
        }

        return $objects;
    }

    /**
     * The SELECT statement and its bindings, for debugging. With field
     * names, the SELECT of values() instead.
     *
     * @param list<string>|null $fields
     * @return array{0: string, 1: list<mixed>}
     */
    public function toSql(?array $fields = null): array
    {
        if ($fields === null) {
            [$sql, $bindings] = $this->compiler()->compileSelect();

            return [$sql, $bindings];
        }
        [$sql, $bindings] = $this->compiler()->compileValues($fields);

        return [$sql, $bindings];
    }

    /**
     * The SELECT of primary keys used by `filter(author__in: $queryset)`.
     *
     * @internal
     * @return array{0: string, 1: list<mixed>}
     */
    public function subquerySql(): array
    {
        [$sql, $bindings] = $this->compiler()->compileValues(['pk'], withOrdering: $this->query->isSliced());

        return [$sql, $bindings];
    }

    // Writing -----------------------------------------------------------------

    /**
     * Create and save a new object.
     *
     * @param array<string, mixed> $attributes
     * @return TModel
     */
    public function create(array $attributes = []): Model
    {
        $model = $this->model;
        $object = new $model($this->createDefaults + $attributes);
        $object->save();

        return $object;
    }

    /**
     * The object matching `$lookup`, created (with `$defaults` too) when
     * missing. Returns [object, created].
     *
     * @param array<string, mixed> $lookup
     * @param array<string, mixed> $defaults
     * @return array{0: TModel, 1: bool}
     */
    public function getOrCreate(array $lookup, array $defaults = []): array
    {
        try {
            return [$this->get($lookup), false];
        } catch (ObjectDoesNotExist) {
        }

        try {
            return $this->model::connection()->transaction(fn (): array => [$this->create(self::creationValues($lookup, $defaults)), true]);
        } catch (IntegrityError $e) {
            // Another request created it in the meantime.
            try {
                return [$this->get($lookup), false];
            } catch (ObjectDoesNotExist) {
                throw $e;
            }
        }
    }

    /**
     * Update the object matching `$lookup` with `$defaults`, or create it.
     * Returns [object, created].
     *
     * @param array<string, mixed> $lookup
     * @param array<string, mixed> $defaults
     * @return array{0: TModel, 1: bool}
     */
    public function updateOrCreate(array $lookup, array $defaults = []): array
    {
        return $this->model::connection()->transaction(function () use ($lookup, $defaults): array {
            try {
                $object = $this->get($lookup);
            } catch (ObjectDoesNotExist) {
                return [$this->create(self::creationValues($lookup, $defaults)), true];
            }
            foreach ($defaults as $name => $value) {
                $object->{$name} = $value;
            }
            $object->save();

            return [$object, false];
        });
    }

    /**
     * Update every matching row with one UPDATE statement and return the
     * number of rows changed. Model hooks and autoNow fields are not used.
     *
     *     Post::objects()->filter(author: $ann)->update(['views' => (new F('views'))->add(1)]);
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values): int
    {
        $this->assertNotSliced('update');
        if ($this->query->empty || $values === []) {
            return 0;
        }
        $query = clone $this->query;
        $query->ordering = [];
        [$sql, $bindings] = (new Compiler($query, $this->model::connection()->grammar()))->compileUpdate($values);

        return $this->model::connection()->statement($sql, $bindings);
    }

    /**
     * Delete every matching object, applying the onDelete rule of foreign
     * keys and calling the model hooks. Returns the number of rows deleted.
     */
    public function delete(): int
    {
        $this->assertNotSliced('delete');
        if ($this->query->empty) {
            return 0;
        }
        $query = (clone $this)->orderBy();
        $query->query->selectRelated = [];
        $objects = $query->toArray();
        if ($objects === []) {
            return 0;
        }
        $collector = new Collector($this->model::connection());
        $collector->collect($objects);

        return $collector->delete();
    }

    // Internals ---------------------------------------------------------------

    /**
     * @return static
     */
    private function addCondition(Q $q, string $method): static
    {
        $this->assertNotSliced($method);
        $copy = clone $this;
        if (!$q->isEmpty()) {
            $copy->query->where[] = $q;
        }

        return $copy;
    }

    private function assertNotSliced(string $method): void
    {
        if ($this->query->isSliced()) {
            throw new \LogicException(sprintf('Cannot call %s() once a limit or offset has been set.', $method));
        }
    }

    /**
     * @return list<string>
     */
    private function orderingOrPk(): array
    {
        return $this->query->effectiveOrdering() ?: ['pk'];
    }

    private function compiler(): Compiler
    {
        return new Compiler($this->query, $this->model::connection()->grammar());
    }

    /**
     * Lookups without "__" become field values of the new object.
     *
     * @param array<string, mixed> $lookup
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private static function creationValues(array $lookup, array $defaults): array
    {
        $values = array_filter($lookup, static fn (string $key): bool => !str_contains($key, '__'), ARRAY_FILTER_USE_KEY);

        return $defaults + $values;
    }
}
