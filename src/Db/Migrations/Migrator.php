<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations;

use BrewPHP\Core\Application;
use BrewPHP\Db\Migrations\Operations\Operation;
use BrewPHP\Db\Registry;

/**
 * Everything the migration commands need, in one place: the migration
 * graph, the state of the current models, new migrations and the
 * executor for one database.
 */
final class Migrator
{
    private ?Graph $graph = null;

    public function __construct(private readonly Application $app, private readonly ?string $database = null)
    {
    }

    public function graph(): Graph
    {
        return $this->graph ??= (new Loader($this->app->apps()))->graph();
    }

    public function executor(): Executor
    {
        $db = $this->app->db($this->database);

        return new Executor($db, $this->graph(), new Recorder($db));
    }

    /**
     * The state of every model of the installed apps, as they are now.
     */
    public function currentState(): ProjectState
    {
        $models = [];
        foreach (array_keys($this->app->apps()->all()) as $label) {
            array_push($models, ...Registry::models($label));
        }

        return ProjectState::fromModels($models);
    }

    /**
     * The changes between the migrations and the models, per app.
     *
     * @param list<string>|null $apps
     * @return array<string, array{operations: list<Operation>, dependsOn: list<string>}>
     */
    public function changes(?array $apps = null, Questioner $questioner = new Questioner()): array
    {
        $graph = $this->graph();
        foreach ($graph->apps() as $label) {
            $graph->leaf($label);
        }

        return (new Autodetector($graph->state(), $this->currentState(), $questioner))->changes($apps);
    }

    /**
     * Turn changes into new migration files (not written yet): one per app,
     * numbered after the app's latest migration and depending on it.
     *
     * @param array<string, array{operations: list<Operation>, dependsOn: list<string>}> $changes
     * @return array<string, array{name: string, path: string, operations: list<Operation>, source: string}>
     */
    public function prepare(array $changes, ?string $name = null): array
    {
        if ($name !== null && preg_match('/^[a-z0-9_]{1,80}$/', $name) !== 1) {
            throw new MigrationError(sprintf('Invalid migration name "%s": use lower-case letters, digits and underscores.', $name));
        }
        $graph = $this->graph();
        $names = [];
        foreach ($changes as $label => $change) {
            $config = $this->app->apps()->get($label);
            $initial = $graph->nodes($label) === [];
            $names[$label] = sprintf('%04d_%s', Loader::nextNumber($config), $name ?? self::suggestName($change['operations'], $initial));
        }

        $prepared = [];
        foreach ($changes as $label => $change) {
            $config = $this->app->apps()->get($label);
            $dependencies = [];
            $leaf = $graph->leaf($label);
            if ($leaf !== null) {
                $dependencies[] = $leaf;
            }
            foreach ($change['dependsOn'] as $other) {
                if (isset($names[$other])) {
                    if (in_array($label, $changes[$other]['dependsOn'], true)) {
                        throw new MigrationError(sprintf(
                            'The new migrations of "%s" and "%s" depend on each other. Create the models of one app first, run makemigrations, then add the foreign keys.',
                            $label,
                            $other,
                        ));
                    }
                    $dependencies[] = $other . '.' . $names[$other];
                    continue;
                }
                $otherLeaf = $graph->leaf($other);
                if ($otherLeaf === null) {
                    throw new MigrationError(sprintf('App "%s" points at models of "%s", which has no migrations. Run makemigrations for "%s" too.', $label, $other, $other));
                }
                $dependencies[] = $otherLeaf;
            }

            $path = $config->migrationsPath() . DIRECTORY_SEPARATOR . $names[$label] . '.php';
            $prepared[$label] = [
                'name' => $names[$label],
                'path' => $path,
                'operations' => $change['operations'],
                'source' => (new Writer())->write($change['operations'], $dependencies, $graph->nodes($label) === []),
            ];
        }

        return $prepared;
    }

    /**
     * Apps with more than one latest migration, and those migrations.
     *
     * @return array<string, list<string>>
     */
    public function conflicts(): array
    {
        $conflicts = [];
        foreach ($this->graph()->apps() as $label) {
            $leaves = $this->graph()->leaves($label);
            if (count($leaves) > 1) {
                $conflicts[$label] = $leaves;
            }
        }

        return $conflicts;
    }

    /**
     * A migration with no operations that depends on every latest
     * migration of an app, which ends a conflict (like Django's merge
     * migrations). Not written yet.
     *
     * @return array{name: string, path: string, operations: list<Operation>, source: string}
     */
    public function prepareMerge(string $label): array
    {
        $leaves = $this->graph()->leaves($label);
        $config = $this->app->apps()->get($label);
        $names = array_map(static fn (string $key): string => explode('.', $key, 2)[1], $leaves);
        $suffix = 'merge_' . implode('_', $names);
        $name = sprintf('%04d_%s', Loader::nextNumber($config), strlen($suffix) > 52 ? 'merge_' . date('Ymd_Hi') : $suffix);

        return [
            'name' => $name,
            'path' => $config->migrationsPath() . DIRECTORY_SEPARATOR . $name . '.php',
            'operations' => [],
            'source' => (new Writer())->write([], $leaves),
        ];
    }

    /**
     * Whether migrations of this app can be written: only apps inside the
     * project, never the framework's or a Composer package's.
     */
    public function canWrite(string $label): bool
    {
        $path = realpath($this->app->apps()->get($label)->path);
        $base = realpath($this->app->basePath());
        $vendor = realpath($this->app->basePath('vendor'));

        return $path !== false && $base !== false
            && str_starts_with($path . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR)
            && ($vendor === false || !str_starts_with($path . DIRECTORY_SEPARATOR, $vendor . DIRECTORY_SEPARATOR));
    }

    /**
     * Call every installed app's migrated() hook.
     */
    public function runMigratedHooks(): void
    {
        foreach ($this->app->apps() as $config) {
            $config->migrated($this->app);
        }
    }

    /**
     * A name for a new migration from its operations, e.g. "post_subtitle".
     *
     * @param list<Operation> $operations
     */
    public static function suggestName(array $operations, bool $initial = false): string
    {
        if ($initial) {
            return 'initial';
        }
        $fragments = [];
        foreach ($operations as $operation) {
            $fragment = $operation->migrationNameFragment();
            if ($fragment === null) {
                $fragments = [];
                break;
            }
            $fragments[] = $fragment;
        }
        $name = strtolower((string) preg_replace('/[^A-Za-z0-9_]+/', '_', implode('_', $fragments)));
        if ($name === '' || strlen($name) > 52) {
            return 'auto_' . date('Ymd_Hi');
        }

        return $name;
    }
}
