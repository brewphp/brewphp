<?php

declare(strict_types=1);

namespace BrewPHP\Core;

/**
 * Describes one installed app, like Django's `AppConfig`.
 *
 * A project app lives in `apps/<label>/` and its classes use the
 * `Apps\<Label>` namespace. Put an `App.php` in the folder to customise it:
 *
 *     namespace Apps\Blog;
 *
 *     final class App extends \BrewPHP\Core\AppConfig
 *     {
 *         public string $verboseName = 'Blog';
 *     }
 */
class AppConfig
{
    /** Dotted name, e.g. "apps.blog" or "brewphp.admin". */
    public string $name = '';

    /** Short unique name, e.g. "blog". */
    public string $label = '';

    /** Human-readable name, e.g. "Blog". */
    public string $verboseName = '';

    /** Absolute path of the app folder. */
    public string $path = '';

    /** PHP namespace of the app's classes, e.g. "Apps\Blog". */
    public string $namespace = '';

    /**
     * Single-file modules loaded when the app boots, so classes and
     * functions defined in them are available everywhere.
     *
     * @var list<string>
     */
    public array $modules = ['models.php', 'views.php', 'forms.php'];

    /**
     * Called once every installed app is registered. Override to connect
     * signals or register things.
     */
    public function ready(Application $app): void
    {
    }

    /**
     * Called for every installed app after `migrate` has run successfully
     * (even when there was nothing to apply), like Django's `post_migrate`
     * signal. Override it to create rows the app needs, such as default
     * permissions; the tables of every applied migration exist by then.
     */
    public function migrated(Application $app): void
    {
    }

    public function templatesPath(): string
    {
        return $this->path . DIRECTORY_SEPARATOR . 'templates';
    }

    /**
     * The folder of one-class-per-file models (`Models/Post.php` holds
     * `Apps\Blog\Models\Post`). Its classes are loaded at boot, like `models.php`.
     */
    public function modelsPath(): string
    {
        return $this->path . DIRECTORY_SEPARATOR . 'Models';
    }

    public function commandsPath(): string
    {
        return $this->path . DIRECTORY_SEPARATOR . 'Commands';
    }

    /**
     * The folder of the app's migration files (`migrations/0001_initial.php`...).
     * Built-in apps keep theirs inside the framework, next to their AppConfig.
     */
    public function migrationsPath(): string
    {
        return $this->path . DIRECTORY_SEPARATOR . 'migrations';
    }
}
