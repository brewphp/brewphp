<?php

declare(strict_types=1);

namespace BrewPHP\Admin;

use BrewPHP\Auth\AuthenticationMiddleware;
use BrewPHP\Auth\Models\Group;
use BrewPHP\Auth\Models\User;
use BrewPHP\Core\AppConfig;
use BrewPHP\Core\Application;
use BrewPHP\Core\CheckMessage;
use BrewPHP\Core\SystemChecks;
use BrewPHP\Security\CsrfViewMiddleware;
use BrewPHP\Sessions\SessionMiddleware;

/**
 * The built-in `brewphp.admin` app: the automatic admin.
 *
 * At boot it loads the `admin.php` file of every installed app and
 * registers the ModelAdmin classes marked with #[Register], plus the user
 * and group admins of `brewphp.auth`.
 */
final class AdminApp extends AppConfig
{
    public string $name = 'brewphp.admin';

    public string $label = 'admin';

    public string $verboseName = 'Administration';

    /** @var list<string> */
    public array $modules = [];

    public function ready(Application $app): void
    {
        $app->singleton(AdminSite::class, static fn (): AdminSite => new AdminSite($app));
        $app->get(SystemChecks::class)->register(self::checks(...));
        $site = $app->get(AdminSite::class);

        foreach ($app->apps() as $config) {
            $file = $config->path . DIRECTORY_SEPARATOR . 'admin.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
        foreach (get_declared_classes() as $class) {
            if (!is_subclass_of($class, ModelAdmin::class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            foreach ((new \ReflectionClass($class))->getAttributes(Register::class) as $attribute) {
                foreach ($attribute->newInstance()->models as $model) {
                    if ($app->apps()->has($model::meta()->appLabel) && !$site->isRegistered($model)) {
                        $site->register($model, $class);
                    }
                }
            }
        }
        if ($app->apps()->has('auth')) {
            foreach ([User::class => UserAdmin::class, Group::class => GroupAdmin::class] as $model => $admin) {
                if (!$site->isRegistered($model)) {
                    $site->register($model, $admin);
                }
            }
        }
    }

    /**
     * @return list<CheckMessage>
     */
    private static function checks(Application $app): array
    {
        $messages = [];
        if (!$app->apps()->has('auth')) {
            $messages[] = CheckMessage::error('admin.E001', "brewphp.admin needs 'brewphp.auth' in INSTALLED_APPS.");
        }
        $middleware = $app->settings()->array('MIDDLEWARE');
        foreach (['admin.E002' => SessionMiddleware::class, 'admin.E003' => AuthenticationMiddleware::class, 'admin.E004' => CsrfViewMiddleware::class] as $id => $class) {
            if (!in_array($class, $middleware, true)) {
                $messages[] = CheckMessage::error($id, sprintf('brewphp.admin needs %s in MIDDLEWARE.', $class));
            }
        }
        foreach ($app->get(AdminSite::class)->registry() as $model => $admin) {
            foreach ([...$admin->listDisplay, ...$admin->listFilter] as $name) {
                if ($name !== '__str__' && !$admin->meta()->hasField($name) && $admin->methodFor($name) === null) {
                    $messages[] = CheckMessage::error('admin.E108', sprintf('%s: "%s" is not a field of %s nor a method of the admin.', $admin::class, $name, $model));
                }
            }
            foreach ($admin->listFilter as $name) {
                if (!$admin->meta()->hasField($name)) {
                    $messages[] = CheckMessage::error('admin.E116', sprintf('%s: listFilter "%s" must be a field of %s.', $admin::class, $name, $model));
                }
            }
        }

        return $messages;
    }
}
