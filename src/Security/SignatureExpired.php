<?php

declare(strict_types=1);

namespace BrewPHP\Security;

/**
 * A timestamped signature is valid but older than the allowed age.
 */
final class SignatureExpired extends BadSignature
{
}
