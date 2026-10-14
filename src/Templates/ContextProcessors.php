<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

use BrewPHP\Http\Request;

/**
 * Built-in context processors. A context processor is any callable that
 * receives the Request and returns variables for every template rendered
 * with `render()`. List them in TEMPLATES.CONTEXT_PROCESSORS.
 */
final class ContextProcessors
{
    /**
     * Adds `request`, so templates can use `{{ request.path }}`.
     *
     * @return array{request: Request}
     */
    public static function request(Request $request): array
    {
        return ['request' => $request];
    }
}
