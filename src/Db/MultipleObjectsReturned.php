<?php

declare(strict_types=1);

namespace BrewPHP\Db;

/**
 * `get()` expected one object but found several.
 */
class MultipleObjectsReturned extends \RuntimeException
{
    /**
     * @param class-string<Model>|string $model
     */
    public function __construct(public readonly string $model, int $count)
    {
        $name = substr((string) strrchr('\\' . $model, '\\'), 1);
        parent::__construct(sprintf('get() returned more than one %s -- it returned %s!', $name, $count > 20 ? 'more than 20' : (string) $count));
    }
}
