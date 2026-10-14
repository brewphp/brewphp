<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Http;

use BrewPHP\Http\Request;
use BrewPHP\Http\UploadedFile;
use BrewPHP\Tests\TestCase;

final class RequestTest extends TestCase
{
    public function testGetRequest(): void
    {
        $request = Request::create('GET', 'http://example.com:8080/posts/hello%20world/?page=2', ['sort' => 'new']);

        self::assertSame('GET', $request->method());
        self::assertTrue($request->isMethod('get'));
        self::assertSame('/posts/hello world/', $request->path());
        self::assertSame('/posts/hello world/?page=2&sort=new', $request->fullPath());
        self::assertSame('2', $request->query('page'));
        self::assertSame(['page' => '2', 'sort' => 'new'], $request->query());
        self::assertSame('example.com', $request->host());
        self::assertSame(8080, $request->port());
        self::assertSame('example.com:8080', $request->httpHost());
        self::assertSame('http://example.com:8080/posts/hello world/', $request->url());
        self::assertSame('http://example.com:8080/a/', $request->absoluteUrl('a/'));
        self::assertSame('https://other.test/', $request->absoluteUrl('https://other.test/'));
        self::assertFalse($request->isSecure());
    }

    public function testHttpsDefaults(): void
    {
        $request = Request::create('GET', 'https://secure.test/');

        self::assertTrue($request->isSecure());
        self::assertSame('https', $request->scheme());
        self::assertSame(443, $request->port());
        self::assertSame('https://secure.test/', $request->fullUrl());
    }

    public function testPostBodyAndInput(): void
    {
        $request = Request::create('POST', '/form/?from=query', ['title' => 'Hi', 'from' => 'body']);

        self::assertSame('Hi', $request->post('title'));
        self::assertSame('body', $request->input('from'));
        self::assertTrue($request->has('title'));
        self::assertFalse($request->has('nope'));
        self::assertSame('default', $request->input('nope', 'default'));
        self::assertSame(['from' => 'body', 'title' => 'Hi'], $request->all());
    }

    public function testMethodOverride(): void
    {
        self::assertSame('DELETE', Request::create('POST', '/', ['_method' => 'delete'])->method());
        self::assertSame('PATCH', Request::create('POST', '/', server: ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'PATCH'])->method());
        self::assertSame('POST', Request::create('POST', '/', ['_method' => 'TRACE'])->method());
        self::assertSame('GET', Request::create('GET', '/', ['_method' => 'DELETE'])->method());
    }

    public function testJsonBody(): void
    {
        $request = Request::create('POST', '/api/', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"Ada"}');

        self::assertTrue($request->isJson());
        self::assertTrue($request->wantsJson());
        self::assertSame(['name' => 'Ada'], $request->json());
        self::assertSame('Ada', $request->input('name'));
        self::assertSame('{"name":"Ada"}', $request->content());

        $invalid = Request::create('POST', '/', server: ['CONTENT_TYPE' => 'application/json'], content: 'nope');
        self::assertSame([], $invalid->json());
    }

    public function testHeadersCookiesAndAttributes(): void
    {
        $request = Request::create('GET', '/', cookies: ['theme' => 'dark'], server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'REMOTE_ADDR' => '10.0.0.1',
        ]);

        self::assertSame('application/json', $request->header('Accept'));
        self::assertSame('fallback', $request->header('X-Missing', 'fallback'));
        self::assertArrayHasKey('accept', $request->headers());
        self::assertTrue($request->wantsJson());
        self::assertTrue($request->isAjax());
        self::assertSame('dark', $request->cookie('theme'));
        self::assertSame(['theme' => 'dark'], $request->cookies());
        self::assertSame('10.0.0.1', $request->ip());

        $request->setAttribute('user', 'ada');
        self::assertSame('ada', $request->attribute('user'));
        self::assertNull($request->attribute('missing'));
    }

    public function testFiles(): void
    {
        $file = UploadedFile::fake(__FILE__, 'test.php');
        $request = Request::create('POST', '/upload/', files: ['doc' => $file, 'many' => [$file]]);

        self::assertSame($file, $request->file('doc'));
        self::assertNull($request->file('many'));
        self::assertNull($request->file('missing'));
        self::assertCount(2, $request->files());
    }

    public function testFromGlobals(): void
    {
        $backup = [$_GET, $_POST, $_COOKIE, $_FILES, $_SERVER];
        try {
            $_GET = ['q' => 'search'];
            $_POST = [];
            $_COOKIE = [];
            $_FILES = [];
            $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/find/?q=search', 'HTTP_HOST' => 'site.test'];

            $request = Request::fromGlobals();
            self::assertSame('/find/', $request->path());
            self::assertSame('search', $request->query('q'));
            self::assertSame('site.test', $request->host());
        } finally {
            [$_GET, $_POST, $_COOKIE, $_FILES, $_SERVER] = $backup;
        }
    }
}
