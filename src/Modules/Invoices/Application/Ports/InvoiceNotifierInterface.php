<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Ports;

use Modules\Invoices\Application\Exceptions\InvoiceSendFailedException;
use Modules\Invoices\Domain\Entities\Invoice;

/**
 * The outbound port for telling a customer their invoice is on the way.
 *
 * The contract is "durably record that this invoice must be notified", not "make
 * an HTTP call" — which is what lets the default adapter write an outbox row
 * inside the caller's transaction, and keeps the entire Notifications coupling
 * behind two infrastructure classes.
 *
 * Implementations are called inside a database transaction. An implementation that
 * performs network I/O therefore holds that transaction open for the duration of
 * the call, which is exactly why the outbox adapter is the default.
 */
interface InvoiceNotifierInterface
{
    /** @throws InvoiceSendFailedException */
    public function notifyInvoiceSent(Invoice $invoice): void;
}
