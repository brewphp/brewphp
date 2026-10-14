<?php

declare(strict_types=1);

namespace BrewPHP\Core;

use BrewPHP\Console\Runner;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use BrewPHP\Routing\Router;
use BrewPHP\Templates\PhpEngine;

/**
 * The application: the project's base path, settings, installed apps and
 * the service container, all in one place.
 *
 * `public/index.php` and `manage.php` both start with:
 *
 *     $app = Application::create(dirname(__DIR__));
 */
class Application extends Container
{
    public const VERSION = '0.1.0-dev';

    private static ?self $instance = null;

    private readonly string $basePath;

    private readonly Apps $apps;

    private bool $booted = false;

    final public function __construct(string $basePath, private readonly Settings $settings = new Settings())
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->apps = new Apps($this->basePath);

        $this->instance(self::class, $this);
        $this->instance(Container::class, $this);
        $this->instance(Settings::class, $settings);
        $this->instance(Apps::class, $this->apps);
        $this->singleton(Router::class, fn (): Router => $this->loadRouter());
        $this->singleton(PhpEngine::class, fn (): PhpEngine => new PhpEngine($this->templateDirectories()));
        $this->singleton(SystemChecks::class, fn (): SystemChecks => SystemChecks::withDefaults());

        self::setInstance($this);
    }

    /**
     * Create and boot the application for a project folder: load `.env`,
     * then `config/settings.php`, then the installed apps.
     */
    public static function create(string $basePath): static
    {
        Env::load($basePath . DIRECTORY_SEPARATOR . '.env');
        $settings = Settings::fromFile($basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'settings.php');

        $app = new static($basePath, $settings);
        $app->boot();

        return $app;
    }

    /**
     * The current application (used by the global helper functions).
     */
    public static function getInstance(): self
    {
        return self::$instance ?? throw new \LogicException('No BrewPHP application has been created yet.');
    }

    public static function hasInstance(): bool
    {
        return self::$instance !== null;
    }

    public static function setInstance(?self $app): void
    {
        self::$instance = $app;
    }

    /**
     * Register the installed apps and run their `ready()` hooks.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        date_default_timezone_set($this->settings->string('TIME_ZONE', 'UTC'));
        $this->apps->populate(array_values($this->settings->array('INSTALLED_APPS')));
        $this->apps->boot($this);
    }

    /**
     * An absolute path inside the project, e.g. `basePath('config', 'urls.php')`.
     */
    public function basePath(string ...$parts): string
    {
        return self::join($this->basePath, ...$parts);
    }

    /**
     * A path inside the storage folder (cache, logs, sessions...).
     */
    public function storagePath(string ...$parts): string
    {
        return self::join($this->resolvePath($this->settings->string('STORAGE_PATH', 'storage')), ...$parts);
    }

    /**
     * A path inside the framework's `resources/` folder.
     */
    public function resourcePath(string ...$parts): string
    {
        return self::join(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources', ...$parts);
    }

    /**
     * Make a project-relative path absolute; absolute paths are unchanged.
     */
    public function resolvePath(string $path): string
    {
        return self::isAbsolute($path) ? $path : $this->basePath($path);
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function apps(): Apps
    {
        return $this->apps;
    }

    public function router(): Router
    {
        return $this->get(Router::class);
    }

    public function templates(): PhpEngine
    {
        return $this->get(PhpEngine::class);
    }

    public function isDebug(): bool
    {
        return $this->settings->bool('DEBUG');
    }

    /**
     * Handle one request and return the response, without sending it.
     */
    public function handle(Request $request): Response
    {
        return (new Kernel($this))->handle($request);
    }

    /**
     * Front controller entry point: handle the current request and send it.
     */
    public function run(): void
    {
        ErrorHandler::register($this);
        $this->handle(Request::fromGlobals())->send();
    }

    public function console(): Runner
    {
        return new Runner($this);
    }

    /**
     * `manage.php` entry point.
     *
     * @param list<string> $argv
     */
    public function runConsole(array $argv): int
    {
        ErrorHandler::registerForConsole();

        return $this->console()->run($argv);
    }

    /**
     * Template folders in lookup order: project, apps, framework.
     *
     * @return list<string>
     */
    public function templateDirectories(): array
    {
        $directories = [];
        foreach ($this->settings->array('TEMPLATES.DIRS') as $directory) {
            if (is_string($directory)) {
                $directories[] = $this->resolvePath($directory);
            }
        }
        foreach ($this->apps as $app) {
            $directories[] = $app->templatesPath();
        }
        $directories[] = $this->resourcePath('templates');

        return $directories;
    }

    private function loadRouter(): Router
    {
        $file = $this->resolvePath($this->settings->string('ROOT_URLCONF', 'config/urls.php'));
        if (!is_file($file)) {
            return new Router();
        }

        $patterns = (static fn (): mixed => require $file)();
        if (!is_array($patterns)) {
            throw new ImproperlyConfigured(sprintf('The URLconf "%s" must return an array of routes.', $file));
        }

        return new Router($patterns);
    }

    private static function join(string $base, string ...$parts): string
    {
        $path = $base;
        foreach ($parts as $part) {
            if ($part !== '') {
                $path .= DIRECTORY_SEPARATOR . trim($part, '/\\');
            }
        }

        return $path;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    }
}
