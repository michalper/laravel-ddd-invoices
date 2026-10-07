<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Notifications;

use Modules\Invoices\Domain\Entities\Invoice;

/**
 * The customer-facing copy lives at the boundary, shared by both notifier
 * adapters, rather than inside an application service where it would be business
 * logic it is not.
 */
final readonly class InvoiceNotificationMessage
{
    private function __construct(
        public string $subject,
        public string $message,
    ) {}

    public static function forInvoice(Invoice $invoice): self
    {
        return new self(
            subject: 'Your invoice is on its way',
            message: sprintf(
                'Hello %s, invoice %s for a total of %s is being sent to you.',
                $invoice->customerName(),
                $invoice->id()->toString(),
                number_format($invoice->totalPrice() / 100, 2, '.', ' '),
            ),
        );
    }
}
