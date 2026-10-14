<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Middleware;

use BrewPHP\Core\Container;
use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Core\Settings;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use BrewPHP\Middleware\Middleware;
use BrewPHP\Middleware\Pipeline;
use BrewPHP\Middleware\SecurityHeadersMiddleware;
use BrewPHP\Tests\TestCase;
use Closure;

final class AppendMiddleware implements Middleware
{
    public function process(Request $request, Closure $next): Response
    {
        $request->setAttribute('trail', $request->attribute('trail', '') . 'A>');
        $response = $next($request);

        return $response->setContent($response->content() . '<A');
    }
}

final class MiddlewareTest extends TestCase
{
    public function testRunsInOrderAroundTheHandler(): void
    {
        $pipeline = new Pipeline(new Container(), [
            AppendMiddleware::class,
            function (Request $request, Closure $next): Response {
                $request->setAttribute('trail', $request->attribute('trail') . 'B>');

                return $next($request)->header('X-B', '1');
            },
            new AppendMiddleware(),
        ]);

        $response = $pipeline->handle(Request::create('GET', '/'), fn (Request $r): Response => new Response((string) $r->attribute('trail') . 'view'));

        self::assertSame('A>B>A>view<A<A', $response->content());
        self::assertSame('1', $response->getHeader('X-B'));
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $pipeline = new Pipeline(new Container(), [fn (Request $r, Closure $next): Response => new Response('blocked', 403)]);
        $response = $pipeline->handle(Request::create('GET', '/'), fn (): Response => throw new \LogicException('not reached'));

        self::assertSame(403, $response->status());
    }

    public function testInvalidMiddleware(): void
    {
        $this->expectException(ImproperlyConfigured::class);
        (new Pipeline(new Container(), ['NotAClass']))->handle(Request::create('GET', '/'), fn (): Response => new Response());
    }

    public function testClosureMustReturnResponse(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new Pipeline(new Container(), [fn (): string => 'oops']))->handle(Request::create('GET', '/'), fn (): Response => new Response());
    }

    public function testSecurityHeadersDefaults(): void
    {
        $middleware = new SecurityHeadersMiddleware(new Settings());
        $response = $middleware->process(Request::create('GET', 'https://site.test/'), fn (): Response => (new Response())->header('X-Frame-Options', 'SAMEORIGIN'));

        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $response->getHeader('X-Frame-Options'), 'a header set by the view is kept');
        self::assertSame('same-origin', $response->getHeader('Referrer-Policy'));
        self::assertSame('same-origin', $response->getHeader('Cross-Origin-Opener-Policy'));
        self::assertNull($response->getHeader('Strict-Transport-Security'));
        self::assertNull($response->getHeader('Content-Security-Policy'));
    }

    public function testHstsAndCsp(): void
    {
        $settings = new Settings([
            'SECURE_HSTS_SECONDS' => 31536000,
            'SECURE_HSTS_INCLUDE_SUBDOMAINS' => true,
            'SECURE_HSTS_PRELOAD' => true,
            'SECURE_CSP' => "default-src 'self'",
            'SECURE_CONTENT_TYPE_NOSNIFF' => false,
        ]);
        $middleware = new SecurityHeadersMiddleware($settings);

        $secure = $middleware->process(Request::create('GET', 'https://site.test/'), fn (): Response => new Response());
        self::assertSame('max-age=31536000; includeSubDomains; preload', $secure->getHeader('Strict-Transport-Security'));
        self::assertSame("default-src 'self'", $secure->getHeader('Content-Security-Policy'));
        self::assertNull($secure->getHeader('X-Content-Type-Options'));

        $plain = $middleware->process(Request::create('GET', 'http://site.test/'), fn (): Response => new Response());
        self::assertNull($plain->getHeader('Strict-Transport-Security'), 'HSTS is only sent over HTTPS');
    }
}
