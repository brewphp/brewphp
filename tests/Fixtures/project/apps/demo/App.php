<?php

declare(strict_types=1);

namespace Apps\Demo;

use BrewPHP\Core\AppConfig;
use BrewPHP\Core\Application;
use BrewPHP\Templates\Compiler;
use BrewPHP\Templates\Context;
use BrewPHP\Templates\Library;
use BrewPHP\Templates\Nodes\Node;
use BrewPHP\Templates\Nodes\TagNode;
use BrewPHP\Templates\Parser;
use BrewPHP\Templates\Token;

final class App extends AppConfig
{
    public string $verboseName = 'Demo app';

    public static bool $ready = false;

    public function ready(Application $app): void
    {
        self::$ready = true;

        $app->templates()->library(
            (new Library())
                ->filter('shout', static fn (mixed $value): string => strtoupper((string) $value) . '!')
                ->simpleTag('greet', static fn (string $name, string $greeting = 'Hello'): string => $greeting . ', ' . $name)
                ->simpleTag('current_path', static fn (Context $context): string => (string) $context->get('request')?->path(), takesContext: true)
                ->tag('repeat', self::repeat(...)),
        );
    }

    /**
     * `{% repeat 3 %}...{% endrepeat %}`: a tag with an end tag.
     */
    public static function repeat(Parser $parser, Token $token): Node
    {
        $times = $parser->compileExpression($token->tagArguments());
        $body = $parser->parse(['endrepeat'], $token);
        $parser->nextToken();

        return new TagNode(static function (Compiler $compiler) use ($times, $body): void {
            $i = $compiler->variable('i');
            $compiler->write(sprintf('for (%s = 0; %s < (int) %s; %s++) {', $i, $i, $times, $i))
                ->indent()
                ->subcompile($body)
                ->outdent()
                ->write('}');
        });
    }
}
