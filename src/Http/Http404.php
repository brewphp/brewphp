<?php

declare(strict_types=1);

namespace BrewPHP\Http;

/**
 * The requested page does not exist (404 Not Found).
 */
class Http404 extends HttpException
{
    public function __construct(string $message = 'Page not found', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, [], $previous);
    }
}
