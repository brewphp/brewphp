<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations;

use BrewPHP\Db\Fields\Field;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Index;
use BrewPHP\Db\Migrations\Operations\AddField;
use BrewPHP\Db\Migrations\Operations\AddIndex;
use BrewPHP\Db\Migrations\Operations\AlterField;
use BrewPHP\Db\Migrations\Operations\AlterModelTable;
use BrewPHP\Db\Migrations\Operations\CreateModel;
use BrewPHP\Db\Migrations\Operations\DeleteModel;
use BrewPHP\Db\Migrations\Operations\Operation;
use BrewPHP\Db\Migrations\Operations\RemoveField;
use BrewPHP\Db\Migrations\Operations\RemoveIndex;
use BrewPHP\Db\Migrations\Operations\RenameField;
use BrewPHP\Db\Migrations\Operations\RenameModel;

/**
 * Compares the state the migrations describe with the current models and
 * returns the operations that turn one into the other, per app. This is
 * the brain of `makemigrations`.
 */
final class Autodetector
{
    /** Operation groups, in the order they are written. */
    private const GROUPS = ['renameModel', 'alterTable', 'createModel', 'removeIndex', 'renameField', 'addField', 'alterField', 'removeField', 'addIndex', 'deleteModel'];

    /** @var array<string, array<string, list<Operation>>> app => group => operations */
    private array $operations = [];

    private ProjectState $from;

    public function __construct(ProjectState $from, private readonly ProjectState $to, private readonly Questioner $questioner = new Questioner())
    {
        $this->from = clone $from;
    }

    /**
     * The changes of the given apps (all apps when null): app label =>
     * operations, and the other apps each app's new migration needs.
     *
     * @param list<string>|null $apps
     * @return array<string, array{operations: list<Operation>, dependsOn: list<string>}>
     */
    public function changes(?array $apps = null): array
    {
        $this->operations = [];
        $labels = $apps ?? array_values(array_unique(array_merge(
            array_map(static fn (ModelState $m): string => $m->app, array_values($this->from->models())),
            array_map(static fn (ModelState $m): string => $m->app, array_values($this->to->models())),
        )));
        sort($labels);

        foreach ($labels as $app) {
            $this->detectRenamedModels($app);
        }
        foreach ($labels as $app) {
            $old = $this->from->models($app);
            $new = $this->to->models($app);
            $this->createModels($app, array_values(array_diff_key($new, $old)));
            $this->deleteModels($app, array_values(array_diff_key($old, $new)));
            foreach (array_keys(array_intersect_key($new, $old)) as $key) {
                $this->compareModel($app, $old[$key], $new[$key]);
            }
        }

        $changes = [];
        foreach ($this->operations as $app => $groups) {
            $operations = [];
            foreach (self::GROUPS as $group) {
                array_push($operations, ...($groups[$group] ?? []));
            }
            if ($operations !== []) {
                $changes[$app] = ['operations' => $operations, 'dependsOn' => $this->dependencies($app, $operations)];
            }
        }
        ksort($changes);

        return $changes;
    }

    private function add(string $app, string $group, Operation $operation): void
    {
        $this->operations[$app][$group][] = $operation;
    }

    private function detectRenamedModels(string $app): void
    {
        $old = $this->from->models($app);
        $new = $this->to->models($app);
        $added = array_diff_key($new, $old);
        foreach (array_diff_key($old, $new) as $removed) {
            foreach ($added as $key => $candidate) {
                if (self::fieldSignatures($removed) === self::fieldSignatures($candidate)
                    && $this->questioner->askRenameModel($app, $removed->name, $candidate->name)) {
                    $operation = new RenameModel($removed->name, $candidate->name);
                    $operation->stateForwards($app, $this->from);
                    $this->add($app, 'renameModel', $operation);
                    unset($added[$key]);
                    break;
                }
            }
        }
    }

    /**
     * @param list<ModelState> $models
     */
    private function createModels(string $app, array $models): void
    {
        // Create targets before the models that point at them; a cycle is
        // broken by adding some foreign keys after every table exists.
        $pending = [];
        foreach ($models as $model) {
            $pending[$model->key()] = $model;
        }
        while ($pending !== []) {
            $next = null;
            foreach ($pending as $key => $model) {
                if ($this->pendingTargets($model, $pending) === []) {
                    $next = $key;
                    break;
                }
            }
            $model = $pending[$next ?? array_key_first($pending)];
            unset($pending[$model->key()]);

            $deferred = $this->pendingTargets($model, $pending);
            $fields = array_diff_key($model->fields, array_flip($deferred));
            $indexes = [];
            $laterIndexes = [];
            foreach ($model->indexes as $index) {
                if (array_intersect($index->fields, $deferred) === []) {
                    $indexes[] = $index;
                } else {
                    $laterIndexes[] = $index;
                }
            }
            $this->add($app, 'createModel', new CreateModel($model->name, $fields, $model->table, $indexes));
            foreach ($deferred as $name) {
                $this->add($app, 'addField', new AddField($model->name, $name, $model->fields[$name]));
            }
            foreach ($laterIndexes as $index) {
                $this->add($app, 'addIndex', new AddIndex($model->name, $index));
            }
        }
    }

