<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

/**
 * The template source is invalid: an unknown tag or filter, an unclosed
 * block, a bad expression... Raised when the template is compiled.
 */
final class TemplateSyntaxError extends TemplateError
{
}
