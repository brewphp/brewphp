<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

use BrewPHP\Auth\Authenticatable;
use BrewPHP\Auth\PermWrapper;
use BrewPHP\Http\Request;
use BrewPHP\Security\Csrf;
use BrewPHP\Sessions\Messages;

/**
 * Built-in context processors. A context processor is any callable that
 * receives the Request and returns variables for every template rendered
 * with `render()`. List them in TEMPLATES.CONTEXT_PROCESSORS.
 */
final class ContextProcessors
{
    /**
     * Adds `request`, so templates can use `{{ request.path }}`.
     *
     * @return array{request: Request}
     */
    public static function request(Request $request): array
    {
        return ['request' => $request];
    }

    /**
     * Adds `user` (the logged-in User or an AnonymousUser) and `perms`
     * (`{% if perms.blog.add_post %}`). Needs AuthenticationMiddleware;
     * the user is only loaded when a template uses it.
     *
     * @return array{user: Authenticatable, perms: PermWrapper}|array{}
     */
    public static function auth(Request $request): array
    {
        if ($request->attribute('user') === null && $request->attribute('_user_resolver') === null) {
            return [];
        }
        $user = $request->user();

        return ['user' => $user, 'perms' => new PermWrapper($user)];
    }

    /**
     * Adds `messages`, the flash messages of the session (removed once a
     * template loops over them).
     *
     * @return array{messages: Messages}|array{}
     */
    public static function messages(Request $request): array
    {
        return $request->hasSession() ? ['messages' => new Messages($request)] : [];
    }

    /**
     * Adds `csrf_token` for `{% csrf_token %}`. The token is only created
     * (and its cookie only set) when a template prints it.
     *
     * @return array{csrf_token: \Stringable}
     */
    public static function csrf(Request $request): array
    {
        return ['csrf_token' => new class ($request) implements \Stringable {
            public function __construct(private readonly Request $request)
            {
            }

            public function __toString(): string
            {
                return Csrf::token($this->request);
            }
        }];
    }
}