    /**
     * Foreign key fields of `$model` whose target is still waiting to be created.
     *
     * @param array<string, ModelState> $pending
     * @return list<string>
     */
    private function pendingTargets(ModelState $model, array $pending): array
    {
        $names = [];
        foreach ($model->fields as $name => $field) {
            if ($field instanceof ForeignKey) {
                $target = $this->to->resolveReference($field->to, $model)->key();
                if ($target !== $model->key() && isset($pending[$target])) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * @param list<ModelState> $models
     */
    private function deleteModels(string $app, array $models): void
    {
        // A model that points at another deleted model goes first.
        $pending = [];
        foreach ($models as $model) {
            $pending[$model->key()] = $model;
        }
        while ($pending !== []) {
            $next = array_key_first($pending);
            foreach ($pending as $key => $candidate) {
                $referenced = false;
                foreach ($this->from->referencesTo($candidate->app, $candidate->name) as [$referencing]) {
                    if ($referencing->key() !== $key && isset($pending[$referencing->key()])) {
                        $referenced = true;
                    }
                }
                if (!$referenced) {
                    $next = $key;
                    break;
                }
            }
            $this->add($app, 'deleteModel', new DeleteModel($pending[$next]->name));
            unset($pending[$next]);
        }
    }

    private function compareModel(string $app, ModelState $old, ModelState $new): void
    {
        $name = $new->name;
        if ($old->table !== $new->table) {
            $this->add($app, 'alterTable', new AlterModelTable($name, $new->table));
        }

        $removed = array_diff_key($old->fields, $new->fields);
        $added = array_diff_key($new->fields, $old->fields);
        foreach ($added as $newName => $field) {
            foreach ($removed as $oldName => $oldField) {
                if (Fields::signature($oldField) === Fields::signature($field) && $this->questioner->askRenameField($name, $oldName, $newName)) {
                    $operation = new RenameField($name, $oldName, $newName);
                    $operation->stateForwards($app, $this->from);
                    $this->add($app, 'renameField', $operation);
                    unset($removed[$oldName], $added[$newName]);
                    break;
                }
            }
        }
        // Renames changed the "from" model (indexes follow renamed fields).
        $old = $this->from->get($app, $old->name);

        foreach ($added as $fieldName => $field) {
            if (!$field->null && !$field->hasDefault() && !$field->isAutoIncrement()) {
                $value = $this->questioner->askNotNullAddition($name, $fieldName);
                $this->add($app, 'addField', new AddField($name, $fieldName, Fields::with($field, ['default' => $value]), preserveDefault: false));
            } else {
                $this->add($app, 'addField', new AddField($name, $fieldName, $field));
            }
        }
        foreach (array_keys($removed) as $fieldName) {
            $this->add($app, 'removeField', new RemoveField($name, $fieldName));
        }
        foreach (array_intersect_key($new->fields, $old->fields) as $fieldName => $field) {
            $oldField = $old->fields[$fieldName];
            if (Fields::signature($oldField) === Fields::signature($field)) {
                continue;
            }
            if ($oldField->null && !$field->null && !$field->hasDefault()) {
                $value = $this->questioner->askNotNullAlteration($name, $fieldName);
                if ($value !== null) {
                    $this->add($app, 'alterField', new AlterField($name, $fieldName, Fields::with($field, ['default' => $value]), preserveDefault: false));
                    continue;
                }
            }
            $this->add($app, 'alterField', new AlterField($name, $fieldName, $field));
        }

        foreach ($old->indexes as $index) {
            if (!self::containsIndex($new->indexes, $index)) {
                $this->add($app, 'removeIndex', new RemoveIndex($name, $index));
            }
        }
        foreach ($new->indexes as $index) {
            if (!self::containsIndex($old->indexes, $index)) {
                $this->add($app, 'addIndex', new AddIndex($name, $index));
            }
        }
    }

    /**
     * Other apps whose models the operations point at.
     *
     * @param list<Operation> $operations
     * @return list<string>
     */
    private function dependencies(string $app, array $operations): array
    {
        $apps = [];
        foreach ($operations as $operation) {
            $fields = match (true) {
                $operation instanceof CreateModel => $operation->fields,
                $operation instanceof AddField, $operation instanceof AlterField => [$operation->field],
                default => [],
            };
            foreach ($fields as $field) {
                if ($field instanceof ForeignKey && str_contains($field->to, '.')) {
                    $target = explode('.', $field->to, 2)[0];
                    if ($target !== $app) {
                        $apps[$target] = true;
                    }
                }
            }
        }
        $apps = array_keys($apps);
        sort($apps);

        return $apps;
    }

    /**
     * @return array<string, string>
     */
    private static function fieldSignatures(ModelState $model): array
    {
        $signatures = array_map(static fn (Field $field): string => Fields::signature($field), $model->fields);
        ksort($signatures);

        return $signatures;
    }

    /**
     * @param list<Index> $indexes
     */
    private static function containsIndex(array $indexes, Index $index): bool
    {
        foreach ($indexes as $candidate) {
            if (RemoveIndex::same($candidate, $index)) {
                return true;
            }
        }

        return false;
    }
}
