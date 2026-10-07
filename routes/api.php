<?php

declare(strict_types=1);

// Invoices first: the Notifications file registers router-global Route::pattern()
// constraints, and loading it last keeps them from reaching these routes.
require __DIR__.'/../src/Modules/Invoices/Presentation/routes.php';
require __DIR__.'/../src/Modules/Notifications/Presentation/routes.php';
