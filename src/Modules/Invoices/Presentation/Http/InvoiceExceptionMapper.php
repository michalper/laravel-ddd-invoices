<?php

declare(strict_types=1);

namespace Modules\Invoices\Presentation\Http;

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Modules\Invoices\Application\Exceptions\InvoiceSendFailedException;
use Modules\Invoices\Domain\Exceptions\InvalidProductLineException;
use Modules\Invoices\Domain\Exceptions\InvalidStatusTransitionException;
use Modules\Invoices\Domain\Exceptions\InvoiceException;
use Modules\Invoices\Domain\Exceptions\InvoiceNotFoundException;
use Modules\Invoices\Domain\Exceptions\InvoiceWithoutProductLinesException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one place that knows how a module failure looks over HTTP, so the domain can
 * stay ignorant of status codes and the controllers can stay free of try/catch.
 *
 * A single `renderable()` typed against the abstract base catches every module
 * exception, because Laravel matches these callbacks with is_a(). Adding a failure
 * mode costs one row below.
 */
final readonly class InvoiceExceptionMapper
{
    /** @var array<class-string<InvoiceException>, int> */
    private const array STATUSES = [
        InvoiceNotFoundException::class => Response::HTTP_NOT_FOUND,

        // 409, not 422: the payload is fine, the resource's current state conflicts
        // with the request. It is also the right answer for the loser of a
        // concurrent send, which is pleasingly consistent.
        InvalidStatusTransitionException::class => Response::HTTP_CONFLICT,

        InvoiceWithoutProductLinesException::class => Response::HTTP_UNPROCESSABLE_ENTITY,
        InvalidProductLineException::class => Response::HTTP_UNPROCESSABLE_ENTITY,

        // The failure is in a downstream dependency, not in the client's request.
        InvoiceSendFailedException::class => Response::HTTP_BAD_GATEWAY,
    ];

    public static function register(Exceptions $exceptions): void
    {
        // Unconditional JSON: every route this module exposes is an API route, and
        // bootstrap/app.php already forces JSON rendering for `api/*`. An extra
        // content-negotiation guard here was unreachable, which mutation testing
        // caught by surviving three mutations of it.
        $exceptions->renderable(static fn (InvoiceException $e): JsonResponse => new JsonResponse(
            data: ['message' => $e->getMessage()],
            status: self::STATUSES[$e::class] ?? Response::HTTP_INTERNAL_SERVER_ERROR,
        ));

        // A renderable() callback does not stop reporting, so every routine 404, 409
        // and 422 was also writing an error-level stack trace to laravel.log — noise
        // that buries the entries worth reading. These four are expected client
        // outcomes, not incidents. InvoiceSendFailedException is deliberately NOT
        // here: a provider refusal is an incident, and its cause reaches the log
        // through `previous` precisely because the 502 body does not carry it.
        $exceptions->dontReport([
            InvoiceNotFoundException::class,
            InvalidStatusTransitionException::class,
            InvoiceWithoutProductLinesException::class,
            InvalidProductLineException::class,
        ]);
    }
}
