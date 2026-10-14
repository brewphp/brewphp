<?php

declare(strict_types=1);

namespace BrewPHP\Db;

/**
 * What happens to objects that point to a deleted object through a
 * `ForeignKey`. BrewPHP applies it in PHP, so it works the same on every
 * database (see `Collector`).
 */
enum OnDelete
{
    /** Delete the objects that point to it too. */
    case Cascade;

    /** Refuse the deletion with a ProtectedError. */
    case Protect;

    /** Same as Protect in BrewPHP. */
    case Restrict;

    /** Set the foreign key to NULL (the field must have `null: true`). */
    case SetNull;

    /** Set the foreign key to the field's default. */
    case SetDefault;

    /** Do nothing; the database constraint decides. */
    case DoNothing;
}
