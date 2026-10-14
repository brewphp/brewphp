<?php

declare(strict_types=1);

namespace BrewPHP\Db;

use BrewPHP\Db\Fields\Field;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Grammars\Grammar;

/**
 * Turns a Query into SQL and bindings for one grammar.
 *
 * Field paths such as `author__name` are resolved one part at a time:
 * each foreign key (or reverse relation) adds a JOIN, and the remaining
 * parts are the lookup (`icontains`, `gte`...). Joins are shared between
 * conditions, ordering and selected columns, keyed by their path.
 *
 * A compiler is used for one statement only.
 *
 * @internal
 */
final class Compiler
{
    /** Lookups usable after a field path. */
    public const LOOKUPS = [
        'exact', 'iexact', 'contains', 'icontains', 'startswith', 'istartswith',
        'endswith', 'iendswith', 'gt', 'gte', 'lt', 'lte', 'in', 'range', 'isnull',
    ];

    /** Transforms applied to a date column before the lookup: `created_at__year__gte`. */
    public const TRANSFORMS = ['year', 'month', 'day'];

    private readonly Grammar $grammar;

    /** @var array<string, array{alias: string, sql: string, nullable: bool}> joins by relation path */
    private array $joins = [];

    public function __construct(private readonly Query $query, Grammar $grammar)
    {
        $this->grammar = $grammar;
    }

    /**
     * SELECT of model rows. Also returns, for each selectRelated() path,
     * where its columns start in the row.
     *
     * @return array{0: string, 1: list<mixed>, 2: list<array{path: list<string>, meta: ModelMeta, offset: int}>}
     */
    public function compileSelect(): array
    {
        $meta = $this->query->meta;
        $columns = array_values(array_map(fn (Field $field): string => $this->grammar->quoteColumn($meta->table, $field->column), $meta->fields));
        $related = [];
        foreach ($this->query->selectRelated as $path) {
            foreach ($this->selectRelatedChain($path) as $step) {
                if (isset($related[implode('__', $step['path'])])) {
                    continue;
                }
                $related[implode('__', $step['path'])] = ['path' => $step['path'], 'meta' => $step['meta'], 'offset' => count($columns)];
                foreach ($step['meta']->fields as $field) {
                    $columns[] = $this->grammar->quoteColumn($step['alias'], $field->column);
                }
            }
        }

        [$sql, $bindings] = $this->compileBody(implode(', ', $columns), withOrdering: true);

        return [$sql, $bindings, array_values($related)];
    }

