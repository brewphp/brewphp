<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Routing;

use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Http\MethodNotAllowed;
use BrewPHP\Routing\Attributes\Route as RouteAttribute;
use BrewPHP\Routing\Converter;
use BrewPHP\Routing\NoReverseMatch;
use BrewPHP\Routing\RouteNotFound;
use BrewPHP\Routing\Router;
use BrewPHP\Tests\TestCase;

#[RouteAttribute('api/posts/', namespace: 'posts', middleware: ['ApiMiddleware'])]
final class AttributeController
{
    #[RouteAttribute('', name: 'list', methods: ['GET'])]
    public function list(): string
    {
        return 'list';
    }

    #[RouteAttribute('<int:id>/', name: 'detail')]
    #[RouteAttribute('<int:id>/alias/', name: 'detail-alias')]
    public function detail(int $id): string
    {
        return (string) $id;
    }

    public function notRouted(): void
    {
    }
}

final class PlainController
{
    #[RouteAttribute('/plain/', name: 'plain')]
    public static function plain(): string
    {
        return 'plain';
    }
}

final class RouterTest extends TestCase
{
    public function testMatchesPathsWithConverters(): void
    {
        $router = new Router([
            path('', 'home', name: 'home'),
            path('posts/<int:id>/', 'detail', name: 'detail'),
            path('tags/<slug:tag>/', 'tag'),
            path('users/<username>/', 'user'),
            path('items/<uuid:uuid>/', 'item'),
            path('files/<path:file>', 'file'),
        ]);

        self::assertSame('home', $router->resolve('GET', '/')->route->view);
        self::assertSame(['id' => 42], $router->resolve('GET', '/posts/42/')->parameters);
        self::assertSame(['tag' => 'php-8_2'], $router->resolve('GET', '/tags/php-8_2/')->parameters);
        self::assertSame(['username' => 'ada lovelace'], $router->resolve('GET', '/users/ada lovelace/')->parameters);
        self::assertSame('item', $router->resolve('GET', '/items/123e4567-e89b-12d3-a456-426614174000/')->route->view);
        self::assertSame(['file' => 'docs/a/b.txt'], $router->resolve('GET', '/files/docs/a/b.txt')->parameters);
        self::assertSame('detail', $router->resolve('GET', '/posts/1/')->name());
    }

    public function testNoMatchThrowsRouteNotFoundWithTriedPatterns(): void
    {
        $router = new Router([path('posts/<int:id>/', 'detail')]);

        try {
            $router->resolve('GET', '/posts/abc/');
            self::fail('Expected RouteNotFound');
        } catch (RouteNotFound $e) {
            self::assertSame('/posts/abc/', $e->path);
            self::assertCount(1, $e->tried);
            self::assertSame(404, $e->status());
        }
        self::assertFalse($router->matches('/posts/abc/'));
        self::assertTrue($router->matches('/posts/1/'));
    }

    public function testFirstMatchWins(): void
    {
        $router = new Router([path('a/<str:x>/', 'first'), path('a/special/', 'second')]);

        self::assertSame('first', $router->resolve('GET', '/a/special/')->route->view);
    }

    public function testMethodRestriction(): void
    {
        $router = new Router([
            path('form/', 'show', methods: ['get']),
            path('form/', 'save', methods: ['POST']),
        ]);

        self::assertSame('show', $router->resolve('GET', '/form/')->route->view);
        self::assertSame('show', $router->resolve('HEAD', '/form/')->route->view);
        self::assertSame('save', $router->resolve('POST', '/form/')->route->view);

        try {
            $router->resolve('DELETE', '/form/');
            self::fail('Expected MethodNotAllowed');
        } catch (MethodNotAllowed $e) {
            self::assertSame(['GET', 'POST', 'HEAD'], $e->allowed());
        }
    }

    public function testRegexRoutes(): void
    {
        $router = new Router([
            re_path('^archive/(?P<year>[0-9]{4})/(?P<month>[0-9]{2})/$', 'archive', name: 'archive'),
            re_path('^search/(?:q-)?(?P<term>\w+)/$', 'search', name: 'search'),
        ]);

        self::assertSame(['year' => '2024', 'month' => '05'], $router->resolve('GET', '/archive/2024/05/')->parameters);
        self::assertSame('/archive/2024/05/', $router->reverse('archive', ['year' => 2024, 'month' => '05']));

        $this->expectException(NoReverseMatch::class);
        $this->expectExceptionMessage('too complex');
        $router->reverse('search', ['term' => 'x']);
    }

    public function testReverse(): void
    {
        $router = new Router([
            path('posts/<int:id>/', 'detail', name: 'detail'),
            path('files/<path:file>', 'file', name: 'file'),
            path('users/<str:name>/', 'user', name: 'user'),
        ]);

        self::assertSame('/posts/5/', $router->reverse('detail', ['id' => 5]));
        self::assertSame('/posts/5/?page=2&q=a+b', $router->reverse('detail', ['id' => 5], ['page' => 2, 'q' => 'a b']));
        self::assertSame('/files/a%20b/c.txt', $router->reverse('file', ['file' => 'a b/c.txt']));
        self::assertSame('/users/ada%2Fx/', $router->reverse('user', ['name' => 'ada/x']));
        self::assertTrue($router->has('detail'));
        self::assertFalse($router->has('nope'));
    }

