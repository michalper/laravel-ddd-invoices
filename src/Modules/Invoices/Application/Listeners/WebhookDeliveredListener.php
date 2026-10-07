<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Listeners;

use Modules\Invoices\Application\Services\MarkInvoiceDeliveredService;
use Modules\Notifications\Api\Events\WebhookDeliveredEvent;

/**
 * One of the two files in this module that know the Notifications namespace, and
 * it only touches that module's published Api layer.
 *
 * Kept as a thin adapter on purpose: the decisions belong in the service, where the
 * invoice's current status is known.
 *
 * Deliberately not queued. The whole reaction is a single conditional UPDATE, so
 * queueing would cost more than doing it — but the real reason is that a
 * synchronous listener makes the webhook's 204 mean something. It attests that the
 * state was applied, which wires the provider's retry semantics to our actual
 * success; queue it and the 204 degrades to "accepted", and a later failure becomes
 * invisible to the provider. That is how deliveries get silently lost.
 */
final readonly class WebhookDeliveredListener
{
    public function __construct(
        private MarkInvoiceDeliveredService $service,
    ) {}

    public function handle(WebhookDeliveredEvent $event): void
    {
        $this->service->markDelivered($event->resourceId);
    }
}
