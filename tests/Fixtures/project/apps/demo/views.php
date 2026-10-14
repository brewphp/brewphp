<?php

declare(strict_types=1);

namespace Apps\Demo;

use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use BrewPHP\Views\View;

function home(Request $request): Response
{
    return render('demo/hello.php', ['name' => 'world']);
}

final class GreetingController
{
    public function hello(Request $request, string $name): string
    {
        return 'Hello, ' . e($name) . '!';
    }
}

final class ArticleView extends View
{
    public string $prefix = 'Article';

    public function get(int $id): string
    {
        return sprintf('%s %d', $this->prefix, $id);
    }

    public function delete(int $id): Response
    {
        return new Response('', 204);
    }
}
