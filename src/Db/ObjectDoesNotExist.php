<?php

declare(strict_types=1);

namespace BrewPHP\Db;

/**
 * `get()` found no object, like Django's `Model.DoesNotExist`.
 * `$e->model` is the class that was queried.
 */
class ObjectDoesNotExist extends \RuntimeException
{
    /**
     * @param class-string<Model>|string $model
     */
    public function __construct(public readonly string $model, string $message = '')
    {
        parent::__construct($message !== '' ? $message : sprintf('%s matching query does not exist.', self::shortName($model)));
    }

    private static function shortName(string $class): string
    {
        return substr((string) strrchr('\\' . $class, '\\'), 1);
    }
}
