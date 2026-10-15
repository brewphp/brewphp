<?php

declare(strict_types=1);

namespace BrewPHP\Console\Commands;

use BrewPHP\Console\Command;
use BrewPHP\Db\Migrations\Migration;
use BrewPHP\Db\Migrations\MigrationError;
use BrewPHP\Db\Migrations\Migrator;
use BrewPHP\Db\QueryException;

final class MigrateCommand extends Command
{
    public string $name = 'migrate';

    public string $description = 'Apply (or unapply) migrations to the database';

    public string $help = <<<'TEXT'
        Brings the database up to date with the migration files:

          php manage.php migrate                 apply every migration
          php manage.php migrate blog            apply the migrations of blog
          php manage.php migrate blog 0002       move blog forwards or backwards to 0002_...
          php manage.php migrate blog zero       unapply every migration of blog
          php manage.php migrate --plan          show what would run, run nothing
          php manage.php migrate blog 0001 --fake  record (or unrecord) without touching tables
        TEXT;

    protected function configure(): void
    {
        $this->addArgument('app', 'The app to migrate (default: all)');
        $this->addArgument('migration', 'The migration name or prefix to move to, or "zero"');
        $this->addOption('database', 'The connection from DATABASES (default: "default")', value: true);
        $this->addOption('fake', 'Mark migrations as applied or unapplied without running them');
        $this->addOption('plan', 'Show the operations that would run, and stop');
    }

    public function handle(): int
    {
        $app = $this->argument('app');
        $target = $this->argument('migration');
        $database = $this->option('database');
        if (is_string($app) && !$this->app->apps()->has($app)) {
            $this->error(sprintf('No installed app with label "%s".', $app));

            return self::INVALID;
        }
        $migrator = new Migrator($this->app, is_string($database) ? $database : null);

        try {
            $executor = $migrator->executor();
            foreach ($migrator->conflicts() as $label => $leaves) {
                $this->error(sprintf(
                    'Conflicting migrations in app "%s": %s. Run "php manage.php makemigrations --merge" to merge them.',
                    $label,
                    implode(' and ', $leaves),
                ));

                return self::FAILURE;
            }
            $executor->checkConsistentHistory();
            $plan = $executor->plan(is_string($app) ? $app : null, is_string($target) ? $target : null);
        } catch (MigrationError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('plan')) {
            $this->title('Planned operations:');
            if ($plan === []) {
                $this->line('  No planned migration operations.');
            }
            foreach ($plan as [$key, $backwards]) {
                $migration = $executor->graph()->node($key);
                $this->line($migration->key() . ($backwards ? ' (unapply)' : ''));
                $operations = $migration->operations();
                foreach ($backwards ? array_reverse($operations) : $operations as $operation) {
                    $this->line('    ' . ($backwards ? 'Undo ' . lcfirst($operation->describe()) : $operation->describe()));
                }
            }

            return self::SUCCESS;
        }

        $this->title('Operations to perform:');
        $this->line(match (true) {
            !is_string($app) => '  Apply all migrations: ' . (implode(', ', $executor->graph()->apps()) ?: '(none)'),
            !is_string($target) => '  Apply all migrations: ' . $app,
            $target === 'zero' => '  Unapply all migrations: ' . $app,
            default => sprintf('  Target %s', $executor->graph()->find($app, $target)),
        });
        $this->title('Running migrations:');
        if ($plan === []) {
            $this->line('  No migrations to apply.');
        }

        try {
            $executor->migrate($plan, (bool) $this->option('fake'), function (Migration $migration, bool $backwards, bool $done): void {
                if (!$done) {
                    $this->output->write(sprintf('  %s %s...', $backwards ? 'Unapplying' : 'Applying', $migration->key()));

                    return;
                }
                $this->output->writeln($this->output->style($this->option('fake') ? ' FAKED' : ' OK', 'green'));
            });
            $migrator->runMigratedHooks();
        } catch (MigrationError|QueryException $e) {
            $this->line();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->warnAboutChanges($migrator);

        return self::SUCCESS;
    }

    private function warnAboutChanges(Migrator $migrator): void
    {
        try {
            $apps = array_keys($migrator->changes());
        } catch (MigrationError) {
            // Detecting changes needs answers (or has its own errors): makemigrations reports them.
            return;
        }
        if ($apps !== []) {
            $this->warn(sprintf(
                'Your models in app(s) %s have changes that are not yet reflected in a migration. Run "php manage.php makemigrations".',
                implode(', ', $apps),
            ));
        }
    }
}
