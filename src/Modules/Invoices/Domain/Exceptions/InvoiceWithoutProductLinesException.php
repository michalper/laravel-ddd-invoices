<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Exceptions;

use Ramsey\Uuid\UuidInterface;

final class InvoiceWithoutProductLinesException extends InvoiceException
{
    public static function cannotBeSent(UuidInterface $id): self
    {
        return new self("Invoice {$id->toString()} has no product lines and cannot be sent.");
    }
}
