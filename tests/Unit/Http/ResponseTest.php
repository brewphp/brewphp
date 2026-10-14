<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Http;

use BrewPHP\Http\Cookie;
use BrewPHP\Http\Http404;
use BrewPHP\Http\JsonResponse;
use BrewPHP\Http\MethodNotAllowed;
use BrewPHP\Http\PermissionDenied;
use BrewPHP\Http\RedirectResponse;
use BrewPHP\Http\Response;
use BrewPHP\Http\StreamedResponse;
use BrewPHP\Tests\TestCase;

final class ResponseTest extends TestCase
{
    public function testBasics(): void
    {
        $response = new Response('<p>Hi</p>', 201, ['X-Custom' => 'yes']);

        self::assertSame('<p>Hi</p>', $response->content());
        self::assertSame(201, $response->status());
        self::assertSame('Created', $response->statusText());
        self::assertSame('yes', $response->getHeader('x-custom'));
        self::assertSame('text/html; charset=utf-8', $response->getHeader('Content-Type'));

        $response->header('x-custom', 'replaced')->setStatus(202)->setContent('new');
        self::assertSame(['x-custom' => 'replaced', 'Content-Type' => 'text/html; charset=utf-8'], $response->headers());
        self::assertSame('new', $response->content());
        self::assertSame(202, $response->status());

        $response->removeHeader('X-CUSTOM');
        self::assertFalse($response->hasHeader('x-custom'));
    }

    public function testRejectsHeaderInjectionAndBadStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Response())->header('X-Test', "a\r\nSet-Cookie: evil=1");
    }

    public function testRejectsInvalidStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Response())->setStatus(99);
    }

    public function testSendOutputsContent(): void
    {
        $response = new Response('sent body');
        ob_start();
        $response->send();
        self::assertSame('sent body', ob_get_clean());
    }

    public function testCookies(): void
    {
        $response = (new Response())
            ->cookie(new Cookie('theme', 'dark mode', expires: time() + 3600, secure: true, sameSite: 'Strict'))
            ->deleteCookie('old');

        $header = $response->cookies()['theme']->toHeader();
        self::assertStringStartsWith('theme=dark%20mode; Expires=', $header);
        self::assertStringContainsString('Max-Age=', $header);
        self::assertStringContainsString('; Path=/; Secure; HttpOnly; SameSite=Strict', $header);
        self::assertStringContainsString('old=; Expires=Thu, 01 Jan 1970', $response->cookies()['old']->toHeader());

        $session = new Cookie('s', 'v', domain: 'example.com', httpOnly: false, sameSite: null);
        self::assertSame('s=v; Path=/; Domain=example.com', $session->toHeader());
        self::assertGreaterThan(time(), (new Cookie('w', 'v', expires: '+1 week'))->expires);
        self::assertSame(86400, (new Cookie('d', 'v', expires: new \DateTimeImmutable('@86400')))->expires);
    }

    public function testCookieValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cookie('bad name');
    }

    public function testSameSiteNoneRequiresSecure(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cookie('c', 'v', sameSite: 'None');
    }

    public function testJsonResponse(): void
    {
        $response = new JsonResponse(['name' => 'Café', 'url' => 'https://a/b'], 201, ['content-type' => 'application/vnd.api+json']);

        self::assertSame('{"name":"Café","url":"https://a/b"}', $response->content());
        self::assertSame(['name' => 'Café', 'url' => 'https://a/b'], $response->data());
        self::assertSame('application/vnd.api+json', $response->getHeader('Content-Type'));
        self::assertSame(201, $response->status());
        self::assertSame('application/json', (new JsonResponse([]))->getHeader('content-type'));
    }

    public function testRedirectResponse(): void
    {
        $response = new RedirectResponse('/login/?next=/a&b', 301);

        self::assertSame(301, $response->status());
        self::assertSame('/login/?next=/a&b', $response->getHeader('Location'));
        self::assertSame('/login/?next=/a&b', $response->targetUrl());
        self::assertStringContainsString('/login/?next=/a&amp;b', $response->content());
    }

    public function testRedirectValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RedirectResponse('/x', 200);
    }

    public function testEmptyRedirectRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RedirectResponse('');
    }

    public function testStreamedResponse(): void
    {
        $response = new StreamedResponse(function (): void {
            echo 'a,b', "\n", 'c,d';
        }, headers: ['Content-Type' => 'text/csv']);

        self::assertSame("a,b\nc,d", $response->content());
        self::assertSame('text/csv', $response->getHeader('content-type'));

        ob_start();
        $response->send();
        self::assertSame("a,b\nc,d", ob_get_clean());
    }

    public function testHttpExceptions(): void
    {
        self::assertSame(404, (new Http404())->status());
        self::assertSame('Page not found', (new Http404())->getMessage());
        self::assertSame(403, (new PermissionDenied('No'))->status());

        $notAllowed = new MethodNotAllowed(['GET', 'HEAD']);
        self::assertSame(405, $notAllowed->status());
        self::assertSame(['Allow' => 'GET, HEAD'], $notAllowed->headers());
        self::assertSame(['GET', 'HEAD'], $notAllowed->allowed());
    }
}
