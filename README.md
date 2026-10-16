# BrewPHP

[![CI](https://github.com/brewphp/brewphp/actions/workflows/ci.yml/badge.svg)](https://github.com/brewphp/brewphp/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/brewphp/brewphp?color=6F4E37)](https://packagist.org/packages/brewphp/brewphp)
[![PHP 8.2+](https://img.shields.io/badge/php-8.2%2B-777BB4)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-C8A27A)](LICENSE)

A small, Django-inspired PHP framework: easy to learn, a readable core, and
zero runtime dependencies.

BrewPHP brings Django's ideas (apps, URLconfs, views, an ORM, templates,
`manage.php`, migrations, auth and forms, and an automatic admin) to modern PHP 8.2+.

> **Status:** stable. BrewPHP 1.0 includes routing, views and middleware;
> an ORM for SQLite, MySQL/MariaDB and PostgreSQL; a Django-like template
> language; migrations; sessions, CSRF protection, users, permissions and
> forms; and an automatic admin. It follows semantic versioning: no
> breaking changes before 2.0. See the [changelog](CHANGELOG.md).

Add it to an existing project with `composer require brewphp/brewphp`, or
start a new one from the skeleton below.

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

```php
<?php
// apps/blog/models.php

declare(strict_types=1);

namespace Apps\Blog;

use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Model;

final class Post extends Model
{
    public static function fields(): array
    {
        return [
            'title' => new CharField(maxLength: 200),
            'author' => new ForeignKey(Author::class, relatedName: 'posts'),
        ];
    }
}
```

```php
$posts = Post::objects()
    ->filter(title__icontains: 'php', author__name: 'Ann')
    ->orderBy('-id')
    ->paginate(10, $request->query('page'));

$post = get_object_or_404(Post::class, id: $id);

return render('blog/post_list.html', ['posts' => $posts]);
```

```html+django
{% extends "base.html" %}

{% block content %}
    {% for post in posts %}
        <h2><a href="{% url 'blog:post-detail' post.id %}">{{ post.title }}</a></h2>
        <p>{{ post.author.name }} · {{ post.created_at|date:"M j, Y" }}</p>
    {% empty %}
        <p>No posts yet.</p>
    {% endfor %}
{% endblock %}
```

```php
<?php
// apps/blog/admin.php: manage posts at /admin/

#[Register(Post::class)]
final class PostAdmin extends ModelAdmin
{
    public array $listDisplay = ['title', 'author', 'published_at'];
    public array $listFilter = ['author'];
    public array $searchFields = ['title', 'author__name'];
}
```

```bash
php manage.php createsuperuser # a user who can log in to the admin
php manage.php startapp blog   # create apps/blog/
php manage.php makemigrations  # write migrations for model changes
php manage.php migrate         # apply them
php manage.php routes          # list every URL pattern
php manage.php check           # look for configuration problems
```

## Documentation

Everything is documented at **<https://docs.brewphp.dev>**.

## Releases

| Version | Scope | Status |
|---|---|---|
| 0.1 | Router, views, request/response, settings, CLI | Done |
| 0.2 | ORM (SQLite, MySQL, PostgreSQL) | Done |
| 0.3 | Templates | Done |
| 0.4 | Migrations, `startapp` | Done |
| 0.5 | Auth, sessions, CSRF, forms | Done |
| 0.6 | Automatic admin | Done |
| **1.0** | **First stable release** | **Released** |

Ideas for 1.x (without breaking changes): many-to-many fields, a custom user
model setting, password reset by email, and admin inlines.

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
