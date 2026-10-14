<?php

declare(strict_types=1);

namespace BrewPHP\Db;

use BrewPHP\Db\Fields\ForeignKey;

/**
 * Deletes objects together with what depends on them, applying the
 * `onDelete` rule of every foreign key that points to them:
 *
 * - Cascade: the pointing objects are collected and deleted too;
 * - Protect / Restrict: the deletion is refused with a ProtectedError;
 * - SetNull / SetDefault: the pointing rows are updated;
 * - DoNothing: nothing happens (the database constraint decides).
 *
 * This runs in PHP rather than with ON DELETE clauses, so it behaves the
 * same on every database and calls the model hooks of every object.
 *
 * @internal used by Model::delete() and QuerySet::delete()
 */
final class Collector
{
    /** @var array<class-string<Model>, array<int|string, Model>> objects to delete, by model, in discovery order */
    private array $objects = [];

    /** @var list<array{field: ForeignKey, value: mixed, ids: list<mixed>}> */
    private array $updates = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Add objects (of one or more models) and everything that cascades from them.
     *
     * @param list<Model> $objects
     */
    public function collect(array $objects): void
    {
        $new = [];
        foreach ($objects as $object) {
            $key = self::key($object->pk);
            if (!isset($this->objects[$object::class][$key])) {
                $this->objects[$object::class][$key] = $object;
                $new[$object::class][] = $object;
            }
        }

        foreach ($new as $model => $added) {
            $ids = array_map(static fn (Model $object): mixed => $object->pk, $added);
            foreach (Registry::reverseRelations($model) as $field) {
                $this->collectRelated($field, $ids);
            }
        }
    }

    /**
     * Delete everything collected in one transaction; returns the number of rows.
     */
    public function delete(): int
    {
        return $this->connection->transaction(function (): int {
            foreach ($this->objects as $objects) {
                foreach ($objects as $object) {
                    $object->beforeDelete();
                }
            }

            $grammar = $this->connection->grammar();
            foreach ($this->updates as $update) {
                $field = $update['field'];
                foreach (array_chunk($update['ids'], 500) as $chunk) {
                    $this->connection->statement(
                        sprintf(
                            'UPDATE %s SET %s = ? WHERE %s IN (%s)',
                            $grammar->quoteIdentifier(self::tableOf($field)),
                            $grammar->quoteIdentifier($field->column),
                            $grammar->quoteIdentifier($field->column),
                            implode(', ', array_fill(0, count($chunk), '?')),
                        ),
                        [$field->toDb($update['value']), ...$chunk],
                    );
                }
            }

            // Objects found last depend on those found first: delete them first.
            $deleted = 0;
            foreach (array_reverse($this->objects, true) as $model => $objects) {
                $meta = $model::meta();
                $ids = array_map(static fn (Model $object): mixed => $meta->pk->toDb($object->pk), array_values($objects));
                foreach (array_chunk($ids, 500) as $chunk) {
                    $deleted += $this->connection->statement(
                        sprintf(
                            'DELETE FROM %s WHERE %s IN (%s)',
                            $grammar->quoteIdentifier($meta->table),
                            $grammar->quoteIdentifier($meta->pk->column),
                            implode(', ', array_fill(0, count($chunk), '?')),
                        ),
                        $chunk,
                    );
                }
            }

            foreach ($this->objects as $model => $objects) {
                foreach ($objects as $object) {
                    $object->markDeleted();
                    $object->afterDelete();
                }
            }

            return $deleted;
        });
    }

    /**
     * @param list<mixed> $ids primary keys of the objects being deleted
     */
    private function collectRelated(ForeignKey $field, array $ids): void
    {
        $model = $field->model;
        if ($field->onDelete === OnDelete::DoNothing || !is_subclass_of($model, Model::class)) {
            return;
        }
        $related = $model::objects()->filter(...[$field->name . '__in' => $ids]);

        switch ($field->onDelete) {
            case OnDelete::Cascade:
                $this->collect($related->orderBy()->toArray());
                break;
            case OnDelete::Protect:
            case OnDelete::Restrict:
                $blocking = array_values(array_filter(
                    $related->toArray(),
                    fn (Model $object): bool => !isset($this->objects[$object::class][self::key($object->pk)]),
                ));
                if ($blocking !== []) {
                    throw new ProtectedError(sprintf(
                        'Cannot delete some %s objects because they are referenced through the protected foreign key %s.%s.',
                        $field->targetMeta()->modelName,
                        $model::meta()->modelName,
                        $field->name,
                    ), $blocking);
                }
                break;
            case OnDelete::SetNull:
                $this->updates[] = ['field' => $field, 'value' => null, 'ids' => $ids];
                break;
            case OnDelete::SetDefault:
                $this->updates[] = ['field' => $field, 'value' => $field->getDefault(), 'ids' => $ids];
                break;
            default:
                break;
        }
    }

    private static function tableOf(ForeignKey $field): string
    {
        return is_subclass_of($field->model, Model::class) ? $field->model::meta()->table : '';
    }

    private static function key(mixed $pk): string
    {
        return is_scalar($pk) ? (string) $pk : '';
    }
}
