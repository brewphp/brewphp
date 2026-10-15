<?php

declare(strict_types=1);

namespace BrewPHP\Console\Commands;

use BrewPHP\Console\Command;
use BrewPHP\Db\Migrations\MigrationError;
use BrewPHP\Db\Migrations\Migrator;
use BrewPHP\Db\Migrations\Questioner;

final class MakeMigrationsCommand extends Command
{
    public string $name = 'makemigrations';

    public string $description = 'Create migrations from changes to your models';

    public string $help = <<<'TEXT'
        Compares your models with the migrations of each app and writes new
        migration files for the differences:

          php manage.php makemigrations            every app
          php manage.php makemigrations blog       only the blog app
          php manage.php makemigrations --check    exit with 1 if a migration is missing (CI)
          php manage.php makemigrations blog --empty --name=fill_slugs
        TEXT;

    protected function configure(): void
    {
        $this->addArgument('app', 'Only these apps', array: true);
        $this->addOption('name', 'Name of the migration (after the number)', value: true);
        $this->addOption('empty', 'Write an empty migration to fill in by hand (needs an app)');
        $this->addOption('dry-run', 'Show what would be written, write nothing');
        $this->addOption('check', 'Write nothing; exit with 1 when models have changes without migrations');
        $this->addOption('merge', 'Write merge migrations for apps with conflicting migrations');
    }

    public function handle(): int
    {
        /** @var list<string> $apps */
        $apps = $this->argument('app');
        foreach ($apps as $label) {
            if (!$this->app->apps()->has($label)) {
                $this->error(sprintf('No installed app with label "%s".', $label));

                return self::INVALID;
            }
        }
        $name = $this->option('name');
        $migrator = new Migrator($this->app);

        try {
            if ($this->option('merge')) {
                $conflicts = $migrator->conflicts();
                if ($apps !== []) {
                    $conflicts = array_intersect_key($conflicts, array_flip($apps));
                }
                if ($conflicts === []) {
                    $this->info('No conflicts detected to merge.');

                    return self::SUCCESS;
                }
                $prepared = [];
                foreach (array_keys($conflicts) as $label) {
                    $prepared[$label] = $migrator->prepareMerge($label);
                }
            } elseif ($this->option('empty')) {
                if ($apps === []) {
                    $this->error('--empty needs the app to write the migration in, e.g. "makemigrations blog --empty".');

                    return self::INVALID;
                }
                $changes = array_fill_keys($apps, ['operations' => [], 'dependsOn' => []]);
                $prepared = $migrator->prepare($changes, is_string($name) ? $name : 'custom');
            } else {
                $questioner = $this->input->isInteractive() && !$this->option('check')
                    ? new Questioner($this->confirm(...), $this->ask(...))
                    : new Questioner();
                $changes = $migrator->changes($apps === [] ? null : $apps, $questioner);
                if ($changes === []) {
                    $this->info($apps === [] ? 'No changes detected.' : sprintf('No changes detected in %s.', implode(', ', $apps)));

                    return self::SUCCESS;
                }
                $prepared = $migrator->prepare($changes, is_string($name) ? $name : null);
            }
        } catch (MigrationError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach (array_keys($prepared) as $label) {
            if (!$migrator->canWrite($label)) {
                $this->error(sprintf(
                    'The models of "%s" differ from its migrations, but its migrations belong to the framework or a package and are not written. Check that your BrewPHP version matches.',
                    $label,
                ));

                return self::FAILURE;
            }
        }

        foreach ($prepared as $label => $migration) {
            $this->line($this->output->style(sprintf('Migrations for "%s":', $label), 'bold'));
            $this->line('  ' . $this->relative($migration['path']));
            foreach ($migration['operations'] as $operation) {
                $this->line('    - ' . $operation->describe());
            }
            if ($this->option('merge')) {
                $this->line('    - Merge ' . implode(', ', $migrator->graph()->leaves($label)));
            }
        }
        if ($this->option('check')) {
            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        foreach ($prepared as $migration) {
            $directory = dirname($migration['path']);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                $this->error(sprintf('Cannot create the folder %s.', $this->relative($directory)));

                return self::FAILURE;
            }
            if (file_exists($migration['path'])) {
                $this->error(sprintf('%s already exists.', $this->relative($migration['path'])));

                return self::FAILURE;
            }
            file_put_contents($migration['path'], $migration['source']);
        }
        $this->comment('Run "php manage.php migrate" to apply them.');

        return self::SUCCESS;
    }
}
