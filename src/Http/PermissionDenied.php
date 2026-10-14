<?php

declare(strict_types=1);

namespace BrewPHP\Http;

/**
 * The user is not allowed to do this (403 Forbidden).
 */
class PermissionDenied extends HttpException
{
    public function __construct(string $message = 'Permission denied', ?\Throwable $previous = null)
    {
        parent::__construct(403, $message, [], $previous);
    }
}
