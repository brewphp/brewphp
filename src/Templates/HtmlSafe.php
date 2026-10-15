<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

/**
 * An object whose string form is already safe HTML, so auto-escaping and
 * `e()` print it as is. SafeString implements it, and so do form objects
 * (`{{ form }}`, `{{ form.title }}`), which escape every value they print.
 *
 * Only implement it on classes that build their HTML from escaped values.
 */
interface HtmlSafe extends \Stringable
{
}
