<?php

declare(strict_types=1);

namespace BrewPHP\Core;

use BrewPHP\Support\Str;

/**
 * The registry of installed apps (the `INSTALLED_APPS` setting).
 *
 * An entry can be:
 * - a project app label, e.g. `'blog'` for the folder `apps/blog/`;
 * - a built-in app, e.g. `'brewphp.admin'`;
 * - the class name of an `AppConfig` subclass from a Composer package.
 *
 * Project apps are autoloaded without Composer configuration: the class
 * `Apps\Blog\Models\Post` is loaded from `apps/blog/Models/Post.php`.
 *
 * @implements \IteratorAggregate<string, AppConfig>
 */
final class Apps implements \IteratorAggregate, \Countable
{
    /**
     * Built-in apps, by their dotted name.
     *
     * @var array<string, class-string<AppConfig>>
     */
    private static array $builtin = [
        'brewphp.admin' => \BrewPHP\Admin\AdminApp::class,
        'brewphp.auth' => \BrewPHP\Auth\AuthApp::class,
        'brewphp.sessions' => \BrewPHP\Sessions\SessionsApp::class,
    ];

    /** @var array<string, AppConfig> */
    private array $apps = [];

    /** @var array<string, string> namespace prefix => folder */
    private array $autoload = [];

    private bool $autoloaderRegistered = false;

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * Register every entry of a list (usually `INSTALLED_APPS`).
     *
     * @param list<mixed> $entries
     */
    public function populate(array $entries): void
    {
        foreach ($entries as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new ImproperlyConfigured('Each INSTALLED_APPS entry must be a non-empty string.');
            }
            $this->register($entry);
        }
    }

    public function register(string $entry): AppConfig
    {
        $app = match (true) {
            isset(self::$builtin[$entry]) => $this->fromClass(self::$builtin[$entry]),
            str_contains($entry, '\\') => $this->fromClass($entry),
            default => $this->fromLabel($entry),
        };

        if (isset($this->apps[$app->label])) {
            throw new ImproperlyConfigured(sprintf('App label "%s" is not unique.', $app->label));
        }

        return $this->apps[$app->label] = $app;
    }

    /**
     * Load each app's single-file modules and the classes in its `Models/`
     * folder (so every model is known to the registry), then call every
     * `ready()` hook.
     */
    public function boot(Application $application): void
    {
        foreach ($this->apps as $app) {
            foreach ($app->modules as $module) {
                $file = $app->path . DIRECTORY_SEPARATOR . $module;
                if (is_file($file)) {
                    require_once $file;
                }
            }
            foreach (glob($app->modelsPath() . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
                // Through the autoloader, so a class is never declared twice.
                class_exists($app->namespace . '\\Models\\' . basename($file, '.php'));
            }
        }
        foreach ($this->apps as $app) {
            $app->ready($application);
        }
    }

    public function get(string $label): AppConfig
    {
        return $this->apps[$label] ?? throw new ImproperlyConfigured(sprintf('No installed app with label "%s".', $label));
    }

    public function has(string $label): bool
    {
        return isset($this->apps[$label]);
    }

    /**
     * @return array<string, AppConfig>
     */
    public function all(): array
    {
        return $this->apps;
    }

    /**
     * @return \ArrayIterator<string, AppConfig>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->apps);
    }

    public function count(): int
    {
        return count($this->apps);
    }

    /**
     * Load a project app class (registered with spl_autoload_register).
     */
    public function autoload(string $class): void
    {
        foreach ($this->autoload as $prefix => $folder) {
            if (str_starts_with($class, $prefix)) {
                $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
                $file = $folder . DIRECTORY_SEPARATOR . $relative . '.php';
                if (is_file($file)) {
                    require_once $file;
                }

                return;
            }
        }
    }

    private function fromLabel(string $label): AppConfig
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $label)) {
            throw new ImproperlyConfigured(sprintf(
                'Invalid app "%s": use a lowercase label like "blog" (folder apps/blog/) or an AppConfig class name.',
                $label,
            ));
        }

        $path = $this->basePath . DIRECTORY_SEPARATOR . 'apps' . DIRECTORY_SEPARATOR . $label;
        if (!is_dir($path)) {
            throw new ImproperlyConfigured(sprintf('App "%s" is installed but the folder "%s" does not exist.', $label, $path));
        }

        $namespace = 'Apps\\' . Str::studly($label);
        $this->registerNamespace($namespace, $path);

        $class = $namespace . '\\App';
        $app = class_exists($class) ? new $class() : new AppConfig();
        if (!$app instanceof AppConfig) {
            throw new ImproperlyConfigured(sprintf('%s must extend %s.', $class, AppConfig::class));
        }

        $app->label = $app->label !== '' ? $app->label : $label;
        $app->name = $app->name !== '' ? $app->name : 'apps.' . $label;
        $app->namespace = $namespace;
        $app->path = $path;
        $app->verboseName = $app->verboseName !== '' ? $app->verboseName : ucwords(str_replace('_', ' ', $label));

        return $app;
    }

    private function fromClass(string $class): AppConfig
    {
        if (!class_exists($class) || !is_subclass_of($class, AppConfig::class)) {
            throw new ImproperlyConfigured(sprintf('Installed app "%s" is not an %s class.', $class, AppConfig::class));
        }

        $app = new $class();
        $reflection = new \ReflectionClass($class);
        $app->namespace = $app->namespace !== '' ? $app->namespace : $reflection->getNamespaceName();
        $app->path = $app->path !== '' ? $app->path : dirname((string) $reflection->getFileName());
        $app->label = $app->label !== '' ? $app->label : Str::snake(basename(str_replace('\\', '/', $app->namespace)));
        $app->name = $app->name !== '' ? $app->name : $class;
        $app->verboseName = $app->verboseName !== '' ? $app->verboseName : ucwords(str_replace('_', ' ', $app->label));

        return $app;
    }

    private function registerNamespace(string $namespace, string $path): void
    {
        $this->autoload[$namespace . '\\'] = $path;
        if (!$this->autoloaderRegistered) {
            spl_autoload_register($this->autoload(...));
            $this->autoloaderRegistered = true;
        }
    }
}
