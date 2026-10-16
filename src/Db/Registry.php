<?php

declare(strict_types=1);

namespace BrewPHP\Db;

use BrewPHP\Core\Application;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Support\Str;

/**
 * The model registry: every loaded, non-abstract Model subclass, grouped by
 * app.
 *
 * At boot, BrewPHP loads each installed app's `models.php` and the classes
 * in its `Models/` folder, so every model of the project is known here. A
 * model belongs to the installed app whose namespace contains it:
 * `Apps\Blog\Post` and `Apps\Blog\Models\Post` both belong to `blog`.
 */
final class Registry
{
    /** @var list<class-string<Model>> */
    private static array $models = [];

    private static int $declaredCount = -1;

    /** @var array<class-string<Model>, list<ForeignKey>> */
    private static array $reverse = [];

    /**
     * Forget everything cached about models (their metadata included).
     * Called when another Application becomes the current one, because a
     * model's app label depends on the installed apps.
     *
     * @internal
     */
    public static function clearCache(): void
    {
        self::$models = [];
        self::$declaredCount = -1;
        self::$reverse = [];
        Model::clearMetaCache();
    }

    /**
     * The model classes, optionally only those of one app.
     *
     * @return list<class-string<Model>>
     */
    public static function models(?string $appLabel = null): array
    {
        $declared = get_declared_classes();
        if (count($declared) !== self::$declaredCount) {
            self::$declaredCount = count($declared);
            self::$reverse = [];
            self::$models = [];
            foreach ($declared as $class) {
                if (is_subclass_of($class, Model::class) && !(new \ReflectionClass($class))->isAbstract()) {
                    self::$models[] = $class;
                }
            }
        }
        if ($appLabel === null) {
            return self::$models;
        }

        return array_values(array_filter(self::$models, static fn (string $model): bool => $model::meta()->appLabel === $appLabel));
    }

    /**
     * A model class by label: `get('blog.Post')` (the model name ignores case).
     *
     * @return class-string<Model>
     */
    public static function get(string $label): string
    {
        [$app, $name] = array_pad(explode('.', $label, 2), 2, '');
        foreach (self::models($app) as $model) {
            if (strcasecmp($model::meta()->modelName, $name) === 0) {
                return $model;
            }
        }

        throw new FieldError(sprintf('No model "%s" is loaded. Is the app installed and the model in models.php or Models/?', $label));
    }

    /**
     * Resolve a ForeignKey target: a class name, "self", "app.Model", or
     * "Model" (in the same app as `$from`).
     *
     * @param class-string<Model>|string $from the model that holds the foreign key
     * @return class-string<Model>
     */
    public static function resolve(string $reference, string $from = ''): string
    {
        if ($reference === 'self') {
            if (!is_subclass_of($from, Model::class)) {
                throw new FieldError('A ForeignKey to "self" must belong to a model class.');
            }

            return $from;
        }
        if (class_exists($reference) && is_subclass_of($reference, Model::class)) {
            return $reference;
        }
        if (!str_contains($reference, '.') && is_subclass_of($from, Model::class)) {
            $reference = $from::meta()->appLabel . '.' . $reference;
        }

        return self::get($reference);
    }

    /**
     * The installed app a model class belongs to. Without a matching app,
     * the label comes from the namespace: `Apps\Blog\...` gives "blog",
     * otherwise the last namespace segment is used.
     */
    public static function appLabelFor(string $class): string
    {
        $best = null;
        if (Application::hasInstance()) {
            foreach (Application::getInstance()->apps() as $label => $app) {
                $prefix = $app->namespace . '\\';
                if ($app->namespace !== '' && str_starts_with($class, $prefix) && ($best === null || strlen($prefix) > $best[1])) {
                    $best = [$label, strlen($prefix)];
                }
            }
        }
        if ($best !== null) {
            return (string) $best[0];
        }

        $segments = explode('\\', $class);
        array_pop($segments);
        if (($segments[0] ?? '') === 'Apps' && isset($segments[1])) {
            return Str::snake($segments[1]);
        }

        return $segments === [] ? 'app' : Str::snake((string) end($segments));
    }

    /**
     * Foreign keys of loaded models that point to `$model`.
     *
     * @param class-string<Model> $model
     * @return list<ForeignKey>
     */
    public static function reverseRelations(string $model): array
    {
        $models = self::models();
        if (!isset(self::$reverse[$model])) {
            $relations = [];
            foreach ($models as $candidate) {
                foreach ($candidate::meta()->foreignKeys() as $field) {
                    if ($field->target() === $model) {
                        $relations[] = $field;
                    }
                }
            }
            self::$reverse[$model] = $relations;
        }

        return self::$reverse[$model];
    }
}
