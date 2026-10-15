<?php

declare(strict_types=1);

namespace BrewPHP\Auth;

use BrewPHP\Auth\Views\LoginView;
use BrewPHP\Auth\Views\LogoutView;
use BrewPHP\Auth\Views\PasswordChangeDoneView;
use BrewPHP\Auth\Views\PasswordChangeView;
use BrewPHP\Routing\Route;

/**
 * The login, logout and password change pages, to include in your URLconf:
 *
 *     path('accounts/', include_urls(\BrewPHP\Auth\Urls::patterns())),
 *
 * Route names: login, logout, password_change, password_change_done.
 */
final class Urls
{
    /**
     * @return list<Route>
     */
    public static function patterns(): array
    {
        return [
            Route::path('login/', LoginView::class, name: 'login'),
            Route::path('logout/', LogoutView::class, name: 'logout'),
            Route::path('password_change/', PasswordChangeView::class, name: 'password_change'),
            Route::path('password_change/done/', PasswordChangeDoneView::class, name: 'password_change_done'),
        ];
    }
}
