# BrewPHP

A small, Django-inspired PHP framework: easy to learn, a readable core, and
zero runtime dependencies.

BrewPHP brings Django's ideas (apps, URLconfs, views, `manage.php`, and later
an ORM, migrations and an automatic admin) to modern PHP 8.2+.

> **Status:** early development. Version 0.1 provides the foundation:
> routing, views, requests and responses, settings, middleware, error pages
> and the `manage.php` command line. See the [roadmap](#roadmap).

## Quick start

Start a new project from the skeleton:

```bash
composer create-project brewphp/skeleton myapp
cd myapp
php manage.php serve
```

Open <http://127.0.0.1:8000/>.

## A taste

```php
<?php
// config/urls.php

declare(strict_types=1);

use BrewPHP\Http\Request;

return [
    path('', fn (Request $request) => 'Hello, world!', name: 'home'),
    path('posts/<int:id>/', [PostController::class, 'detail'], name: 'post-detail'),
    path('blog/', include_urls('apps/blog/urls.php', namespace: 'blog')),
];
```

```php
reverse('post-detail', ['id' => 5]); // "/posts/5/"
```

```bash
php manage.php routes   # list every URL pattern
php manage.php check    # look for configuration problems
```

## Documentation

Everything is documented at **<https://docs.brewphp.dev>**.

## Roadmap

| Version | Scope |
|---|---|
| 0.1 | Router, views, request/response, settings, CLI |
| 0.2 | ORM (SQLite, MySQL, PostgreSQL) |
| 0.3 | Templates |
| 0.4 | Migrations, `startapp` |
| 0.5 | Auth, sessions, CSRF, forms |
| 0.6 | Automatic admin |

## Contributing

Run the checks before opening a pull request:

```bash
composer install
composer test    # PHPUnit
composer stan    # PHPStan, level 8
composer cs      # PHP-CS-Fixer (composer cs:fix to apply)
```

Read the [contributing guide](https://github.com/brewphp/.github/blob/main/CONTRIBUTING.md)
for the project rules. Report security problems privately as described in
[SECURITY.md](SECURITY.md).

## License

BrewPHP is open-source software licensed under the [MIT license](LICENSE).
