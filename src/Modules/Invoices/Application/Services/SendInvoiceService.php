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
     * recoverable, and it is also why the statement order below is free: the
     * specification asks for the status change to follow the notification, and
     * since the two commit together, writing them in that order costs nothing and
     * keeps the code honest against the requirement.
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

            $invoice->markAsSending();

            $this->notifier->notifyInvoiceSent($invoice);

            // Second, database-level enforcement of the same precondition the
            // aggregate just checked. Two concurrent requests both read a draft and
            // both pass the in-memory guard; exactly one conditional UPDATE matches
            // a row. The loser rolls back, so its recorded intent disappears too and
            // the customer is notified once.
            if (! $this->repository->compareAndSwapStatus($id, StatusEnum::Draft, StatusEnum::Sending)) {
                throw InvalidStatusTransitionException::concurrentModification($id);
            }

            return $invoice;
        });
    }
}
