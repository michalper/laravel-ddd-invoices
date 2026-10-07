<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Services;

use Modules\Invoices\Application\Exceptions\InvoiceSendFailedException;
use Modules\Invoices\Application\Ports\InvoiceNotifierInterface;
use Modules\Invoices\Application\Ports\TransactionManagerInterface;
use Modules\Invoices\Domain\Entities\Invoice;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Exceptions\InvalidStatusTransitionException;
use Modules\Invoices\Domain\Exceptions\InvoiceNotFoundException;
use Modules\Invoices\Domain\Exceptions\InvoiceWithoutProductLinesException;
use Modules\Invoices\Domain\Repositories\InvoiceRepositoryInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class SendInvoiceService
{
    public function __construct(
        private InvoiceRepositoryInterface $repository,
        private InvoiceNotifierInterface $notifier,
        private TransactionManagerInterface $transaction,
    ) {}

    /**
     * Notifying and flipping the status happen in one transaction, so they either
     * both land or neither does. That atomicity is what makes this workflow
     * recoverable, and it is also what makes the statement order below invisible to
     * the outside world: nothing is observable until the commit, so the
     * specification's "change the status to sending after sending the notification"
     * is satisfied by the commit, not by which line comes first.
     *
     * Claiming the invoice therefore goes FIRST, and that ordering is load-bearing
     * rather than stylistic. The in-memory guard cannot decide a race — two
     * concurrent requests both read a draft and both pass markAsSending() — so the
     * conditional UPDATE is the only thing that does. With the default outbox
     * adapter the notification is just another row in this transaction, so either
     * order is safe; but NotificationFacadeInvoiceNotifier reaches the provider
     * immediately, and an external call is not rolled back. Notifying before the
     * claim would let both racers e-mail the customer and then hand the loser a 409
     * for a message that had already gone out. Claiming first makes exactly one
     * request reach the provider under either adapter.
     *
     * Failure needs no compensating transition: an exception rolls the status back
     * to draft and takes the recorded intent with it. The state machine therefore
     * keeps exactly the three transitions the specification defines — there is no
     * "un-send" edge for a client to reach.
     *
     * @throws InvoiceNotFoundException no such invoice (404)
     * @throws InvalidStatusTransitionException not a draft, or another request won the race (409)
     * @throws InvoiceWithoutProductLinesException nothing to invoice (422)
     * @throws InvoiceSendFailedException the notification path refused the message (502)
     */
    public function send(UuidInterface $id): Invoice
    {
        return $this->transaction->run(function () use ($id): Invoice {
            $invoice = $this->repository->get($id);

            // The aggregate's guard runs first so an illegal transition is a 409 and
            // an invoice with no lines a 422, both decided before any row is touched.
            $invoice->markAsSending();

            // Second, database-level enforcement of the same precondition: exactly
            // one conditional UPDATE matches a row, and the loser rolls back.
            if (! $this->repository->compareAndSwapStatus($id, StatusEnum::Draft, StatusEnum::Sending)) {
                throw InvalidStatusTransitionException::concurrentModification($id);
            }

            // Only the winner gets here, so the customer is notified exactly once.
            $this->notifier->notifyInvoiceSent($invoice);

            return $invoice;
        });
    }
}
