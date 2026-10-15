<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations;

/**
 * The migrations of every app and their dependencies.
 *
 * Nodes are keyed "app.name". Within an app, each migration depends on the
 * one before it (written by `makemigrations`); across apps, dependencies
 * say which migration of another app must run first.
 */
final class Graph
{
    /** @var array<string, Migration> */
    private array $nodes = [];

    /** @var array<string, list<string>> key => keys it depends on */
    private array $parents = [];

    /** @var array<string, list<string>> key => keys that depend on it */
    private array $children = [];

    /** @var list<string>|null */
    private ?array $order = null;

    /**
     * @param iterable<Migration> $migrations
     */
    public function __construct(iterable $migrations = [])
    {
        foreach ($migrations as $migration) {
            $this->nodes[$migration->key()] = $migration;
        }
        ksort($this->nodes);
        foreach ($this->nodes as $key => $migration) {
            $this->parents[$key] ??= [];
            $this->children[$key] ??= [];
            foreach ($migration->dependencies as $dependency) {
                if (!isset($this->nodes[$dependency])) {
                    throw new MigrationError(sprintf(
                        'Migration %s depends on %s, which does not exist. Is the app installed and the migration file present?',
                        $key,
                        $dependency,
                    ));
                }
                $this->parents[$key][] = $dependency;
                $this->children[$dependency][] = $key;
            }
        }
        $this->order();
    }

    public function has(string $key): bool
    {
        return isset($this->nodes[$key]);
    }

    public function node(string $key): Migration
    {
        return $this->nodes[$key] ?? throw new MigrationError(sprintf('No migration %s.', $key));
    }

    /**
     * @return array<string, Migration>
     */
    public function nodes(?string $app = null): array
    {
        if ($app === null) {
            return $this->nodes;
        }

        return array_filter($this->nodes, static fn (Migration $m): bool => $m->app === $app);
    }

    /**
     * The labels of apps that have migrations.
     *
     * @return list<string>
     */
    public function apps(): array
    {
        $apps = array_values(array_unique(array_map(static fn (Migration $m): string => $m->app, $this->nodes)));
        sort($apps);

        return $apps;
    }

    /**
     * @return list<string>
     */
    public function dependencies(string $key): array
    {
        return $this->parents[$key] ?? [];
    }

    /**
     * Every migration, ordered so that dependencies come first. The order
     * is stable: ties are broken by key.
     *
     * @return list<string>
     */
    public function order(): array
    {
        if ($this->order !== null) {
            return $this->order;
        }
        $order = [];
        $state = [];
        $visit = function (string $key, array $path) use (&$visit, &$order, &$state): void {
            if (($state[$key] ?? 0) === 2) {
                return;
            }
            if (($state[$key] ?? 0) === 1) {
                throw new MigrationError('Circular dependency between migrations: ' . implode(' -> ', [...$path, $key]) . '.');
            }
            $state[$key] = 1;
            $parents = $this->parents[$key];
            sort($parents);
            foreach ($parents as $parent) {
                $visit($parent, [...$path, $key]);
            }
            $state[$key] = 2;
            $order[] = $key;
        };
        foreach (array_keys($this->nodes) as $key) {
            $visit($key, []);
        }

        return $this->order = $order;
    }

    /**
     * The latest migrations of an app: those no other migration of the
     * same app depends on. More than one means two branches that conflict.
     *
     * @return list<string>
     */
    public function leaves(string $app): array
    {
        $leaves = [];
        foreach ($this->nodes($app) as $key => $migration) {
            $hasChild = false;
            foreach ($this->children[$key] as $child) {
                if ($this->nodes[$child]->app === $app) {
                    $hasChild = true;
                    break;
                }
            }
            if (!$hasChild) {
                $leaves[] = $key;
            }
        }

        return $leaves;
    }

    /**
     * The one latest migration of an app, or null when it has none.
     */
    public function leaf(string $app): ?string
    {
        $leaves = $this->leaves($app);
        if (count($leaves) > 1) {
            throw new MigrationError(sprintf(
                'Conflicting migrations in app "%s": %s. Run "php manage.php makemigrations --merge" to merge them.',
                $app,
                implode(' and ', $leaves),
            ));
        }

        return $leaves[0] ?? null;
    }

    /**
     * A migration and everything it depends on, dependencies first.
     *
     * @return list<string>
     */
    public function ancestors(string $key): array
    {
        $this->node($key);
        $wanted = [$key => true];
        $stack = [$key];
        while ($stack !== []) {
            foreach ($this->parents[array_pop($stack)] as $parent) {
                if (!isset($wanted[$parent])) {
                    $wanted[$parent] = true;
                    $stack[] = $parent;
                }
            }
        }

        return array_values(array_filter($this->order(), static fn (string $k): bool => isset($wanted[$k])));
    }

    /**
     * A migration and everything that depends on it, dependencies first.
     *
     * @return list<string>
     */
    public function descendants(string $key): array
    {
        $this->node($key);
        $wanted = [$key => true];
        $stack = [$key];
        while ($stack !== []) {
            foreach ($this->children[array_pop($stack)] as $child) {
                if (!isset($wanted[$child])) {
                    $wanted[$child] = true;
                    $stack[] = $child;
                }
            }
        }

        return array_values(array_filter($this->order(), static fn (string $k): bool => isset($wanted[$k])));
    }

    /**
     * Find a migration of an app by name or unique name prefix ("0002").
     */
    public function find(string $app, string $prefix): string
    {
        if (isset($this->nodes[$app . '.' . $prefix])) {
            return $app . '.' . $prefix;
        }
        $matches = array_keys(array_filter($this->nodes($app), static fn (Migration $m): bool => str_starts_with($m->name, $prefix)));
        if (count($matches) === 1) {
            return $matches[0];
        }

        throw new MigrationError($matches === []
            ? sprintf('App "%s" has no migration named "%s".', $app, $prefix)
            : sprintf('More than one migration of "%s" starts with "%s": %s.', $app, $prefix, implode(', ', $matches)));
    }

    /**
     * The project state after the given migrations (in dependency order).
     *
     * @param array<string, mixed> $keys keys to include (values ignored); null means all
     */
    public function state(?array $keys = null): ProjectState
    {
        $state = new ProjectState();
        foreach ($this->order() as $key) {
            if ($keys === null || isset($keys[$key])) {
                $this->nodes[$key]->mutateState($state);
            }
        }

        return $state;
    }
}
