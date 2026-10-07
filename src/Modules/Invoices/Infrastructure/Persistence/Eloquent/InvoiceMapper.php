<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Persistence\Eloquent;

use Modules\Invoices\Domain\Entities\Invoice;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\ValueObjects\ProductLine;
use Modules\Invoices\Domain\ValueObjects\ProductLineCollection;
use Ramsey\Uuid\Uuid;

/**
 * The whole cost of keeping Eloquent out of the domain, in one place.
 */
final readonly class InvoiceMapper
{
    public function toDomain(InvoiceModel $model): Invoice
    {
        $lines = $model->productLines
            ->map(static fn (ProductLineModel $line): ProductLine => new ProductLine(
                id: Uuid::fromString($line->id),
                name: $line->name,
                quantity: $line->quantity,
                unitPrice: $line->unit_price,
            ))
            ->values()
            ->all();

        return Invoice::restore(
            id: Uuid::fromString($model->id),
            customerName: $model->customer_name,
            customerEmail: $model->customer_email,
            status: StatusEnum::from($model->status),
            productLines: ProductLineCollection::fromArray($lines),
        );
    }

    /** @return array<string, string> */
    public function toInvoiceRow(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id()->toString(),
            'customer_name' => $invoice->customerName(),
            'customer_email' => $invoice->customerEmail(),
            'status' => $invoice->status()->value,
        ];
    }

    /** @return list<array<string, string|int>> */
    public function toProductLineRows(Invoice $invoice): array
    {
        return array_map(
            static fn (ProductLine $line): array => [
                'id' => $line->id->toString(),
                'name' => $line->name,
                'quantity' => $line->quantity,
                'unit_price' => $line->unitPrice,
            ],
            $invoice->productLines()->toArray(),
        );
    }
}
