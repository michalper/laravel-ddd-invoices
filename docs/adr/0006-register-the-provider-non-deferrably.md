# ADR 0006: Register the Invoices provider non-deferrably, and load its routes from routes/api.php

## Status

Accepted.

## Context

`NotificationServiceProvider`, which came with the task, implements `DeferrableProvider`. Copying
that shape for `InvoiceServiceProvider` looks like consistency and is a correctness bug.

A deferred provider is loaded only when something resolves one of the abstracts it promises in
`provides()`. A delivery webhook request resolves nothing from this module, because it enters
through the Notifications controller and dispatches `WebhookDeliveredEvent`. The
`Event::listen()` call in `boot()` would therefore never run, and deliveries would fail silently
while the endpoint still answered 204 — the worst possible failure shape, since nothing is
visibly broken.

Event discovery is not an option either: `bootstrap/app.php` never calls `->withEvents()`, so the
provider that performs discovery is never registered, and even with it Laravel scans
`app_path('Listeners')` rather than `src/Modules/**`.

## Decision

`InvoiceServiceProvider` does not implement `DeferrableProvider`. The listener is registered
explicitly with `Event::listen()` in `boot()`.

The Notifications provider can stay deferrable precisely because its bindings are always pulled
on demand — nothing depends on its `boot()` having run.

Routes are loaded by `require`-ing `src/Modules/Invoices/Presentation/routes.php` from
`routes/api.php`, Invoices first, following the existing convention. Registering them from the
provider would bypass `withRouting(api: ...)` and mean rebuilding the `api` prefix and middleware
group by hand.

## Consequences

- The provider is loaded on every request, including ones that touch no invoice. The cost is a
  handful of container bindings, which is the right trade against a silently broken webhook.
- The load order in `routes/api.php` is load-bearing for a second reason: the Notifications
  routes file calls `Route::pattern('reference', ...)` and `Route::pattern('action', ...)`, which
  are router-global and applied at route-registration time. A same-named parameter in this module
  would silently inherit that constraint, which is why the parameter is named `invoiceId`.
- `DeliveryWebhookTest` has a test that dispatches the event through the real dispatcher. It
  exists so that breaking this decision fails with "the listener is not registered" rather than
  as a confusing 204.
