<?php

declare(strict_types=1);

namespace Modules\Invoices\Presentation\Presenters;

use Modules\Invoices\Domain\Entities\Invoice;
use Modules\Invoices\Domain\ValueObjects\ProductLine;

/**
 * A plain presenter rather than a JsonResource: JsonResource is built around model
 * attribute magic, and the aggregate exposes explicit accessors. Totals are
 * computed here from the domain, never read from a column — the schema has none,
 * and derived money has no business being stored twice.
 */
final readonly class InvoicePresenter
{
    /**
     * @return array{data: array{
     *     id: string,
     *     status: string,
     *     customer_name: string,
     *     customer_email: string,
     *     product_lines: list<array{id: string, name: string, quantity: int, unit_price: int, total_unit_price: int}>,
     *     total_price: int,
     * }}
     */
    public function toArray(Invoice $invoice): array
    {
        return [
            'data' => [
                'id' => $invoice->id()->toString(),
                'status' => $invoice->status()->value,
                'customer_name' => $invoice->customerName(),
                'customer_email' => $invoice->customerEmail(),
                'product_lines' => array_map(
                    static fn (ProductLine $line): array => [
                        'id' => $line->id->toString(),
                        'name' => $line->name,
                        'quantity' => $line->quantity,
                        'unit_price' => $line->unitPrice,
                        'total_unit_price' => $line->totalUnitPrice(),
                    ],
                    $invoice->productLines()->toArray(),
                ),
                'total_price' => $invoice->totalPrice(),
            ],
        ];
    }
}
