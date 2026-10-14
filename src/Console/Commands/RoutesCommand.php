<?php

declare(strict_types=1);

namespace BrewPHP\Console\Commands;

use BrewPHP\Console\Command;
use BrewPHP\Views\ViewResolver;

final class RoutesCommand extends Command
{
    public string $name = 'routes';

    public string $description = 'List every URL pattern with its name, view and middleware';

    protected function configure(): void
    {
        $this->addOption('filter', 'Only show routes whose pattern or name contains this text', value: true);
        $this->addOption('json', 'Print the routes as JSON');
    }

    public function handle(): int
    {
        $filter = $this->option('filter');
        $rows = [];
        foreach ($this->app->router()->routes() as $route) {
            $row = [
                'methods' => $route->methods === [] ? 'ANY' : implode('|', $route->methods),
                'pattern' => '/' . $route->pattern(),
                'name' => $route->name ?? '',
                'view' => ViewResolver::describe($route->view),
                'middleware' => implode(', ', array_map(
                    static fn (mixed $m): string => is_string($m) ? self::shortName($m) : get_debug_type($m),
                    $route->middleware,
                )),
            ];
            if (is_string($filter) && !str_contains($row['pattern'], $filter) && !str_contains($row['name'], $filter)) {
                continue;
            }
            $rows[] = $row;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if ($rows === []) {
            $this->warn('No routes found.');

            return self::SUCCESS;
        }

        $this->table(['Methods', 'Pattern', 'Name', 'View', 'Middleware'], array_map('array_values', $rows));
        $this->comment(sprintf('%d route%s', count($rows), count($rows) === 1 ? '' : 's'));

        return self::SUCCESS;
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
