<?php

declare(strict_types=1);

namespace Tests\Feature\Invoices\Http;

use Ramsey\Uuid\Uuid;
use Tests\Support\Invoices\CreatesInvoices;
use Tests\TestCase;

final class ViewInvoiceTest extends TestCase
{
    use CreatesInvoices;

    public function test_view_returns_the_invoice_with_line_totals_and_total_price(): void
    {
        $invoice = $this->persistedDraft(lines: 2);
        $lines = $invoice->productLines()->toArray();

        $this->getJson(route('invoices.view', ['invoiceId' => $invoice->id()->toString()]))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $invoice->id()->toString(),
                    'status' => 'draft',
                    'customer_name' => 'Ada Lovelace',
                    'customer_email' => 'ada@example.com',
                    'product_lines' => [
                        [
                            'id' => $lines[0]->id->toString(),
                            'name' => 'Widget 1',
                            'quantity' => 1,
                            'unit_price' => 500,
                            'total_unit_price' => 500,
                        ],
                        [
                            'id' => $lines[1]->id->toString(),
                            'name' => 'Widget 2',
                            'quantity' => 2,
                            'unit_price' => 1000,
                            'total_unit_price' => 2000,
                        ],
                    ],
                    'total_price' => 2500,
                ],
            ]);
    }

    public function test_view_returns_not_found_for_an_unknown_invoice(): void
    {
        $this->getJson(route('invoices.view', ['invoiceId' => Uuid::uuid4()->toString()]))
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    /** The route constraint rejects a malformed identifier before any controller runs. */
    public function test_view_returns_not_found_for_a_non_uuid_identifier(): void
    {
        $this->getJson('/api/invoices/not-a-uuid')->assertNotFound();
    }
}
