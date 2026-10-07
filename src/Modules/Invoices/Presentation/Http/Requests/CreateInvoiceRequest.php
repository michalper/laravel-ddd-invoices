<?php

declare(strict_types=1);

namespace Modules\Invoices\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Invoices\Application\Commands\CreateInvoiceCommand;
use Modules\Invoices\Application\Commands\CreateProductLineCommand;

final class CreateInvoiceRequest extends FormRequest
{
    /**
     * The split here is deliberate: this guards the SHAPE of the request, while the
     * aggregate guards the invariant. The overlap is not redundancy — the domain
     * must not trust HTTP, and a client deserves a 422 naming the offending field
     * rather than a 500 from a constructor it cannot see.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'string', 'email:rfc', 'max:255'],

            // `sometimes` rather than `required` or `present` is the rule that
            // encodes "an invoice can be created with empty product lines".
            'product_lines' => ['sometimes', 'array'],
            'product_lines.*.name' => ['required', 'string', 'max:255'],

            // `integer` is strict, so "2" as a JSON string is rejected: this API is
            // explicitly typed rather than coercive. The upper bound mirrors the
            // schema's `integer` columns, so an oversized value is a 422 here
            // instead of an out-of-range error from MySQL (SQLite would take it
            // silently).
            'product_lines.*.quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'product_lines.*.unit_price' => ['required', 'integer', 'min:1', 'max:2147483647'],
        ];
    }

    /**
     * Keeps the controller at two lines and puts the array-to-DTO mapping in one
     * testable place.
     */
    public function toCommand(): CreateInvoiceCommand
    {
        /** @var array{customer_name: string, customer_email: string, product_lines?: list<array{name: string, quantity: int, unit_price: int}>} $data */
        $data = $this->validated();

        return new CreateInvoiceCommand(
            customerName: $data['customer_name'],
            customerEmail: $data['customer_email'],
            productLines: array_map(
                static fn (array $line): CreateProductLineCommand => new CreateProductLineCommand(
                    name: $line['name'],
                    quantity: $line['quantity'],
                    unitPrice: $line['unit_price'],
                ),
                $data['product_lines'] ?? [],
            ),
        );
    }
}
