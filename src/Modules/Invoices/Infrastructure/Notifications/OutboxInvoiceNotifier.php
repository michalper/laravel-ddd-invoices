<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Notifications;

use Modules\Invoices\Application\Ports\InvoiceNotifierInterface;
use Modules\Invoices\Domain\Entities\Invoice;
use Modules\Invoices\Infrastructure\Persistence\OutboxRepository;

/**
 * The default adapter, and the reason the send workflow has no unrecoverable
 * failure window.
 *
 * It records the intent in the same database and the same transaction as the status
 * change, so the two cannot disagree — there is no dual write left to go wrong.
 * Actually reaching the provider is a separate, retried step, which makes delivery
 * at-least-once with the invoice id doubling as the idempotency key (the provider
 * already receives it as `reference`).
 *
 * The queue dispatch is only a latency optimisation; the row is the source of
 * truth, and `invoices:reconcile` re-drives anything the queue dropped.
 */
final readonly class OutboxInvoiceNotifier implements InvoiceNotifierInterface
{
    public function __construct(
        private OutboxRepository $outbox,
    ) {}

    public function notifyInvoiceSent(Invoice $invoice): void
    {
        $content = InvoiceNotificationMessage::forInvoice($invoice);

        $messageId = $this->outbox->enqueue($invoice->id(), [
            'to_email' => $invoice->customerEmail(),
            'subject' => $content->subject,
            'message' => $content->message,
        ]);

        // afterCommit so a rolled-back send never dispatches a job for a row that
        // no longer exists.
        ProcessOutboxMessageJob::dispatch($messageId)->afterCommit();
    }
}
