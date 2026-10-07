<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Exceptions;

use Modules\Invoices\Domain\Enums\StatusEnum;
use Ramsey\Uuid\UuidInterface;

final class InvalidStatusTransitionException extends InvoiceException
{
    public static function between(StatusEnum $from, StatusEnum $to): self
    {
        return new self("An invoice cannot move from {$from->value} to {$to->value}.");
    }

    /**
     * Raised when the in-memory guard passed but the compare-and-swap lost: another
     * request changed the status between our read and our write.
     */
    public static function concurrentModification(UuidInterface $id): self
    {
        return new self("Invoice {$id->toString()} was modified by another request.");
    }
}
