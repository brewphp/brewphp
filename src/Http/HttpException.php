<?php

declare(strict_types=1);

namespace BrewPHP\Http;

/**
 * Throw from anywhere to stop and answer with an HTTP error page.
 *
 *     throw new HttpException(410, 'This post was removed.');
 *
 * The subclasses Http404, PermissionDenied, BadRequest and
 * MethodNotAllowed cover the common cases.
 */
class HttpException extends \RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
