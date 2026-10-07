<?php

declare(strict_types=1);

namespace Tests\Feature\Invoices\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CreateInvoiceTest extends TestCase
{
    public function test_create_stores_a_draft_invoice_and_returns_it(): void
    {
        $response = $this->postJson(route('invoices.create'), [
            'customer_name' => 'Ada Lovelace',
            'customer_email' => 'ada@example.com',
            'product_lines' => [
                ['name' => 'Widget', 'quantity' => 2, 'unit_price' => 500],
                ['name' => 'Gadget', 'quantity' => 3, 'unit_price' => 100],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.customer_name', 'Ada Lovelace')
            ->assertJsonPath('data.product_lines.0.total_unit_price', 1000)
            ->assertJsonPath('data.product_lines.1.total_unit_price', 300)
            ->assertJsonPath('data.total_price', 1300);

        $id = $response->json('data.id');
        self::assertIsString($id);

        $response->assertHeader('Location', route('invoices.view', ['invoiceId' => $id]));

        $this->assertDatabaseHas('invoices', ['id' => $id, 'status' => 'draft']);
        $this->assertDatabaseCount('invoice_product_lines', 2);
    }

    public function test_create_accepts_empty_product_lines(): void
    {
        $this->postJson(route('invoices.create'), [
            'customer_name' => 'Ada Lovelace',
            'customer_email' => 'ada@example.com',
            'product_lines' => [],
        ])
            ->assertCreated()
            ->assertJsonPath('data.product_lines', [])
            ->assertJsonPath('data.total_price', 0);

        $this->assertDatabaseCount('invoice_product_lines', 0);
    }

    public function test_create_accepts_omitted_product_lines(): void
    {
        $this->postJson(route('invoices.create'), [
            'customer_name' => 'Ada Lovelace',
            'customer_email' => 'ada@example.com',
        ])
            ->assertCreated()
            ->assertJsonPath('data.total_price', 0);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $invalid
     */
    #[DataProvider('invalidPayloads')]
    public function test_create_rejects_an_invalid_payload(array $payload, array $invalid): void
    {
        $this->postJson(route('invoices.create'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($invalid);

        $this->assertDatabaseCount('invoices', 0);
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function invalidPayloads(): iterable
    {
        $line = ['name' => 'Widget', 'quantity' => 1, 'unit_price' => 100];

        yield 'missing customer name' => [
            ['customer_email' => 'ada@example.com'],
            ['customer_name'],
        ];

        yield 'blank customer name' => [
            ['customer_name' => '', 'customer_email' => 'ada@example.com'],
            ['customer_name'],
        ];

        yield 'missing customer email' => [
            ['customer_name' => 'Ada Lovelace'],
            ['customer_email'],
        ];

        yield 'malformed customer email' => [
            ['customer_name' => 'Ada Lovelace', 'customer_email' => 'not-an-email'],
            ['customer_email'],
        ];

        yield 'product line without a name' => [
            ['customer_name' => 'Ada', 'customer_email' => 'ada@example.com', 'product_lines' => [['quantity' => 1, 'unit_price' => 100]]],
            ['product_lines.0.name'],
        ];

        yield 'product line without a quantity' => [
            ['customer_name' => 'Ada', 'customer_email' => 'ada@example.com', 'product_lines' => [['name' => 'Widget', 'unit_price' => 100]]],
            ['product_lines.0.quantity'],
        ];

        yield 'product line without a unit price' => [
            ['customer_name' => 'Ada', 'customer_email' => 'ada@example.com', 'product_lines' => [['name' => 'Widget', 'quantity' => 1]]],
            ['product_lines.0.unit_price'],
        ];

        yield 'zero quantity' => [
            ['customer_name' => 'Ada', 'customer_email' => 'ada@example.com', 'product_lines' => [[...$line, 'quantity' => 0]]],
            ['product_lines.0.quantity'],
        ];

        yield 'negative quantity' => [
            ['customer_name' => 'Ada', 'customer_email' => 'ada@example.com', 'product_lines' => [[...$line, 'quantity' => -1]]],
            ['product_lines.0.quantity'],
        ];

        yield 'zero unit price' => [
            ['customer_name' => 'Ada', 'customer_email' => 'ada@example.com', 'product_lines' => [[...$line, 'unit_price' => 0]]],
            ['product_lines.0.unit_price'],
        ];

        // Strict on purpose: the API is explicitly typed, not coercive. The sharp
        // case is "2" — a string Laravel's own `integer` rule would coerce and
        // accept, which once turned into a TypeError and a 500 further down. The
        // float and non-numeric variants pin the rest of the boundary.
        yield 'quantity as a coercible string' => [
            ['customer_name' => 'Ada', 'customer_email' => 'ada@example.com', 'product_lines' => [[...$line, 'quantity' => '2']]],
            ['product_lines.0.quantity'],
        ];

        yield 'quantity as a non-numeric string' => [
            ['customer_name' => 'Ada', 'customer_email' => 'ada@example.com', 'product_lines' => [[...$line, 'quantity' => 'two']]],
            ['product_lines.0.quantity'],
        ];

        yield 'unit price as a coercible string' => [
            ['customer_name' => 'Ada', 'customer_email' => 'ada@example.com', 'product_lines' => [[...$line, 'unit_price' => '500']]],
            ['product_lines.0.unit_price'],
        ];

        // Bounded so an oversized value is a 422 here rather than an out-of-range
        // error from MySQL later. SQLite would have taken it silently.
        yield 'unit price beyond the integer column' => [
            ['customer_name' => 'Ada', 'customer_email' => 'ada@example.com', 'product_lines' => [[...$line, 'unit_price' => 2147483648]]],
            ['product_lines.0.unit_price'],
        ];
    }
}
