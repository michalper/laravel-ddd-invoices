<?php

declare(strict_types=1);

namespace Modules\Invoices\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Invoices\Application\Services\SendInvoiceService;
use Modules\Invoices\Presentation\Presenters\InvoicePresenter;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

final readonly class SendInvoiceController
{
    public function __construct(
        private SendInvoiceService $service,
        private InvoicePresenter $presenter,
    ) {}

    /**
     * 202, not 200: the request has been accepted and the intent durably recorded,
     * but the terminal state (sent-to-client) arrives later over the delivery
     * webhook. `sending` is literally a transitional state, which is what 202 is
     * for.
     */
    public function __invoke(string $invoiceId): JsonResponse
    {
        $invoice = $this->service->send(Uuid::fromString($invoiceId));

        return new JsonResponse(
            data: $this->presenter->toArray($invoice),
            status: Response::HTTP_ACCEPTED,
        );
    }
}
