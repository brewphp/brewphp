<?php

declare(strict_types=1);

namespace BrewPHP\Db;

/**
 * Deleting an object was refused because other objects point to it through
 * a foreign key with `onDelete: OnDelete::Protect` (or `Restrict`).
 */
final class ProtectedError extends \RuntimeException
{
    /**
     * @param list<Model> $protectedObjects the objects that block the deletion
     */
    public function __construct(string $message, public readonly array $protectedObjects)
    {
        parent::__construct($message);
    }
}
