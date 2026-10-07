<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Invoices\Application\Listeners\WebhookDeliveredListener;
use Modules\Invoices\Application\Ports\InvoiceNotifierInterface;
use Modules\Invoices\Application\Ports\TransactionManagerInterface;
use Modules\Invoices\Domain\Repositories\InvoiceRepositoryInterface;
use Modules\Invoices\Infrastructure\Console\ReconcileInvoiceSendingCommand;
use Modules\Invoices\Infrastructure\Notifications\NotificationFacadeInvoiceNotifier;
use Modules\Invoices\Infrastructure\Notifications\OutboxInvoiceNotifier;
use Modules\Invoices\Infrastructure\Persistence\DatabaseTransactionManager;
use Modules\Invoices\Infrastructure\Persistence\EloquentInvoiceRepository;
use Modules\Notifications\Api\Events\WebhookDeliveredEvent;

/**
 * Deliberately NOT a DeferrableProvider, and this is not a style preference.
 *
 * A deferred provider is loaded only when something resolves one of its promised
 * abstracts. A delivery webhook request resolves nothing from this module — it
 * enters through the Notifications controller — so the Event::listen() below would
 * never run and deliveries would fail silently while still answering 204. The
 * Notifications provider can be deferrable precisely because its bindings are
 * always pulled on demand; this one registers a listener, which is eager wiring.
 */
final class InvoiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped() matches the lifetime the Notifications provider settled on.
        $this->app->scoped(InvoiceRepositoryInterface::class, EloquentInvoiceRepository::class);
        $this->app->scoped(TransactionManagerInterface::class, DatabaseTransactionManager::class);

        // The one line that swaps durable delivery for a direct provider call. The
        // application service is identical either way — that is the whole point of
        // the port.
        $this->app->scoped(InvoiceNotifierInterface::class, static function ($app): InvoiceNotifierInterface {
            /** @var Application $app */
            return $app->make(
                $app->make('config')->get('invoices.notifier') === 'direct'
                    ? NotificationFacadeInvoiceNotifier::class
                    : OutboxInvoiceNotifier::class,
            );
        });
    }

    public function boot(): void
    {
        // Explicit, because discovery is not merely unidiomatic here — it is
        // unavailable: bootstrap/app.php never calls ->withEvents(), so the provider
        // that performs discovery is never registered, and even with it Laravel
        // scans app_path('Listeners') rather than src/Modules/**.
        Event::listen(WebhookDeliveredEvent::class, WebhookDeliveredListener::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileInvoiceSendingCommand::class]);
        }
    }
}
