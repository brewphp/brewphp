<?php

declare(strict_types=1);

namespace BrewPHP\Console\Commands;

use BrewPHP\Console\Command;
use BrewPHP\Console\Stub;
use BrewPHP\Support\Str;

final class StartAppCommand extends Command
{
    /** Labels used by BrewPHP's own apps. */
    public const RESERVED = ['admin', 'auth', 'brewphp', 'sessions', 'messages', 'static', 'media'];

    public string $name = 'startapp';

    public string $description = 'Create a new app in apps/<label>/';

    public string $help = <<<'TEXT'
        Creates the folder of a new app with an App.php, models.php,
        views.php, urls.php, a template and an empty migrations/ folder:

          php manage.php startapp blog

        Then add 'blog' to INSTALLED_APPS and include its URLs.
        Each file comes from a stub in the framework's resources/stubs/app/;
        put your own in stubs/app/ (e.g. stubs/app/views.php.stub) to change it.
        TEXT;

    protected function configure(): void
    {
        $this->addArgument('label', 'The app label: lower-case letters, digits and underscores', required: true);
    }

    public function handle(): int
    {
        $label = (string) $this->argument('label');
        if (preg_match('/^[a-z][a-z0-9_]{0,49}$/', $label) !== 1) {
            $this->error(sprintf('"%s" is not a valid app label: start with a letter and use only lower-case letters, digits and underscores (at most 50).', $label));

            return self::INVALID;
        }
        if (in_array($label, self::RESERVED, true) || $this->app->apps()->has($label)) {
            $this->error(sprintf('The label "%s" is already used. Choose another one.', $label));

            return self::INVALID;
        }
        $path = $this->app->basePath('apps', $label);
        if (file_exists($path)) {
            $this->error(sprintf('%s already exists.', $this->relative($path)));

            return self::FAILURE;
        }

        $replacements = [
            'label' => $label,
            'namespace' => 'Apps\\' . Str::studly($label),
            'verbose_name' => ucwords(str_replace('_', ' ', $label)),
        ];
        $files = [
            'App.php' => 'App.php',
            'models.php' => 'models.php',
            'views.php' => 'views.php',
            'urls.php' => 'urls.php',
            'index.html' => 'templates' . DIRECTORY_SEPARATOR . $label . DIRECTORY_SEPARATOR . 'index.html',
        ];
        foreach ($files as $stub => $target) {
            Stub::write($this->app, 'app/' . $stub, $path . DIRECTORY_SEPARATOR . $target, $replacements);
        }
        $migrations = $path . DIRECTORY_SEPARATOR . 'migrations';
        if (!is_dir($migrations)) {
            mkdir($migrations, 0775, true);
        }
        file_put_contents($migrations . DIRECTORY_SEPARATOR . '.gitkeep', '');

        $this->success(sprintf('Created the app "%s" in %s/', $label, $this->relative($path)));
        $this->line();
        $this->line('Next steps:');
        $this->line(sprintf("  1. Add '%s' to INSTALLED_APPS in config/settings.php", $label));
        $this->line(sprintf("  2. Include its URLs in config/urls.php: path('%s/', include_urls('apps/%s/urls.php', namespace: '%s')),", $label, $label, $label));
        $this->line(sprintf('  3. Write models in apps/%s/models.php, then run makemigrations and migrate', $label));

        return self::SUCCESS;
    }
}
