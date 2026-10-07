<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Services;

use Modules\Invoices\Domain\Entities\Invoice;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Exceptions\InvalidStatusTransitionException;
use Modules\Invoices\Domain\Repositories\InvoiceRepositoryInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;

/**
 * Reacts to a delivery confirmation from the notification provider.
 *
 * The dividing line for what this swallows is retryability. A provider retries on
 * any non-2xx, and every state anomaly here is permanently non-retryable: the
 * invoice will still be unknown, still a draft, still already delivered on attempt
 * two and attempt fifty. Returning an error would buy nothing but a retry storm,
 * so anomalies are recorded and the webhook still answers 204.
 *
 * Infrastructure failures are the opposite case and are deliberately NOT caught:
 * if the database is unreachable the state change genuinely did not happen, and a
 * provider retry in thirty seconds is exactly the right recovery — so a
 * QueryException propagates and the webhook answers 500.
 */
final readonly class MarkInvoiceDeliveredService
{
    public function __construct(
        private InvoiceRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    public function markDelivered(UuidInterface $invoiceId): void
    {
        // find(), not get(): an id that is not an invoice is normal here. The event
        // is a shared cross-module broadcast carrying an opaque resource id, and
        // Invoices is one of potentially several listeners — an id that isn't ours
        // simply isn't addressed to us.
        $invoice = $this->repository->find($invoiceId);

        if (! $invoice instanceof Invoice) {
            $this->logger->info('Delivery webhook for an unknown resource; ignoring.', [
                'invoice_id' => $invoiceId->toString(),
            ]);

            return;
        }

        $statusBefore = $invoice->status();

        try {
            $invoice->markAsSentToClient();
        } catch (InvalidStatusTransitionException $e) {
            $this->log($statusBefore, $invoiceId, $e);

            return;
        }

        if (! $this->repository->compareAndSwapStatus($invoiceId, StatusEnum::Sending, StatusEnum::SentToClient)) {
            // Concurrent duplicate webhooks: the other one applied the change.
            $this->logger->info('Delivery already applied by a concurrent webhook; ignoring.', [
                'invoice_id' => $invoiceId->toString(),
            ]);

            return;
        }

        $this->logger->info('Invoice marked as sent to client.', [
            'invoice_id' => $invoiceId->toString(),
        ]);
    }

    private function log(StatusEnum $statusBefore, UuidInterface $invoiceId, InvalidStatusTransitionException $e): void
    {
        $context = [
            'invoice_id' => $invoiceId->toString(),
            'current_status' => $statusBefore->value,
            'reason' => $e->getMessage(),
        ];

        // A draft invoice is a real anomaly, not a duplicate: either the send path
        // lost its commit, or the webhook was forged or misrouted. It is the signal
        // the reconciler and alerting key on, so it is louder.
        if ($statusBefore === StatusEnum::Draft) {
            $this->logger->warning('Delivery webhook for an invoice that was never sent; ignoring.', $context);

            return;
        }

        $this->logger->info('Delivery webhook already applied; ignoring.', $context);
    }
}
