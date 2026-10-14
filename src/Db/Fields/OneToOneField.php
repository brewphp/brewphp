<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Db\OnDelete;

/**
 * A one-to-one relation: a ForeignKey with a unique constraint. The reverse
 * accessor returns one object instead of a QuerySet:
 *
 *     'user' => new OneToOneField(User::class, relatedName: 'profile'),
 *     $user->profile;  // the Profile, or ObjectDoesNotExist
 */
class OneToOneField extends ForeignKey
{
    public function __construct(
        string $to,
        OnDelete $onDelete = OnDelete::Cascade,
        ?string $relatedName = null,
        bool $unique = true,
        mixed ...$options,
    ) {
        parent::__construct($to, $onDelete, $relatedName, ...$options, unique: $unique);
    }

    public function relatedAccessorName(): string
    {
        return $this->relatedName ?? $this->relatedQueryName();
    }
}
