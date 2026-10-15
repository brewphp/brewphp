<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations;

use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Model;
use BrewPHP\Db\ModelMeta;

/**
 * Every model of the project as the migrations describe it at one point
 * in history. Operations change it (stateForwards()), and the schema
 * editor works on the ModelMeta objects it builds.
 *
 * Models are keyed by lower-case label: "blog.post".
 */
final class ProjectState
{
    /** @var array<string, ModelState> */
    private array $models = [];

    /** @var array<string, ModelMeta> */
    private array $metas = [];

    /**
     * The state of model classes (usually every model of the installed apps).
     *
     * @param list<class-string<Model>> $models
     */
    public static function fromModels(array $models): self
    {
        $state = new self();
        foreach ($models as $model) {
            $state->add(ModelState::fromMeta($model::meta()));
        }

        return $state;
    }

    public function add(ModelState $model): void
    {
        if (isset($this->models[$model->key()])) {
            throw new MigrationError(sprintf('Model %s already exists in the migration state.', $model->label()));
        }
        $this->models[$model->key()] = $model;
        $this->metas = [];
    }

    public function remove(string $app, string $name): ModelState
    {
        $model = $this->get($app, $name);
        unset($this->models[$model->key()]);
        $this->metas = [];

        return $model;
    }

    public function has(string $app, string $name): bool
    {
        return isset($this->models[strtolower($app . '.' . $name)]);
    }

    /**
     * A model's state, to read or change. Call changed() after changing it.
     */
    public function get(string $app, string $name): ModelState
    {
        return $this->models[strtolower($app . '.' . $name)] ?? throw new MigrationError(sprintf(
            'No model "%s.%s" in the migration state. Is a migration or a dependency missing?',
            $app,
            $name,
        ));
    }

    /**
     * Forget the cached ModelMeta objects after a model state changed.
     */
    public function changed(): void
    {
        $this->metas = [];
    }

    /**
     * @return array<string, ModelState>
     */
    public function models(?string $app = null): array
    {
        if ($app === null) {
            return $this->models;
        }

        return array_filter($this->models, static fn (ModelState $model): bool => $model->app === $app);
    }

    /**
     * The ModelMeta of a model at this point in history, for the schema
     * editor. Its foreign keys point at the target's ModelMeta in this
     * same state, so they use the target table as it was then.
     */
    public function meta(string $app, string $name): ModelMeta
    {
        $key = strtolower($app . '.' . $name);
        if (isset($this->metas[$key])) {
            return $this->metas[$key];
        }
        $model = $this->get($app, $name);
        $fields = [];
        foreach ($model->fields as $fieldName => $field) {
            $fields[$fieldName] = clone $field;
        }
        $meta = new ModelMeta('', $model->table, $fields, $model->app, $model->name, [], $model->indexes);
        // Cache first: a foreign key to itself (or a cycle) binds to this same object.
        $this->metas[$key] = $meta;
        foreach ($meta->foreignKeys() as $field) {
            $target = $this->resolveReference($field->to, $model);
            $field->bindTarget($this->meta($target->app, $target->name));
        }

        return $meta;
    }

    /**
     * The model a ForeignKey target names: "app.Model", "Model" (same app),
     * "self" or a model class.
     */
    public function resolveReference(string $reference, ModelState $from): ModelState
    {
        if ($reference === 'self') {
            return $from;
        }
        if (!str_contains($reference, '.') && class_exists($reference) && is_subclass_of($reference, Model::class)) {
            $meta = $reference::meta();

            return $this->get($meta->appLabel, $meta->modelName);
        }
        [$app, $name] = str_contains($reference, '.') ? explode('.', $reference, 2) : [$from->app, $reference];

        return $this->get($app, $name);
    }

    /**
     * Foreign keys in any model that point to `$app.$name`, as
     * [model state, field name] pairs.
     *
     * @return list<array{ModelState, string}>
     */
    public function referencesTo(string $app, string $name): array
    {
        $key = strtolower($app . '.' . $name);
        $references = [];
        foreach ($this->models as $model) {
            foreach ($model->fields as $fieldName => $field) {
                if ($field instanceof ForeignKey && $this->resolveReference($field->to, $model)->key() === $key) {
                    $references[] = [$model, $fieldName];
                }
            }
        }

        return $references;
    }

    public function __clone()
    {
        foreach ($this->models as $key => $model) {
            $this->models[$key] = clone $model;
        }
        $this->metas = [];
    }
}
