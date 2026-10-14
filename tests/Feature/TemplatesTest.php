<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Feature;

use BrewPHP\Core\ErrorHandler;
use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Core\Settings;
use BrewPHP\Http\Request;
use BrewPHP\Templates\ContextProcessors;
use BrewPHP\Templates\TemplateError;
use BrewPHP\Templates\Templates;
use BrewPHP\Tests\TestCase;

final class FooterProcessor
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request): array
    {
        return ['footer' => 'footer for ' . $request->path(), 'site' => 'Processor'];
    }
}

/**
 * The templates facade inside an application: engine selection, render(),
 * context processors, app libraries and error pages.
 */
final class TemplatesTest extends TestCase
{
    protected function setUp(): void
    {
        ini_set('error_log', $this->tempDir() . '/php-errors.log');
    }

    /**
     * A project with its own templates and an URLconf rendering them.
     *
     * @param array<string, string> $templates
     */
    private function project(array $templates, string $routes): string
    {
        $project = $this->copyProject();
        foreach ($templates as $name => $source) {
            @mkdir(dirname($project . '/templates/' . $name), 0777, true);
            file_put_contents($project . '/templates/' . $name, $source);
        }
        file_put_contents($project . '/config/urls.php', "<?php\n\nreturn [\n" . $routes . "\n];\n");

        return $project;
    }

    public function testDefaults(): void
    {
        $defaults = Settings::defaults()['TEMPLATES'];

        self::assertTrue($defaults['CACHE']);
        self::assertFalse($defaults['STRICT']);
        self::assertSame([[ContextProcessors::class, 'request']], $defaults['CONTEXT_PROCESSORS']);
        self::assertInstanceOf(Templates::class, $this->makeApp()->templates());
    }

    public function testEngineIsChosenByExtension(): void
    {
        $project = $this->project(
            ['page.html' => '<p>{{ name }}</p>{# comment #}', 'page.php' => '<p><?= e($name) ?></p><?php /* php */ ?>', 'page.txt' => 'Hi {{ name }}'],
            '',
        );
        $templates = $this->makeApp([], $project)->templates();

        self::assertSame('<p>&lt;Ann&gt;</p>', $templates->render('page.html', ['name' => '<Ann>']));
        self::assertSame('<p>&lt;Ann&gt;</p>', $templates->render('page.php', ['name' => '<Ann>']));
        self::assertSame('Hi &lt;Ann&gt;', $templates->render('page.txt', ['name' => '<Ann>']));
        self::assertSame('<p>x</p>', $templates->render(['missing.html', 'page.php'], ['name' => 'x']));
        self::assertTrue($templates->exists('page.txt'));
        self::assertSame($project . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'page.html', $templates->find('page.html'));
        self::assertFileExists($project . '/storage/templates', 'compiled into storage/templates');
    }

    public function testRenderHelperRunsContextProcessors(): void
    {
        $project = $this->project(
            [
                'base.html' => '<title>{% block title %}{{ site }}{% endblock %}</title>{% block content %}{% endblock %}<footer>{{ footer }}</footer>',
                'page.html' => "{% extends 'base.html' %}{% block content %}{{ request.path }} {{ request.method }} {{ site }}{% endblock %}",
                'plain.php' => '<?= e($request->path()) ?>',
            ],
            "path('page/', fn () => render('page.html', ['site' => 'View']), name: 'page'),\n"
            . "path('plain/', fn () => render('plain.php'), name: 'plain'),\n"
            . "path('string/', fn () => render_to_string('page.html'), name: 'string'),",
        );
        $settings = ['TEMPLATES' => ['DIRS' => ['templates'], 'CONTEXT_PROCESSORS' => [
            [ContextProcessors::class, 'request'],
            FooterProcessor::class,
        ]]];
        $app = $this->makeApp($settings, $project);

        self::assertSame('<title>View</title>/page/ GET View<footer>footer for /page/</footer>', $app->handle(Request::create('GET', '/page/'))->content());
        self::assertSame('/plain/', $app->handle(Request::create('GET', '/plain/'))->content());
        self::assertSame('<title>Processor</title>/string/ GET Processor<footer>footer for /string/</footer>', $app->handle(Request::create('GET', '/string/'))->content());
    }

    public function testRequestProcessorIsOnWithoutTheSetting(): void
    {
        $project = $this->project(['p.html' => '{{ request.path }}'], "path('p/', fn () => render('p.html'), name: 'p'),");
        $app = $this->makeApp(['TEMPLATES' => ['DIRS' => ['templates']]], $project);

        self::assertSame('/p/', $app->handle(Request::create('GET', '/p/'))->content());
        self::assertSame('', $app->templates()->render('p.html'), 'no request outside render()');
    }

    public function testInvalidContextProcessor(): void
    {
        $this->expectException(ImproperlyConfigured::class);
        $this->expectExceptionMessage('"not a callable"');
        $this->makeApp(['TEMPLATES' => ['DIRS' => [], 'CONTEXT_PROCESSORS' => ['not a callable']]])->templates();
    }

    public function testAppLibraries(): void
    {
        $app = $this->makeApp();
        $app->handle(Request::create('GET', '/json/'));
        $html = $app->templates()->render('demo/library.html', ['word' => 'hey', 'name' => 'Ann'], Request::create('GET', '/x/'));

        self::assertSame("HEY! Hello, Ann Hi, Ann *** [/x/]\n", $html);
    }

    public function testStrictSetting(): void
    {
        $app = $this->makeApp(['TEMPLATES' => ['DIRS' => [], 'STRICT' => true, 'CACHE' => false]]);

        $this->expectException(TemplateError::class);
        $app->templates()->renderString('{{ nope }}');
    }

    public function testDebugPageShowsTheTemplateLine(): void
    {
        $project = $this->project(
            ['broken.html' => "<h1>Title</h1>\n<p>{{ items|length }}</p>\n{% url 'no-such-route' %}\n<footer></footer>"],
            "path('broken/', fn () => render('broken.html'), name: 'broken'),",
        );
        $response = $this->makeApp([], $project)->handle(Request::create('GET', '/broken/'));
        $html = $response->content();

        self::assertSame(500, $response->status());
        self::assertStringContainsString('Template error', $html);
        self::assertStringContainsString('In template <strong>broken.html</strong>, line <strong>3</strong>', $html);
        self::assertStringContainsString('<div class="hl"><span class="ln">3</span>{% url &#039;no-such-route&#039; %}</div>', $html);
        self::assertStringContainsString('No route named &quot;no-such-route&quot;', $html);
    }

    public function testDebugPageForSyntaxErrors(): void
    {
        $app = $this->makeApp();
        $error = null;
        try {
            $app->templates()->renderString("ok\n{% if %}", []);
        } catch (TemplateError $e) {
            $error = $e;
        }
        self::assertNotNull($error);
        $html = (new ErrorHandler($app))->render($error, Request::create('GET', '/'))->content();

        self::assertStringContainsString('In template <strong>&lt;string&gt;</strong>, line <strong>2</strong>', $html);
    }

    public function testProductionErrorPagesCanBeTemplates(): void
    {
        $project = $this->project(
            ['base.html' => '<main>{% block content %}{% endblock %}</main>', '404.html' => "{% extends 'base.html' %}{% block content %}{{ status }} {{ title }}: {{ request.path }}{% endblock %}"],
            '',
        );
        $response = $this->makeApp(['DEBUG' => false, 'ALLOWED_HOSTS' => ['*']], $project)->handle(Request::create('GET', '/missing/'));

        self::assertSame(404, $response->status());
        self::assertSame('<main>404 Page not found: /missing/</main>', $response->content());
    }
}
