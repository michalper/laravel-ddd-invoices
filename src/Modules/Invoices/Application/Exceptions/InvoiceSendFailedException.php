<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Exceptions;

use Modules\Invoices\Domain\Exceptions\InvoiceException;
use Ramsey\Uuid\UuidInterface;
use Throwable;

/**
 * The notification path refused the message.
 *
 * Declared in Application because it is part of the InvoiceNotifierInterface
 * contract, but raised by the adapter that knows what kind of failure occurred —
 * so a provider error becomes this (mapped to 502) while an adapter's own
 * infrastructure failure, such as a QueryException from the outbox insert, is left
 * alone and surfaces as a 500.
 *
 * It extends the domain base class so Presentation's single `renderable()` picks
 * it up; Application already depends on Domain, so the direction is intact.
 */
final class InvoiceSendFailedException extends InvoiceException
{
    public static function forInvoice(UuidInterface $id, Throwable $previous): self
    {
        return new self(
            "Could not send invoice {$id->toString()}: {$previous->getMessage()}",
            previous: $previous,
        );
    }
}
