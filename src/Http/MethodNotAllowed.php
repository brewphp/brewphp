<?php

declare(strict_types=1);

namespace BrewPHP\Http;

/**
 * The URL exists but does not accept this HTTP method (405).
 */
class MethodNotAllowed extends HttpException
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(private readonly array $allowed, string $message = 'Method not allowed')
    {
        parent::__construct(405, $message, ['Allow' => implode(', ', $allowed)]);
    }

    /**
     * @return list<string>
     */
    public function allowed(): array
    {
        return $this->allowed;
    }
}