    public function testReverseErrors(): void
    {
        $router = new Router([path('posts/<int:id>/', 'detail', name: 'post-detail')]);

        foreach ([
            fn () => $router->reverse('post-detail'),
            fn () => $router->reverse('post-detail', ['id' => 1, 'extra' => 2]),
            fn () => $router->reverse('post-detail', ['id' => 'abc']),
        ] as $call) {
            try {
                $call();
                self::fail('Expected NoReverseMatch');
            } catch (NoReverseMatch) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectExceptionMessage('Did you mean "post-detail"?');
        $router->reverse('post-detial');
    }

    public function testIncludesAndNamespaces(): void
    {
        $blog = include_urls([
            path('', 'index', name: 'index'),
            path('posts/<int:id>/', 'detail', name: 'detail', middleware: ['Inner']),
            path('admin/', include_urls([path('', 'admin-home', name: 'home')], namespace: 'admin')),
        ], namespace: 'blog');

        $router = new Router([path('blog/', $blog, middleware: ['Outer'])]);

        self::assertSame('/blog/', $router->reverse('blog:index'));
        self::assertSame('/blog/posts/3/', $router->reverse('blog:detail', ['id' => 3]));
        self::assertSame('/blog/admin/', $router->reverse('blog:admin:home'));

        $match = $router->resolve('GET', '/blog/posts/3/');
        self::assertSame('blog:detail', $match->name());
        self::assertSame(['Outer', 'Inner'], $match->route->middleware);
        self::assertSame('blog/posts/<int:id>/', $match->route->pattern());
    }

    public function testIncludeFromFile(): void
    {
        $file = $this->tempDir() . '/urls.php';
        file_put_contents($file, "<?php return [path('x/', 'x', name: 'x')];");
        $router = new Router([path('inc/', include_urls($file, namespace: 'inc'))]);

        self::assertSame('/inc/x/', $router->reverse('inc:x'));
    }

    public function testIncludeMissingFileFails(): void
    {
        $this->expectException(ImproperlyConfigured::class);
        include_urls('/no/such/urls.php');
    }

    public function testRegexIncludePrefix(): void
    {
        $router = new Router([re_path('^v(?P<version>[0-9])/', include_urls([path('items/', 'items', name: 'items')], namespace: 'api'))]);

        self::assertSame(['version' => '2'], $router->resolve('GET', '/v2/items/')->parameters);
        self::assertSame('/v2/items/', $router->reverse('api:items', ['version' => 2]));
    }

    public function testGroups(): void
    {
        $router = new Router([
            group('api/', [
                path('status/', 'status', name: 'status'),
                group('v1/', [path('ping/', 'ping', name: 'ping')], middleware: ['V1']),
            ], middleware: ['Api'], namespace: 'api'),
        ]);

        self::assertSame('/api/v1/ping/', $router->reverse('api:ping'));
        self::assertSame(['Api', 'V1'], $router->resolve('GET', '/api/v1/ping/')->route->middleware);
        self::assertSame(['Api'], $router->resolve('GET', '/api/status/')->route->middleware);
    }

    public function testNestedArraysAreFlattened(): void
    {
        $router = new Router([[path('a/', 'a')], [[path('b/', 'b')]]]);

        self::assertCount(2, $router->routes());
    }

    public function testInvalidEntryFails(): void
    {
        $this->expectException(ImproperlyConfigured::class);
        new Router(['not a route']);
    }

    public function testCustomConverter(): void
    {
        Router::registerConverter('year', new Converter('[0-9]{4}', fn (string $v): int => (int) $v, fn (mixed $v): string => sprintf('%04d', $v)));
        $router = new Router([path('archive/<year:y>/', 'archive', name: 'archive')]);

        self::assertSame(['y' => 2024], $router->resolve('GET', '/archive/2024/')->parameters);
        self::assertSame('/archive/0999/', $router->reverse('archive', ['y' => 999]));
    }

    public function testInvalidConverterName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Router::registerConverter('bad-name', new Converter('x'));
    }

    public function testUnknownConverterFails(): void
    {
        $router = new Router([path('a/<money:amount>/', 'a')]);
        $this->expectException(ImproperlyConfigured::class);
        $this->expectExceptionMessage('Unknown path converter "money"');
        $router->resolve('GET', '/a/1/');
    }

    public function testDuplicateParameterFails(): void
    {
        $router = new Router([path('<int:id>/<int:id>/', 'a')]);
        $this->expectException(ImproperlyConfigured::class);
        $router->resolve('GET', '/1/2/');
    }

    public function testUnclosedParameterFails(): void
    {
        $router = new Router([path('<int:id/', 'a')]);
        $this->expectException(ImproperlyConfigured::class);
        $router->resolve('GET', '/1/');
    }

    public function testConverterRejectsArrays(): void
    {
        $router = new Router([path('<str:x>/', 'a', name: 'a')]);
        $this->expectException(\InvalidArgumentException::class);
        $router->reverse('a', ['x' => ['array']]);
    }

    public function testAttributeRoutes(): void
    {
        $router = new Router([...routes_from(AttributeController::class, PlainController::class)]);

        self::assertSame('/api/posts/', $router->reverse('posts:list'));
        self::assertSame('/api/posts/7/alias/', $router->reverse('posts:detail-alias', ['id' => 7]));
        self::assertSame('/plain/', $router->reverse('plain'));

        $match = $router->resolve('GET', '/api/posts/7/');
        self::assertSame([AttributeController::class, 'detail'], $match->route->view);
        self::assertSame(['ApiMiddleware'], $match->route->middleware);
        self::assertSame(['GET'], $router->resolve('GET', '/api/posts/')->route->methods);
        self::assertCount(4, $router->routes());
    }

    protected function tearDown(): void
    {
        $property = new \ReflectionProperty(Router::class, 'customConverters');
        $property->setValue(null, []);
        parent::tearDown();
    }
}
