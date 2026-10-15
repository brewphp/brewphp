<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations;

use BrewPHP\Db\Connection;
use BrewPHP\Db\IntegrityError;

/**
 * Plans and runs migrations: `migrate` is a thin layer over this class.
 *
 *     $executor = new Executor($db, $loader->graph(), new Recorder($db));
 *     $plan = $executor->plan();                   // everything not yet applied
 *     $plan = $executor->plan('blog', '0001');     // forwards or backwards to blog.0001_...
 *     $plan = $executor->plan('blog', 'zero');     // unapply every blog migration
 *     $executor->migrate($plan);
 */
final class Executor
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Graph $graph,
        private readonly Recorder $recorder,
    ) {
    }

    public function graph(): Graph
    {
        return $this->graph;
    }

    public function recorder(): Recorder
    {
        return $this->recorder;
    }

    /**
     * The steps to reach a target, as [migration key, backwards] pairs.
     *
     * - no app: apply every migration of every app;
     * - an app: apply every migration of that app (and what it depends on);
     * - an app and a name (or name prefix): move forwards or backwards so
     *   that this migration is the latest one applied;
     * - an app and "zero": unapply every migration of the app.
     *
     * @return list<array{0: string, 1: bool}>
     */
    public function plan(?string $app = null, ?string $name = null): array
    {
        $applied = $this->recorder->applied();
        if ($app !== null && $this->graph->nodes($app) === [] && $name !== 'zero') {
            throw new MigrationError(sprintf('App "%s" has no migrations.', $app));
        }

        if ($app === null || $name === null) {
            $targets = [];
            foreach ($app === null ? $this->graph->apps() : [$app] as $label) {
                $leaf = $this->graph->leaf($label);
                if ($leaf !== null) {
                    $targets = [...$targets, ...$this->graph->ancestors($leaf)];
                }
            }

            return $this->forwards($targets, $applied);
        }

        if ($name === 'zero') {
            $undo = [];
            foreach (array_keys($this->graph->nodes($app)) as $key) {
                if (isset($applied[$key])) {
                    $undo = [...$undo, ...$this->graph->descendants($key)];
                }
            }

            return $this->backwards($undo, $applied);
        }

        $target = $this->graph->find($app, $name);
        if (!isset($applied[$target])) {
            return $this->forwards($this->graph->ancestors($target), $applied);
        }
        // Unapply what comes after the target in its app, and what depends on that.
        $undo = [];
        foreach ($this->graph->descendants($target) as $key) {
            if ($key !== $target && $this->graph->node($key)->app === $app) {
                $undo = [...$undo, ...$this->graph->descendants($key)];
            }
        }

        return $this->backwards($undo, $applied);
    }

    /**
     * Run a plan. `$progress` is called before (`$done` false) and after
     * (`$done` true) each migration.
     *
     * @param list<array{0: string, 1: bool}> $plan
     * @param (callable(Migration $migration, bool $backwards, bool $done): void)|null $progress
     */
    public function migrate(array $plan, bool $fake = false, ?callable $progress = null): void
    {
        if ($plan === []) {
            return;
        }
        $this->checkConsistentHistory();
        $this->recorder->ensureTable();
        foreach ($plan as [$key, $backwards]) {
            $migration = $this->graph->node($key);
            if ($progress !== null) {
                $progress($migration, $backwards, false);
            }
            $applied = $this->recorder->applied();
            if ($backwards) {
                $this->unapply($migration, $this->stateBefore($key, $applied), $fake);
            } else {
                $this->apply($migration, $this->stateBefore($key, $applied), $fake);
            }
            if ($progress !== null) {
                $progress($migration, $backwards, true);
            }
        }
    }

    /**
     * The SQL a migration runs, without running it (`sqlmigrate`). Each
     * operation is preceded by a "-- description" comment.
     *
     * @return list<string>
     */
    public function collectSql(string $key, bool $backwards = false): array
    {
        $migration = $this->graph->node($key);
        $before = $this->graph->state(array_flip(array_diff($this->graph->ancestors($key), [$key])));
        $editor = $this->connection->schemaEditor(collectOnly: true);
        $states = $this->operationStates($migration, $before);
        $sql = [];
        $operations = $migration->operations();
        $indexes = $backwards ? array_reverse(array_keys($operations)) : array_keys($operations);
        foreach ($indexes as $i) {
            $operation = $operations[$i];
            $sql[] = '-- ' . $operation->describe();
            $count = count($editor->collectedSql());
            if ($backwards) {
                if (!$operation->reversible()) {
                    throw new MigrationError(sprintf('Migration %s cannot be unapplied: "%s" is not reversible.', $key, $operation->describe()));
                }
                $operation->databaseBackwards($migration->app, $editor, $states[$i + 1], $states[$i]);
            } else {
                $operation->databaseForwards($migration->app, $editor, $states[$i], $states[$i + 1]);
            }
            array_push($sql, ...array_slice($editor->collectedSql(), $count));
        }

        return $sql;
    }

    /**
     * Fail when an applied migration has a dependency that is not applied
     * (for example after migration files were edited by hand).
     */
    public function checkConsistentHistory(): void
    {
        $applied = $this->recorder->applied();
        foreach (array_keys($applied) as $key) {
            if (!$this->graph->has($key)) {
                continue;
            }
            foreach ($this->graph->dependencies($key) as $dependency) {
                if (!isset($applied[$dependency])) {
                    throw new MigrationError(sprintf(
                        'Inconsistent migration history: %s is applied before its dependency %s.',
                        $key,
                        $dependency,
                    ));
                }
            }
        }
    }

    /**
     * @param list<string> $keys
     * @param array<string, true> $applied
     * @return list<array{0: string, 1: bool}>
     */
    private function forwards(array $keys, array $applied): array
    {
        $wanted = array_flip($keys);
        $plan = [];
        foreach ($this->graph->order() as $key) {
            if (isset($wanted[$key]) && !isset($applied[$key])) {
                $plan[] = [$key, false];
            }
        }

        return $plan;
    }

    /**
     * @param list<string> $keys
     * @param array<string, true> $applied
     * @return list<array{0: string, 1: bool}>
     */
    private function backwards(array $keys, array $applied): array
    {
        $wanted = array_flip($keys);
        $plan = [];
        foreach (array_reverse($this->graph->order()) as $key) {
            if (isset($wanted[$key]) && isset($applied[$key])) {
                $plan[] = [$key, true];
            }
        }

        return $plan;
    }

    /**
     * The state of the database just before `$key` is applied (or after it
     * is unapplied): every other applied migration that comes before it.
     *
     * @param array<string, true> $applied
     */
    private function stateBefore(string $key, array $applied): ProjectState
    {
        $keys = [];
        foreach ($this->graph->order() as $candidate) {
            if ($candidate === $key) {
                break;
            }
            if (isset($applied[$candidate])) {
                $keys[$candidate] = true;
            }
        }
        // Dependencies come first in the order, so they are all included.
        return $this->graph->state($keys);
    }

    private function apply(Migration $migration, ProjectState $before, bool $fake): void
    {
        if ($fake) {
            $this->recorder->record($migration->app, $migration->name);

            return;
        }
        $this->atomically($migration, function () use ($migration, $before): void {
            $editor = $this->connection->schemaEditor();
            $states = $this->operationStates($migration, $before);
            foreach ($migration->operations() as $i => $operation) {
                $operation->databaseForwards($migration->app, $editor, $states[$i], $states[$i + 1]);
            }
            $this->recorder->record($migration->app, $migration->name);
        });
    }

    private function unapply(Migration $migration, ProjectState $before, bool $fake): void
    {
        $operations = $migration->operations();
        foreach ($operations as $operation) {
            if (!$operation->reversible()) {
                throw new MigrationError(sprintf('Migration %s cannot be unapplied: "%s" is not reversible.', $migration->key(), $operation->describe()));
            }
        }
        if ($fake) {
            $this->recorder->unrecord($migration->app, $migration->name);

            return;
        }
        $this->atomically($migration, function () use ($migration, $before, $operations): void {
            $editor = $this->connection->schemaEditor();
            $states = $this->operationStates($migration, $before);
            for ($i = count($operations) - 1; $i >= 0; $i--) {
                $operations[$i]->databaseBackwards($migration->app, $editor, $states[$i + 1], $states[$i]);
            }
            $this->recorder->unrecord($migration->app, $migration->name);
        });
    }

    /**
     * The state before each operation of a migration, and after the last.
     *
     * @return list<ProjectState>
     */
    private function operationStates(Migration $migration, ProjectState $before): array
    {
        $states = [$before];
        $state = $before;
        foreach ($migration->operations() as $operation) {
            $state = clone $state;
            $operation->stateForwards($migration->app, $state);
            $states[] = $state;
        }

        return $states;
    }

    /**
     * Run a migration in a transaction when the database can roll back
     * schema changes. SQLite needs foreign key checks off during the
     * transaction (table rebuilds); they are verified before committing.
     */
    private function atomically(Migration $migration, \Closure $work): void
    {
        $db = $this->connection;
        if (!$migration->atomic || !$db->grammar()->supportsDdlTransactions()) {
            $work();

            return;
        }
        $sqlite = $db->engine() === 'sqlite';
        $checksOff = false;
        if ($sqlite && !$db->inTransaction() && (int) $db->scalar('PRAGMA foreign_keys') === 1) {
            $db->statement('PRAGMA foreign_keys = OFF');
            $checksOff = true;
        }
        try {
            $db->transaction(function () use ($db, $work, $sqlite, $migration): void {
                $work();
                if ($sqlite && $db->select('PRAGMA foreign_key_check') !== []) {
                    throw new IntegrityError('PRAGMA foreign_key_check', [], new \PDOException(sprintf(
                        'Migration %s left rows that break foreign key constraints.',
                        $migration->key(),
                    ), 23000));
                }
            });
        } finally {
            if ($checksOff) {
                $db->statement('PRAGMA foreign_keys = ON');
            }
        }
    }
}
