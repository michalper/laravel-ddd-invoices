<?php

declare(strict_types=1);

namespace Modules\Invoices\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Invoices\Application\Services\CreateInvoiceService;
use Modules\Invoices\Presentation\Http\Requests\CreateInvoiceRequest;
use Modules\Invoices\Presentation\Presenters\InvoicePresenter;
use Symfony\Component\HttpFoundation\Response;

final readonly class CreateInvoiceController
{
    public function __construct(
        private CreateInvoiceService $service,
        private InvoicePresenter $presenter,
    ) {}

    public function __invoke(CreateInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->service->create($request->toCommand());

        return new JsonResponse(
            data: $this->presenter->toArray($invoice),
            status: Response::HTTP_CREATED,
            headers: ['Location' => route('invoices.view', ['invoiceId' => $invoice->id()->toString()])],
        );
    }
}
