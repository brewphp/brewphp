<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Feature;

use BrewPHP\Core\ErrorHandler;
use BrewPHP\Tests\TestCase;

final class ErrorPagesTest extends TestCase
{
    private const PRODUCTION = ['DEBUG' => false, 'ALLOWED_HOSTS' => ['localhost']];

    private string $log;

    protected function setUp(): void
    {
        $this->log = $this->tempDir() . '/php-errors.log';
        ini_set('error_log', $this->log);
    }

    public function testDebugPageShowsExceptionDetails(): void
    {
        // Built from pieces so the source excerpts on the page never contain it.
        $response = $this->request('GET', '/boom/?token=abc', settings: ['SECRET_KEY' => 'super-secret-' . 'value']);
        $html = $response->content();

        self::assertSame(500, $response->status());
        self::assertStringContainsString('Kaboom', $html);
        self::assertStringContainsString('RuntimeException', $html);
        self::assertStringContainsString('Stack trace', $html);
        self::assertStringContainsString('config/urls.php', str_replace('\\', '/', $html));
        self::assertStringContainsString('DEBUG = true', $html);
        self::assertStringNotContainsString('super-secret-value', $html, 'secret settings are masked');
        self::assertStringContainsString('********', $html);
        self::assertStringContainsString('Kaboom', (string) file_get_contents($this->log), 'server errors are logged');
    }

    public function testProductionPageHidesDetails(): void
    {
        $response = $this->request('GET', '/boom/', settings: self::PRODUCTION);

        self::assertSame(500, $response->status());
        self::assertStringContainsString('Server error', $response->content());
        self::assertStringNotContainsString('Kaboom', $response->content());
        self::assertStringNotContainsString('Stack trace', $response->content());
    }

    public function testProduction404AndOverriddenTemplate(): void
    {
        $missing = $this->request('GET', '/missing/', settings: self::PRODUCTION);
        self::assertSame(404, $missing->status());
        self::assertStringContainsString('Page not found', $missing->content());
        self::assertStringNotContainsString('tried these URL patterns', $missing->content());

        $forbidden = $this->request('GET', '/forbidden/', settings: self::PRODUCTION);
        self::assertSame(403, $forbidden->status());
        self::assertSame("<p>Custom forbidden page: Members only</p>\n", $forbidden->content());
    }

    public function testDebug404ListsPatterns(): void
    {
        $response = $this->request('GET', '/missing/');
        $html = $response->content();

        self::assertSame(404, $response->status());
        self::assertStringContainsString('tried these URL patterns', $html);
        self::assertStringContainsString('/articles/&lt;int:id&gt;/', $html);
        self::assertStringContainsString("[name='demo:post']", $html);
    }

    public function testJsonErrors(): void
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];

        $missing = $this->request('GET', '/missing/', server: $server, settings: self::PRODUCTION);
        self::assertSame(404, $missing->status());
        self::assertSame('{"error":"No route matches \"/missing/\".","status":404}', $missing->content());

        $boom = $this->request('GET', '/boom/', server: $server, settings: self::PRODUCTION);
        self::assertSame('{"error":"Server error","status":500}', $boom->content());

        $debug = json_decode($this->request('GET', '/boom/', server: $server)->content(), true);
        self::assertIsArray($debug);
        self::assertSame('Kaboom', $debug['exception']['message']);
    }

    public function testBrokenErrorTemplateFallsBackToPlainText(): void
    {
        $response = $this->request('GET', '/boom/', settings: self::PRODUCTION + ['TEMPLATES' => ['DIRS' => [__DIR__ . '/../Fixtures/broken-templates']]]);

        self::assertSame(500, $response->status());
        self::assertSame('<h1>500 Server error</h1>', $response->content());
    }

    public function testMask(): void
    {
        self::assertSame(
            ['SECRET_KEY' => '********************', 'DATABASES' => ['default' => ['PASSWORD' => '********************', 'NAME' => 'db']], 'DEBUG' => true],
            ErrorHandler::mask(['SECRET_KEY' => 'x', 'DATABASES' => ['default' => ['PASSWORD' => 'p', 'NAME' => 'db']], 'DEBUG' => true]),
        );
    }
}
