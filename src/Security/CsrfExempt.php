<?php

declare(strict_types=1);

namespace BrewPHP\Security;

use BrewPHP\Views\View;

/**
 * Turns off CSRF protection for one view, e.g. a webhook that other
 * servers call with their own signature:
 *
 *     #[CsrfExempt]
 *     function stripe_webhook(Request $request): Response { ... }
 *
 * Works on functions, controller methods (or their class) and View
 * classes. Only exempt views that check the caller another way.
 */
#[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class CsrfExempt
{
    /**
     * Whether a route view carries the attribute.
     */
    public static function appliesTo(mixed $view): bool
    {
        try {
            $reflections = match (true) {
                $view instanceof \Closure, is_string($view) && function_exists($view) => [new \ReflectionFunction($view)],
                is_string($view) && is_subclass_of($view, View::class), $view instanceof View => [new \ReflectionClass($view)],
                is_array($view) && count($view) === 2 && ((is_string($view[0]) && class_exists($view[0])) || is_object($view[0])) && is_string($view[1]) => [
                    new \ReflectionClass($view[0]),
                    new \ReflectionMethod($view[0], $view[1]),
                ],
                is_object($view) && method_exists($view, '__invoke') => [new \ReflectionClass($view), new \ReflectionMethod($view, '__invoke')],
                default => [],
            };
        } catch (\ReflectionException) {
            return false;
        }
        foreach ($reflections as $reflection) {
            if ($reflection->getAttributes(self::class) !== []) {
                return true;
            }
        }

        return false;
    }
}
