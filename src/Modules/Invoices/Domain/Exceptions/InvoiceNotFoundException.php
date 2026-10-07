<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Exceptions;

use Ramsey\Uuid\UuidInterface;

final class InvoiceNotFoundException extends InvoiceException
{
    public static function withId(UuidInterface $id): self
    {
        return new self("Invoice {$id->toString()} does not exist.");
    }
}
