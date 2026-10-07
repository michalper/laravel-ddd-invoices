<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Notifications;

use Modules\Invoices\Application\Exceptions\InvoiceSendFailedException;
use Modules\Invoices\Application\Ports\InvoiceNotifierInterface;
use Modules\Invoices\Domain\Entities\Invoice;
use Modules\Notifications\Api\Data\NotifyData;
use Modules\Notifications\Api\NotificationFacadeInterface;
use Throwable;

/**
 * The alternative adapter, kept deliberately as an exhibit of the trade-off.
 *
 * It calls the provider straight away, which means a network call inside the
 * caller's transaction — holding a row lock for the duration of someone else's
 * latency. It is only harmless here because the task's DummyDriver does nothing.
 * That flaw is exactly why OutboxInvoiceNotifier is the default; this one exists so
 * the full flow can be demonstrated without a queue worker, and is selected with
 * INVOICE_NOTIFIER=direct.
 *
 * Together with WebhookDeliveredListener, this is the entire coupling surface
 * between Invoices and Notifications, and it only touches the published Api layer.
 */
final readonly class NotificationFacadeInvoiceNotifier implements InvoiceNotifierInterface
{
    public function __construct(
        private NotificationFacadeInterface $facade,
    ) {}

    public function notifyInvoiceSent(Invoice $invoice): void
    {
        $content = InvoiceNotificationMessage::forInvoice($invoice);

        try {
            $this->facade->notify(new NotifyData(
                resourceId: $invoice->id(),
                toEmail: $invoice->customerEmail(),
                subject: $content->subject,
                message: $content->message,
            ));
        } catch (Throwable $e) {
            // A downstream dependency refused the message, so this becomes a 502 and
            // the surrounding transaction rolls the invoice back to draft.
            throw InvoiceSendFailedException::forInvoice($invoice->id(), $e);
        }
    }
}
