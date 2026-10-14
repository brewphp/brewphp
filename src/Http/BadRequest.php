<?php

declare(strict_types=1);

namespace BrewPHP\Http;

/**
 * The request is malformed or suspicious (400 Bad Request).
 */
class BadRequest extends HttpException
{
    public function __construct(string $message = 'Bad request', ?\Throwable $previous = null)
    {
        parent::__construct(400, $message, [], $previous);
    }
}
