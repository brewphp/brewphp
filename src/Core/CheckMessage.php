<?php

declare(strict_types=1);

namespace BrewPHP\Core;

/**
 * One finding of the system check framework (`manage.php check`).
 */
final class CheckMessage
{
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const INFO = 'info';

    /**
     * @param self::ERROR|self::WARNING|self::INFO $level
     * @param string $id stable identifier such as "security.W004"
     */
    public function __construct(
        public readonly string $level,
        public readonly string $id,
        public readonly string $message,
        public readonly ?string $hint = null,
    ) {
    }

    public static function error(string $id, string $message, ?string $hint = null): self
    {
        return new self(self::ERROR, $id, $message, $hint);
    }

    public static function warning(string $id, string $message, ?string $hint = null): self
    {
        return new self(self::WARNING, $id, $message, $hint);
    }

    public function isError(): bool
    {
        return $this->level === self::ERROR;
    }
}
