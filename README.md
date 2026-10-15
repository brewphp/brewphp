# BrewPHP

A small, Django-inspired PHP framework: easy to learn, a readable core, and
zero runtime dependencies.

BrewPHP brings Django's ideas (apps, URLconfs, views, an ORM, templates,
`manage.php`, migrations, auth and forms, and soon an automatic admin) to modern PHP 8.2+.

> **Status:** early development. Version 0.1 provides the foundation:
> routing, views, requests and responses, settings, middleware, error pages
> and the `manage.php` command line. Version 0.2 adds the ORM: models,
> fields, relations, QuerySets and a schema editor for SQLite, MySQL and
> PostgreSQL. Version 0.3 adds the Django-like template language, compiled
> to PHP, with inheritance, auto-escaping and custom tags and filters.
> Version 0.4 adds migrations (`makemigrations`, `migrate`) and `startapp`.
> Version 0.5 adds sessions, CSRF protection, users and permissions,
> login views and forms.
> See the [roadmap](#roadmap).

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

```bash
php manage.php startapp blog   # create apps/blog/
php manage.php makemigrations  # write migrations for model changes
php manage.php migrate         # apply them
php manage.php routes          # list every URL pattern
php manage.php check           # look for configuration problems
```

## Documentation

Everything is documented at **<https://docs.brewphp.dev>**.

## Roadmap

| Version | Scope | Status |
|---|---|---|
| 0.1 | Router, views, request/response, settings, CLI | Done |
| 0.2 | ORM (SQLite, MySQL, PostgreSQL) | Done |
| 0.3 | Templates | Done |
| 0.4 | Migrations, `startapp` | Done |
| 0.5 | Auth, sessions, CSRF, forms | Done |
| 0.6 | Automatic admin | Planned |

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
