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
    /**
     * The cause travels as `previous` only, never in the message.
     *
     * That distinction matters because Presentation returns getMessage() in the 502
     * body, and a `renderable()` callback runs before the framework's own rendering
     * — so APP_DEBUG=false does not mask it the way it masks an unhandled 500.
     * Interpolating the downstream message here would hand any API client whatever
     * the driver happened to say: with a real HTTP or database-backed provider that
     * is a Guzzle or PDO string carrying internal hostnames, URLs, DSN fragments or
     * SQL. Keeping it as `previous` still gets the whole chain into the log, which
     * is where an operator can actually use it.
     */
    public static function forInvoice(UuidInterface $id, Throwable $previous): self
    {
        return new self(
            "Could not send invoice {$id->toString()}; the notification provider refused the message.",
            previous: $previous,
        );
    }
}
