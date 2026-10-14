<?php

declare(strict_types=1);

namespace BrewPHP\Http;

/**
 * A response whose body is JSON.
 *
 *     return new JsonResponse(['ok' => true], 201);
 */
class JsonResponse extends Response
{
    public const DEFAULT_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private mixed $data = null,
        int $status = 200,
        array $headers = [],
        int $flags = self::DEFAULT_FLAGS,
    ) {
        parent::__construct(json_encode($data, $flags | JSON_THROW_ON_ERROR), $status, ['Content-Type' => 'application/json']);
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }
    }

    public function data(): mixed
    {
        return $this->data;
    }
}
