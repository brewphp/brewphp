<?php

declare(strict_types=1);

namespace BrewPHP\Core;

/**
 * Marks a method that changes data, such as `Model::save()` or
 * `QuerySet::delete()`. Templates call zero-argument methods for
 * `{{ post.method }}`, but never one marked with this attribute (or one
 * that overrides a method marked with it), so a template cannot delete
 * rows by accident.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class AltersData
{
}
