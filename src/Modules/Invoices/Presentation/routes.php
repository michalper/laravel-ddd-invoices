<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Invoices\Presentation\Http\Controllers\CreateInvoiceController;
use Modules\Invoices\Presentation\Http\Controllers\SendInvoiceController;
use Modules\Invoices\Presentation\Http\Controllers\ViewInvoiceController;

// The parameter is deliberately not named `reference` or `action`: the Notifications
// routes file registers those names with Route::pattern(), which is router-global,
// so a same-named parameter here would silently inherit that module's constraint.
// A route-scoped whereUuid() keeps the constraint local and gives a 404 for a
// malformed id before any controller runs.
Route::post('/invoices', CreateInvoiceController::class)
    ->name('invoices.create');

Route::get('/invoices/{invoiceId}', ViewInvoiceController::class)
    ->whereUuid('invoiceId')
    ->name('invoices.view');

Route::post('/invoices/{invoiceId}/send', SendInvoiceController::class)
    ->whereUuid('invoiceId')
    ->name('invoices.send');
