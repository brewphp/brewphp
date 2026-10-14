<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Views;

use BrewPHP\Core\Container;
use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Http\JsonResponse;
use BrewPHP\Http\MethodNotAllowed;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use BrewPHP\Tests\TestCase;
use BrewPHP\Views\View;
use BrewPHP\Views\ViewResolver;

final class Formatter
{
    public function format(string $value): string
    {
        return '[' . $value . ']';
    }
}

final class PostController
{
    public function __construct(private readonly Formatter $formatter)
    {
    }

    public function detail(Request $request, int $id): string
    {
        return $this->formatter->format($request->method() . ' ' . $id);
    }

    public static function index(): array
    {
        return ['posts' => []];
    }
}

final class ContactView extends View
{
    public string $title = 'Contact';

    public function get(Formatter $formatter): string
    {
        return $formatter->format($this->title . ' ' . ($this->params['lang'] ?? '-'));
    }

    public function post(Request $request): Response
    {
        return new Response('sent ' . $request->post('msg'), 201);
    }
}

final class AuditedView extends View
{
    /** @var list<string> */
    public static array $log = [];

    public function dispatch(Request $request, \Closure $call): mixed
    {
        self::$log[] = 'before';
        $result = parent::dispatch($request, $call);
        self::$log[] = 'after';

        return $result;
    }

    public function get(): string
    {
        return 'audited';
    }
}

final class NeedsArgumentView extends View
{
    public function __construct(public string $required)
    {
    }

    public function get(): string
    {
        return $this->required;
    }
}

final class Stringy implements \Stringable
{
    public function __toString(): string
    {
        return 'stringy';
    }
}

function plain_view(): string
{
    return 'function view';
}

final class ViewResolverTest extends TestCase
{
    private ViewResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ViewResolver(new Container());
    }

    public function testClosureWithParameters(): void
    {
        $response = $this->resolver->call(
            fn (Request $request, int $id, string $slug = 'none'): string => "{$request->path()} $id $slug",
            Request::create('GET', '/x/'),
            ['id' => '5'],
        );

        self::assertSame('/x/ 5 none', $response->content());
    }

    public function testFunctionName(): void
    {
        $response = $this->resolver->call(__NAMESPACE__ . '\plain_view', Request::create('GET', '/'));

        self::assertSame('function view', $response->content());
    }

    public function testControllerMethodGetsDependencies(): void
    {
        $response = $this->resolver->call([PostController::class, 'detail'], Request::create('GET', '/'), ['id' => 3]);

        self::assertSame('[GET 3]', $response->content());
    }

    public function testStaticMethodAndArrayReturn(): void
    {
        $response = $this->resolver->call([PostController::class, 'index'], Request::create('GET', '/'));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame('{"posts":[]}', $response->content());
    }

    public function testMissingControllerMethod(): void
    {
        $this->expectException(ImproperlyConfigured::class);
        $this->resolver->call([PostController::class, 'missing'], Request::create('GET', '/'));
    }

    public function testNotCallable(): void
    {
        $this->expectException(ImproperlyConfigured::class);
        $this->resolver->call(42, Request::create('GET', '/'));
    }

    public function testClassBasedView(): void
    {
        $get = $this->resolver->call(ContactView::class, Request::create('GET', '/'), ['lang' => 'en']);
        $post = $this->resolver->call(ContactView::class, Request::create('POST', '/', ['msg' => 'hi']));
        $head = $this->resolver->call(ContactView::class, Request::create('HEAD', '/'));

        self::assertSame('[Contact en]', $get->content());
        self::assertSame(201, $post->status());
        self::assertSame('sent hi', $post->content());
        self::assertSame('[Contact -]', $head->content());
    }

    public function testAsViewConfiguresAndCopiesPerRequest(): void
    {
        $view = ContactView::asView(['title' => 'Support']);

        self::assertSame('[Support -]', $this->resolver->call($view, Request::create('GET', '/'))->content());
        self::assertSame('Support', $view->title);
    }

    public function testAsViewRejectsUnknownProperty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContactView::asView(['nope' => 1]);
    }

    public function testAsViewRejectsRequiredConstructor(): void
    {
        $this->expectException(\LogicException::class);
        NeedsArgumentView::asView();
    }

    public function testUnsupportedMethodGives405(): void
    {
        try {
            $this->resolver->call(ContactView::class, Request::create('DELETE', '/'));
            self::fail('Expected MethodNotAllowed');
        } catch (MethodNotAllowed $e) {
            self::assertSame(['GET', 'POST', 'OPTIONS', 'HEAD'], $e->allowed());
        }
    }

    public function testOptionsListsAllowedMethods(): void
    {
        $response = $this->resolver->call(ContactView::class, Request::create('OPTIONS', '/'));

        self::assertSame(204, $response->status());
        self::assertSame('GET, POST, OPTIONS, HEAD', $response->getHeader('Allow'));
    }

    public function testDispatchCanBeOverridden(): void
    {
        AuditedView::$log = [];
        $this->resolver->call(AuditedView::class, Request::create('GET', '/'));

        self::assertSame(['before', 'after'], AuditedView::$log);
    }

    public function testReturnValueConversion(): void
    {
        self::assertSame('stringy', ViewResolver::toResponse(new Stringy())->content());
        $serializable = new class () implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['a' => 1];
            }
        };
        self::assertSame('{"a":1}', ViewResolver::toResponse($serializable)->content());

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('returned null');
        ViewResolver::toResponse(null, 'my_view');
    }

    public function testDescribe(): void
    {
        self::assertSame(PostController::class . '::detail', ViewResolver::describe([PostController::class, 'detail']));
        self::assertSame(ContactView::class, ViewResolver::describe(ContactView::asView()));
        self::assertSame('home', ViewResolver::describe('home'));
        self::assertStringStartsWith('Closure (ViewResolverTest.php:', ViewResolver::describe(fn () => 1));
        self::assertSame(__NAMESPACE__ . '\plain_view', ViewResolver::describe(plain_view(...)));
        self::assertSame('int', ViewResolver::describe(5));
    }
}
