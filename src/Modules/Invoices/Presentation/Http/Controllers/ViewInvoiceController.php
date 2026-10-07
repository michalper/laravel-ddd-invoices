<?php

declare(strict_types=1);

namespace Modules\Invoices\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Invoices\Application\Services\GetInvoiceService;
use Modules\Invoices\Presentation\Presenters\InvoicePresenter;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

final readonly class ViewInvoiceController
{
    public function __construct(
        private GetInvoiceService $service,
        private InvoicePresenter $presenter,
    ) {}

    public function __invoke(string $invoiceId): JsonResponse
    {
        $invoice = $this->service->get(Uuid::fromString($invoiceId));

        return new JsonResponse(
            data: $this->presenter->toArray($invoice),
            status: Response::HTTP_OK,
        );
    }
}
