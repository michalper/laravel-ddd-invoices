<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Services;

use Modules\Invoices\Application\Commands\CreateInvoiceCommand;
use Modules\Invoices\Application\Commands\CreateProductLineCommand;
use Modules\Invoices\Domain\Entities\Invoice;
use Modules\Invoices\Domain\Repositories\InvoiceRepositoryInterface;
use Modules\Invoices\Domain\ValueObjects\ProductLine;
use Modules\Invoices\Domain\ValueObjects\ProductLineCollection;

final readonly class CreateInvoiceService
{
    public function __construct(
        private InvoiceRepositoryInterface $repository,
    ) {}

    public function create(CreateInvoiceCommand $command): Invoice
    {
        // "An invoice can be created with empty product lines" reads as an explicit
        // named call rather than an implicit empty array.
        $lines = $command->productLines === []
            ? ProductLineCollection::empty()
            : ProductLineCollection::fromArray(array_map(
                static fn (CreateProductLineCommand $line): ProductLine => ProductLine::create(
                    name: $line->name,
                    quantity: $line->quantity,
                    unitPrice: $line->unitPrice,
                ),
                $command->productLines,
            ));

        $invoice = Invoice::draft(
            customerName: $command->customerName,
            customerEmail: $command->customerEmail,
            productLines: $lines,
        );

        $this->repository->save($invoice);

        return $invoice;
    }
}
