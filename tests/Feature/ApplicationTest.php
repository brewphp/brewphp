<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Feature;

use Apps\Demo\App as DemoApp;
use BrewPHP\Core\AppConfig;
use BrewPHP\Core\Application;
use BrewPHP\Core\Apps;
use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Core\Settings;
use BrewPHP\Http\Request;
use BrewPHP\Routing\Router;
use BrewPHP\Templates\Templates;
use BrewPHP\Tests\TestCase;

final class PackagedApp extends AppConfig
{
    public string $verboseName = 'Packaged';
}

final class ApplicationTest extends TestCase
{
    public function testCreateLoadsEnvSettingsAndApps(): void
    {
        $project = $this->copyProject();
        file_put_contents($project . '/.env', "BREW_GREETING=hi\n");
        file_put_contents($project . '/config/settings.php', "<?php return ['DEBUG' => env('BREW_GREETING') === 'hi', 'INSTALLED_APPS' => ['demo'], 'TIME_ZONE' => 'Europe/Paris'];");

        $app = Application::create($project);

        self::assertTrue($app->isDebug());
        self::assertSame('Europe/Paris', date_default_timezone_get());
        self::assertSame($app, Application::getInstance());
        self::assertTrue(DemoApp::$ready);
        date_default_timezone_set('UTC');
    }

    public function testPaths(): void
    {
        $app = $this->makeApp(['STORAGE_PATH' => '/var/brew']);

        self::assertSame(self::PROJECT . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'urls.php', $app->basePath('config', 'urls.php'));
        self::assertSame('/var/brew' . DIRECTORY_SEPARATOR . 'logs', $app->storagePath('logs'));
        self::assertFileExists($app->resourcePath('templates', 'brewphp', 'welcome.php'));
        self::assertSame('C:\\x', $app->resolvePath('C:\\x'));
        self::assertSame(self::PROJECT . DIRECTORY_SEPARATOR . 'rel', $app->resolvePath('rel'));
    }

    public function testServicesAreRegistered(): void
    {
        $app = $this->makeApp();

        self::assertSame($app->router(), $app->get(Router::class));
        self::assertInstanceOf(Templates::class, $app->templates());
        self::assertSame($app->settings(), $app->get(Settings::class));
        self::assertSame($app, app());
        self::assertTrue(settings('DEBUG'));
        self::assertSame($app->apps(), $app->get(Apps::class));
    }

    public function testTemplateDirectoryOrder(): void
    {
        $directories = $this->makeApp()->templateDirectories();

        self::assertSame(self::PROJECT . DIRECTORY_SEPARATOR . 'templates', $directories[0]);
        self::assertStringEndsWith('demo' . DIRECTORY_SEPARATOR . 'templates', $directories[1]);
        self::assertStringEndsWith('resources' . DIRECTORY_SEPARATOR . 'templates', $directories[2]);
    }

    public function testAppRegistry(): void
    {
        $apps = $this->makeApp()->apps();
        $demo = $apps->get('demo');

        self::assertInstanceOf(DemoApp::class, $demo);
        self::assertSame('apps.demo', $demo->name);
        self::assertSame('Demo app', $demo->verboseName);
        self::assertSame('Apps\Demo', $demo->namespace);
        self::assertTrue($apps->has('demo'));
        self::assertCount(1, $apps);
        self::assertSame(['demo'], array_keys($apps->all()));
    }

    public function testPackagedAppByClassName(): void
    {
        $apps = new Apps(self::PROJECT);
        $app = $apps->register(PackagedApp::class);

        self::assertSame('feature', $app->label);
        self::assertSame('Packaged', $app->verboseName);
        self::assertSame(__DIR__, $app->path);
    }

    public function testDefaultAppConfigWhenAppHasNoAppClass(): void
    {
        $project = $this->copyProject();
        mkdir($project . '/apps/blog_posts');

        $app = (new Apps($project))->register('blog_posts');

        self::assertSame(AppConfig::class, $app::class);
        self::assertSame('Blog Posts', $app->verboseName);
        self::assertSame('Apps\BlogPosts', $app->namespace);
    }

    /**
     * @return iterable<string, array{list<mixed>, string}>
     */
    public static function invalidAppsProvider(): iterable
    {
        yield 'missing folder' => [['nope'], 'does not exist'];
        yield 'bad label' => [['Bad-Label'], 'Invalid app'];
        yield 'not a string' => [[42], 'non-empty string'];
        yield 'duplicate' => [['demo', 'demo'], 'not unique'];
        yield 'not an app class' => [[Settings::class], 'is not an'];
    }

    /**
     * @param list<mixed> $entries
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAppsProvider')]
    public function testInvalidApps(array $entries, string $message): void
    {
        $this->expectException(ImproperlyConfigured::class);
        $this->expectExceptionMessage($message);
        (new Apps(self::PROJECT))->populate($entries);
    }

    public function testGetUnknownApp(): void
    {
        $this->expectException(ImproperlyConfigured::class);
        (new Apps(self::PROJECT))->get('nope');
    }

    public function testGetInstanceWithoutApplication(): void
    {
        Application::setInstance(null);
        self::assertFalse(Application::hasInstance());
        $this->expectException(\LogicException::class);
        Application::getInstance();
    }

    public function testHelpers(): void
    {
        $app = $this->makeApp();

        self::assertSame('/articles/5/', reverse('article', ['id' => 5]));
        self::assertSame('http://localhost/articles/5/', url(reverse('article', ['id' => 5])), 'APP_URL default outside a request');

        $app->handle(Request::create('GET', 'https://site.localhost/'));
        self::assertSame('https://site.localhost/about/', url('/about/'));

        self::assertSame('/articles/5/', redirect('article', ['id' => 5])->targetUrl());
        self::assertSame('/login/', redirect('/login/')->targetUrl());
        self::assertSame('https://brewphp.dev', redirect('https://brewphp.dev', status: 301)->targetUrl());
        self::assertSame("<h1>Hello, x!</h1>\n", render('demo/hello.php', ['name' => 'x'])->content());
        self::assertSame(201, response('', 201)->status());
        self::assertSame('[1]', json([1])->content());
        self::assertSame(base_path('a'), $app->basePath('a'));
        self::assertSame(storage_path('logs'), $app->storagePath('logs'));
        self::assertNull(env('BREW_NEVER_SET_ANYWHERE'));
        self::assertSame($app->router(), app(Router::class));
    }

    public function testEscapeHelper(): void
    {
        self::assertSame('&lt;a href=&quot;x&quot;&gt;&#039;', e('<a href="x">\''));
        self::assertSame('5', e(5));
        self::assertSame('', e(null));
        self::assertSame('1', e(true));
        self::assertSame('', e(false));
        $this->expectException(\InvalidArgumentException::class);
        e([]);
    }

    public function testAbortHelper(): void
    {
        foreach ([400 => 'Bad request', 403 => 'Permission denied', 404 => 'Page not found', 410 => ''] as $status => $message) {
            try {
                abort($status);
            } catch (\BrewPHP\Http\HttpException $e) {
                self::assertSame($status, $e->status());
                self::assertSame($message, $e->getMessage());
            }
            try {
                abort($status, 'Custom');
            } catch (\BrewPHP\Http\HttpException $e) {
                self::assertSame('Custom', $e->getMessage());
            }
        }
    }
}
