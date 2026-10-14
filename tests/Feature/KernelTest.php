<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Feature;

use BrewPHP\Core\Application;
use BrewPHP\Core\Settings;
use BrewPHP\Http\Request;
use BrewPHP\Routing\RouteMatch;
use BrewPHP\Tests\TestCase;

final class KernelTest extends TestCase
{
    public function testFunctionViewRendersAppTemplate(): void
    {
        $response = $this->request('GET', '/');

        self::assertSame(200, $response->status());
        self::assertSame("<h1>Hello, world!</h1>\n", $response->content());
    }

    public function testControllerClassViewAndJson(): void
    {
        self::assertSame('Hello, &lt;Ada&gt;!', $this->request('GET', '/hello/<Ada>/')->content());
        self::assertSame('Article 7', $this->request('GET', '/articles/7/')->content());
        self::assertSame(204, $this->request('DELETE', '/articles/7/')->status());

        $json = $this->request('GET', '/json/?q=brew');
        self::assertSame('application/json', $json->getHeader('Content-Type'));
        self::assertSame('{"ok":true,"q":"brew"}', $json->content());
    }

    public function testRouteMatchIsStoredOnTheRequest(): void
    {
        $app = $this->makeApp();
        $request = Request::create('GET', '/articles/3/');
        $app->handle($request);

        $match = $request->attribute('route');
        self::assertInstanceOf(RouteMatch::class, $match);
        self::assertSame('article', $match->name());
        self::assertSame(['id' => 3], $match->parameters);
        self::assertSame($request, $app->get(Request::class));
    }

    public function testMethodNotAllowed(): void
    {
        $response = $this->request('GET', '/only-post/');

        self::assertSame(405, $response->status());
        self::assertSame('POST', $response->getHeader('Allow'));
        self::assertSame('posted', $this->request('POST', '/only-post/')->content());

        $classView = $this->request('PUT', '/articles/1/');
        self::assertSame(405, $classView->status());
        self::assertSame('GET, DELETE, OPTIONS, HEAD', $classView->getHeader('Allow'));
    }

    public function testAppendSlashRedirect(): void
    {
        $response = $this->request('GET', '/articles/7?ref=x');

        self::assertSame(301, $response->status());
        self::assertSame('/articles/7/?ref=x', $response->getHeader('Location'));
        self::assertSame(404, $this->request('POST', '/articles/7')->status(), 'only GET and HEAD are redirected');
        self::assertSame(404, $this->request('GET', '/articles/7', settings: ['APPEND_SLASH' => false])->status());
    }

    public function testAppendSlashNeverRedirectsOffSite(): void
    {
        $app = new Application(self::PROJECT, new Settings([
            'DEBUG' => true,
            'ROOT_URLCONF' => $this->urlconf("[path('<path:any>/', fn () => 'ok')]"),
        ]));
        $backslash = $app->handle(Request::create('GET', '/', server: ['REQUEST_URI' => '/\evil.example']));
        self::assertSame(301, $backslash->status());
        self::assertSame('/evil.example/', $backslash->getHeader('Location'));

        // PHP parses "//host/x" as a host plus the path "/x".
        $doubleSlash = $app->handle(Request::create('GET', '/', server: ['REQUEST_URI' => '//evil.example/x']));
        self::assertSame('/x/', $doubleSlash->getHeader('Location'));
    }

    public function testIncludedRoutesWithMiddleware(): void
    {
        $response = $this->request('GET', '/demo/tagged/');

        self::assertSame('tagged', $response->content());
        self::assertSame('demo', $response->getHeader('X-Tag'));
        self::assertSame('post my-first-post', $this->request('GET', '/demo/posts/my-first-post/')->content());
        self::assertSame('/demo/posts/hi/', $this->makeApp()->router()->reverse('demo:post', ['slug' => 'hi']));
    }

    public function testGlobalMiddlewareAlsoWrapsErrors(): void
    {
        $ok = $this->request('GET', '/');
        $missing = $this->request('GET', '/missing/');

        self::assertSame('nosniff', $ok->getHeader('X-Content-Type-Options'));
        self::assertSame(404, $missing->status());
        self::assertSame('DENY', $missing->getHeader('X-Frame-Options'));
    }

    public function testAllowedHosts(): void
    {
        // DEBUG with no ALLOWED_HOSTS: only local addresses.
        self::assertSame(200, $this->request('GET', 'http://localhost/')->status());
        self::assertSame(200, $this->request('GET', 'http://app.localhost/')->status());
        self::assertSame(400, $this->request('GET', 'http://evil.test/')->status());

        // Production with no ALLOWED_HOSTS: nothing is served.
        self::assertSame(400, $this->request('GET', 'http://localhost/', settings: ['DEBUG' => false])->status());

        $production = ['DEBUG' => false, 'ALLOWED_HOSTS' => ['.example.com', 'brewphp.dev']];
        self::assertSame(200, $this->request('GET', 'http://example.com/', settings: $production)->status());
        self::assertSame(200, $this->request('GET', 'http://www.example.com/', settings: $production)->status());
        self::assertSame(200, $this->request('GET', 'http://BrewPHP.dev:8080/', settings: $production)->status());
        self::assertSame(400, $this->request('GET', 'http://notexample.com/', settings: $production)->status());
        self::assertSame(400, $this->request('GET', '/', server: ['HTTP_HOST' => 'bad host!'], settings: ['ALLOWED_HOSTS' => ['*']])->status());
        self::assertSame(200, $this->request('GET', 'http://anything.test/', settings: ['DEBUG' => false, 'ALLOWED_HOSTS' => ['*']])->status());
    }

    public function testWelcomePageWithoutRoutes(): void
    {
        $settings = ['ROOT_URLCONF' => 'config/missing-urls.php'];

        $welcome = $this->request('GET', '/', settings: $settings);
        self::assertSame(200, $welcome->status());
        self::assertStringContainsString('Your project is brewing.', $welcome->content());
        self::assertStringContainsString('Plus+Jakarta+Sans', $welcome->content());

        self::assertSame(404, $this->request('GET', '/', settings: $settings + ['DEBUG' => false, 'ALLOWED_HOSTS' => ['*']])->status());
    }

    public function testInvalidUrlconfGivesServerError(): void
    {
        $response = $this->request('GET', '/', settings: ['ROOT_URLCONF' => $this->urlconf("'not an array'"), 'DEBUG' => false, 'ALLOWED_HOSTS' => ['*']]);

        self::assertSame(500, $response->status());
    }

    private function urlconf(string $returns): string
    {
        $file = $this->tempDir() . '/urls.php';
        file_put_contents($file, "<?php\nreturn $returns;\n");

        return $file;
    }

    protected function setUp(): void
    {
        // Server errors are written to the PHP error log; keep test output clean.
        ini_set('error_log', $this->tempDir() . '/php-errors.log');
    }
}
