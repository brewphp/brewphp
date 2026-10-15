<?php

declare(strict_types=1);

use BrewPHP\Admin\AdminSite;

// The URLconf of the admin tests.
return [
    path('admin/', include_urls(AdminSite::default()->urls(), namespace: 'admin')),
    path('', static fn (): string => 'home', name: 'home'),
];