    /**
     * SELECT of some columns, for values() and valuesList().
     *
     * @param list<string> $paths field paths; all fields when empty
     * @return array{0: string, 1: list<mixed>, 2: array<string, Field>}
     */
    public function compileValues(array $paths, bool $withOrdering = true): array
    {
        $fields = [];
        $columns = [];
        if ($paths === []) {
            foreach ($this->query->meta->fields as $field) {
                $fields[$field->attname] = $field;
                $columns[] = $this->grammar->quoteColumn($this->query->meta->table, $field->column);
            }
        }
        foreach ($paths as $path) {
            [$column, $field, $lookups] = $this->resolve($path);
            if ($lookups !== []) {
                throw new FieldError(sprintf('Cannot select "%s": lookups are not allowed here.', $path));
            }
            $fields[$path] = $field;
            $columns[] = $column;
        }
        [$sql, $bindings] = $this->compileBody(implode(', ', $columns), $withOrdering);

        return [$sql, $bindings, $fields];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    public function compileCount(): array
    {
        if ($this->query->isSliced() || $this->query->distinct) {
            // The ordering only matters when a LIMIT picks the rows.
            [$inner, $bindings] = $this->compileValues(['pk'], withOrdering: $this->query->isSliced());

            return ['SELECT COUNT(*) FROM (' . $inner . ') ' . $this->grammar->quoteIdentifier('sub'), $bindings];
        }

        return $this->compileBody('COUNT(*)', withOrdering: false);
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    public function compileExists(): array
    {
        [$sql, $bindings] = $this->compileBody('1', withOrdering: false, withLimit: false);

        return [$sql . $this->grammar->compileLimit(1, 0), $bindings];
    }

    /**
     * @param array<string, Aggregate> $aggregates by result key
     * @return array{0: string, 1: list<mixed>, 2: array<string, Field|null>}
     */
    public function compileAggregate(array $aggregates): array
    {
        $fields = [];
        $expressions = [];
        foreach ($aggregates as $key => $aggregate) {
            if ($aggregate->field === '*') {
                $fields[$key] = null;
                $expressions[$key] = '*';
            } else {
                [$column, $field, $lookups] = $this->resolve($aggregate->field);
                if ($lookups !== []) {
                    throw new FieldError(sprintf('Cannot aggregate "%s": lookups are not allowed here.', $aggregate->field));
                }
                $fields[$key] = $field;
                $expressions[$key] = $column;
            }
        }

        $sliced = $this->query->isSliced() || $this->query->distinct;
        $select = [];
        $inner = [];
        $i = 0;
        foreach ($aggregates as $key => $aggregate) {
            $expression = $expressions[$key];
            if ($sliced && $expression !== '*') {
                $inner[] = $expression . ' AS ' . $this->grammar->quoteIdentifier('c' . $i);
                $expression = $this->grammar->quoteColumn('sub', 'c' . $i);
            }
            $select[] = sprintf(
                '%s(%s%s) AS %s',
                $aggregate->function(),
                $aggregate->distinct ? 'DISTINCT ' : '',
                $expression,
                $this->grammar->quoteIdentifier('a' . $i),
            );
            $i++;
        }

        if ($sliced) {
            // The ordering only matters when a LIMIT picks the rows.
            [$sql, $bindings] = $this->compileBody(implode(', ', $inner ?: ['1 AS ' . $this->grammar->quoteIdentifier('c')]), withOrdering: $this->query->isSliced());

            return ['SELECT ' . implode(', ', $select) . ' FROM (' . $sql . ') ' . $this->grammar->quoteIdentifier('sub'), $bindings, $fields];
        }
        [$sql, $bindings] = $this->compileBody(implode(', ', $select), withOrdering: false);

        return [$sql, $bindings, $fields];
    }

    /**
     * UPDATE of every matching row. Conditions through joins become a
     * subquery on the primary key.
     *
     * @param array<string, mixed> $values field name (or attribute name) => value or F
     * @return array{0: string, 1: list<mixed>}
     */
    public function compileUpdate(array $values): array
    {
        $meta = $this->query->meta;
        $sets = [];
        $setBindings = [];
        foreach ($values as $name => $value) {
            $field = $meta->hasField($name) ? $meta->field($name) : $meta->fieldByAttname($name);
            if ($field === null) {
                throw new FieldError(sprintf('%s has no field named "%s".', $meta->model, $name));
            }
            if ($value instanceof F) {
                [$expression, $bindings] = $this->compileF($value);
                if ($this->joins !== []) {
                    throw new FieldError('Joined field references are not allowed in update().');
                }
            } else {
                [$expression, $bindings] = ['?', [$field->toDb($value)]];
            }
            $sets[] = $this->grammar->quoteIdentifier($field->column) . ' = ' . $expression;
            array_push($setBindings, ...$bindings);
        }

        [$where, $whereBindings] = $this->compileWhere();
        $sql = 'UPDATE ' . $this->grammar->quoteIdentifier($meta->table) . ' SET ' . implode(', ', $sets);
        if ($this->joins === []) {
            return [$sql . ($where !== '' ? ' WHERE ' . $where : ''), [...$setBindings, ...$whereBindings]];
        }

        // MySQL cannot select from the updated table directly, hence the extra derived table.
        $pk = $this->grammar->quoteColumn($meta->table, $meta->pk->column);
        $inner = 'SELECT ' . $pk . ' AS ' . $this->grammar->quoteIdentifier('pk') . $this->compileFrom() . ' WHERE ' . $where;

        return [
            sprintf('%s WHERE %s IN (SELECT %s FROM (%s) %s)', $sql, $this->grammar->quoteIdentifier($meta->pk->column), $this->grammar->quoteIdentifier('pk'), $inner, $this->grammar->quoteIdentifier('sub')),
            [...$setBindings, ...$whereBindings],
        ];
    }

    // Building blocks -----------------------------------------------------------

    /**
     * "SELECT <columns> FROM ... WHERE ... ORDER BY ... LIMIT ...".
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileBody(string $columns, bool $withOrdering, bool $withLimit = true): array
    {
        [$where, $bindings] = $this->compileWhere();
        $order = $withOrdering ? $this->compileOrderBy() : '';

        $sql = 'SELECT ' . ($this->query->distinct ? 'DISTINCT ' : '') . $columns . $this->compileFrom();
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        if ($order !== '') {
            $sql .= ' ORDER BY ' . $order;
        }
        if ($withLimit) {
            $sql .= $this->grammar->compileLimit($this->query->limit, $this->query->offset);
        }

        return [$sql, $bindings];
    }

    private function compileFrom(): string
    {
        $sql = ' FROM ' . $this->grammar->quoteIdentifier($this->query->meta->table);
        foreach ($this->joins as $join) {
            $sql .= ' ' . $join['sql'];
        }

        return $sql;
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileWhere(): array
    {
        $parts = [];
        $bindings = [];
        foreach ($this->query->where as $q) {
            [$sql, $qBindings] = $this->compileQ($q, false);
            if ($sql !== '') {
                // compileQ() already parenthesises anything with AND/OR inside.
                $parts[] = $sql;
                array_push($bindings, ...$qBindings);
            }
        }

        return [implode(' AND ', $parts), $bindings];
    }

    private function compileOrderBy(): string
    {
        $terms = [];
        foreach ($this->query->effectiveOrdering() as $term) {
            $descending = str_starts_with($term, '-');
            [$column, , $lookups] = $this->resolve(ltrim($term, '-'));
            if ($lookups !== []) {
                throw new FieldError(sprintf('Cannot order by "%s".', $term));
            }
            $terms[] = $column . ($descending ? ' DESC' : ' ASC');
        }

        return implode(', ', $terms);
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileQ(Q $q, bool $negated): array
    {
        $negated = $negated !== $q->negated;
        $parts = [];
        $bindings = [];
        foreach ($q->children as $child) {
            [$sql, $childBindings] = $child instanceof Q ? $this->compileQ($child, $negated) : $this->compileLookup($child[0], $child[1], $negated);
            if ($sql !== '') {
                $parts[] = $sql;
                array_push($bindings, ...$childBindings);
            }
        }
        if ($parts === []) {
            return ['', []];
        }
        $sql = count($parts) === 1 ? $parts[0] : '(' . implode(' ' . $q->connector . ' ', $parts) . ')';

        return [$q->negated ? 'NOT (' . $sql . ')' : $sql, $bindings];
    }

    /**
     * One condition, e.g. `title__icontains` => 'php'.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileLookup(string $key, mixed $value, bool $negated): array
    {
        [$column, $field, $lookups, $nullable] = $this->resolve($key);
        $valueField = $field;
        while ($lookups !== [] && in_array($lookups[0], self::TRANSFORMS, true)) {
            $column = $this->grammar->datePart((string) array_shift($lookups), $column);
            $valueField = null;
        }
        $lookup = $lookups[0] ?? 'exact';
        if (count($lookups) > 1 || !in_array($lookup, self::LOOKUPS, true)) {
            throw new FieldError(sprintf('Unsupported lookup "%s" in "%s".', implode('__', $lookups), $key));
        }

        [$sql, $bindings] = match ($lookup) {
            'exact' => $value === null ? [$column . ' IS NULL', []] : $this->comparison($column, '=', $value, $valueField),
            'gt' => $this->comparison($column, '>', $value, $valueField),
            'gte' => $this->comparison($column, '>=', $value, $valueField),
            'lt' => $this->comparison($column, '<', $value, $valueField),
            'lte' => $this->comparison($column, '<=', $value, $valueField),
            'isnull' => [$column . ($value ? ' IS NULL' : ' IS NOT NULL'), []],
            'in' => $this->compileIn($column, $value, $valueField),
            'range' => $this->compileRange($column, $value, $valueField),
            'iexact' => $value === null ? [$column . ' IS NULL', []] : $this->compileLike($column, 'exact', true, $value),
            default => $this->compileLike(
                $column,
                (string) preg_replace('/^i/', '', $lookup),
                str_starts_with($lookup, 'i'),
                $value,
            ),
        };

        // Under NOT, a NULL column would make the whole condition unknown;
        // like Django, rows with NULL are kept by exclude().
        if ($negated && $nullable && $lookup !== 'isnull' && $value !== null) {
            $sql = '(' . $sql . ' AND ' . $column . ' IS NOT NULL)';
        }

        return [$sql, $bindings];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function comparison(string $column, string $operator, mixed $value, ?Field $field): array
    {
        [$expression, $bindings] = $this->valueExpression($value, $field);

        return [$column . ' ' . $operator . ' ' . $expression, $bindings];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileLike(string $column, string $kind, bool $caseInsensitive, mixed $value): array
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            throw new FieldError(sprintf('Text lookups need a string, got %s.', get_debug_type($value)));
        }
        /** @var 'contains'|'startswith'|'endswith'|'exact' $kind */
        [$sql, $binding] = $this->grammar->compileLike($column, $kind, $caseInsensitive, (string) $value);

        return [$sql, [$binding]];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileIn(string $column, mixed $value, ?Field $field): array
    {
        if ($value instanceof QuerySet) {
            [$sql, $bindings] = $value->subquerySql();

            return [$column . ' IN (' . $sql . ')', $bindings];
        }
        if (!is_iterable($value)) {
            throw new FieldError(sprintf('The "in" lookup needs a list or a QuerySet, got %s.', get_debug_type($value)));
        }
        $bindings = [];
        foreach ($value as $item) {
            if ($item !== null) {
                $bindings[] = $field !== null ? $field->toDb($item) : $item;
            }
        }
        if ($bindings === []) {
            // Nothing can match an empty list.
            return ['1 = 0', []];
        }

        return [$column . ' IN (' . implode(', ', array_fill(0, count($bindings), '?')) . ')', $bindings];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileRange(string $column, mixed $value, ?Field $field): array
    {
        if (!is_array($value) || count($value) !== 2) {
            throw new FieldError('The "range" lookup needs an array of two values: [low, high].');
        }
        [$low, $high] = array_values($value);
        [$lowSql, $lowBindings] = $this->valueExpression($low, $field);
        [$highSql, $highBindings] = $this->valueExpression($high, $field);

        return [$column . ' BETWEEN ' . $lowSql . ' AND ' . $highSql, [...$lowBindings, ...$highBindings]];
    }

    /**
     * A placeholder and its converted value, or the SQL of an F expression.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function valueExpression(mixed $value, ?Field $field): array
    {
        if ($value instanceof F) {
            return $this->compileF($value);
        }

        return ['?', [$field !== null ? $field->toDb($value) : $value]];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileF(F $expression): array
    {
        [$sql, , $lookups] = $this->resolve($expression->name);
        if ($lookups !== []) {
            throw new FieldError(sprintf('F("%s") must name a field, not a lookup.', $expression->name));
        }
        $bindings = [];
        foreach ($expression->operations as [$operator, $operand]) {
            [$operandSql, $operandBindings] = $operand instanceof F ? $this->compileF($operand) : ['?', [$operand]];
            $sql = '(' . $sql . ' ' . $operator . ' ' . $operandSql . ')';
            array_push($bindings, ...$operandBindings);
        }

        return [$sql, $bindings];
    }

    // Field paths ---------------------------------------------------------------

    /**
     * Resolve "author__name__icontains" into the quoted column, its field,
     * the remaining lookup parts and whether the column can be NULL (a
     * nullable field or an outer join).
     *
     * @return array{0: string, 1: Field, 2: list<string>, 3: bool}
     */
    private function resolve(string $path): array
    {
        $parts = explode('__', $path);
        $meta = $this->query->meta;
        $alias = $meta->table;
        $joinPath = [];
        $nullable = false;
        $count = count($parts);

        for ($i = 0; $i < $count; $i++) {
            $name = $parts[$i];
            $next = $parts[$i + 1] ?? null;
            $nextIsField = $next !== null && !$this->isLookup($next);

            if ($meta->hasField($name)) {
                $field = $meta->field($name);
                if (!$field instanceof ForeignKey || !$nextIsField) {
                    return [$this->grammar->quoteColumn($alias, $field->column), $field, array_slice($parts, $i + 1), $nullable || $field->null];
                }
                $target = $field->targetMeta();
                // "author__id" is already stored in author_id: no join needed.
                if (($next === 'pk' || $next === $target->pk->name) && ($i + 2 >= $count || $this->isLookup($parts[$i + 2]))) {
                    return [$this->grammar->quoteColumn($alias, $field->column), $field, array_slice($parts, $i + 2), $nullable || $field->null];
                }
                $joinPath[] = $name;
                $nullable = $nullable || $field->null;
                $alias = $this->join(implode('__', $joinPath), $target->table, $target->pk->column, $alias, $field->column, $nullable);
                $meta = $target;

                continue;
            }

            $relation = $this->reverseRelation($meta, $name);
            if ($relation !== null) {
                $relatedMeta = $relation->model !== '' && is_subclass_of($relation->model, Model::class) ? $relation->model::meta() : throw new FieldError('Detached relation.');
                $joinPath[] = $name;
                $nullable = true;
                $alias = $this->join(implode('__', $joinPath), $relatedMeta->table, $relation->column, $alias, $meta->pk->column, true);
                $meta = $relatedMeta;
                if (!$nextIsField) {
                    return [$this->grammar->quoteColumn($alias, $meta->pk->column), $meta->pk, array_slice($parts, $i + 1), true];
                }

                continue;
            }

            $choices = array_keys($meta->fields);
            foreach ($meta->reverseRelations() as $reverse) {
                $choices[] = $reverse->relatedQueryName();
            }
            throw new FieldError(sprintf('Cannot resolve keyword "%s" into a field of %s. Choices are: %s.', $name, $meta->modelName ?: $meta->table, implode(', ', $choices)));
        }

        throw new FieldError(sprintf('Cannot resolve "%s".', $path));
    }

    private function isLookup(string $name): bool
    {
        return in_array($name, self::LOOKUPS, true) || in_array($name, self::TRANSFORMS, true);
    }

    private function reverseRelation(ModelMeta $meta, string $name): ?ForeignKey
    {
        foreach ($meta->reverseRelations() as $field) {
            if ($field->relatedQueryName() === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Add a JOIN once per relation path; returns its alias.
     */
    private function join(string $path, string $table, string $column, string $parentAlias, string $parentColumn, bool $left): string
    {
        if (!isset($this->joins[$path])) {
            $alias = 'T' . (count($this->joins) + 1);
            $this->joins[$path] = [
                'alias' => $alias,
                'nullable' => $left,
                'sql' => sprintf(
                    '%s JOIN %s %s ON %s = %s',
                    $left ? 'LEFT' : 'INNER',
                    $this->grammar->quoteIdentifier($table),
                    $this->grammar->quoteIdentifier($alias),
                    $this->grammar->quoteColumn($alias, $column),
                    $this->grammar->quoteColumn($parentAlias, $parentColumn),
                ),
            ];
        }

        return $this->joins[$path]['alias'];
    }

    /**
     * The joins of a selectRelated() path, one step per foreign key.
     *
     * @return list<array{path: list<string>, meta: ModelMeta, alias: string}>
     */
    private function selectRelatedChain(string $path): array
    {
        $meta = $this->query->meta;
        $alias = $meta->table;
        $steps = [];
        $names = [];
        $nullable = false;
        foreach (explode('__', $path) as $name) {
            $field = $meta->hasField($name) ? $meta->field($name) : null;
            if (!$field instanceof ForeignKey) {
                throw new FieldError(sprintf('selectRelated("%s"): "%s" is not a foreign key of %s.', $path, $name, $meta->modelName));
            }
            $names[] = $name;
            $nullable = $nullable || $field->null;
            $target = $field->targetMeta();
            $alias = $this->join(implode('__', $names), $target->table, $target->pk->column, $alias, $field->column, $nullable);
            $steps[] = ['path' => $names, 'meta' => $target, 'alias' => $alias];
            $meta = $target;
        }

        return $steps;
    }
}
