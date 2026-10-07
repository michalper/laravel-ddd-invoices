<?php

declare(strict_types=1);

namespace Tests\Support\Invoices;

use Modules\Invoices\Application\Exceptions\InvoiceSendFailedException;
use Modules\Invoices\Application\Ports\InvoiceNotifierInterface;
use Modules\Invoices\Domain\Entities\Invoice;
use RuntimeException;

final class SpyNotifier implements InvoiceNotifierInterface
{
    /** @var list<Invoice> */
    public array $notified = [];

    public function __construct(
        private readonly bool $shouldFail = false,
        private readonly ?CallLog $callLog = null,
    ) {}

    public function notifyInvoiceSent(Invoice $invoice): void
    {
        $this->callLog?->record('notify');

        if ($this->shouldFail) {
            throw InvoiceSendFailedException::forInvoice($invoice->id(), new RuntimeException('provider down'));
        }

        $this->notified[] = $invoice;
    }

    public function count(): int
    {
        return count($this->notified);
    }
}
