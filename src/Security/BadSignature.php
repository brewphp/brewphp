<?php

declare(strict_types=1);

namespace BrewPHP\Security;

/**
 * A signed value was changed, or signed with another key or salt.
 */
class BadSignature extends \RuntimeException
{
}
