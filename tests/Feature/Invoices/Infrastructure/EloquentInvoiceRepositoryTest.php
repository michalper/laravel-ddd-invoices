<?php

declare(strict_types=1);

namespace Tests\Feature\Invoices\Infrastructure;

use Modules\Invoices\Domain\Entities\Invoice;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Exceptions\InvoiceNotFoundException;
use Modules\Invoices\Domain\ValueObjects\ProductLine;
use Modules\Invoices\Domain\ValueObjects\ProductLineCollection;
use Ramsey\Uuid\Uuid;
use Tests\Support\Invoices\CreatesInvoices;
use Tests\TestCase;

final class EloquentInvoiceRepositoryTest extends TestCase
{
    use CreatesInvoices;

    public function test_save_persists_the_invoice_with_its_product_lines(): void
    {
        $invoice = $this->persistedDraft(lines: 3);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id()->toString(),
            'customer_name' => 'Ada Lovelace',
            'customer_email' => 'ada@example.com',
            'status' => StatusEnum::Draft->value,
        ]);
        $this->assertDatabaseCount('invoice_product_lines', 3);
    }

    public function test_save_persists_an_invoice_without_product_lines(): void
    {
        $invoice = $this->persistedDraft(lines: 0);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id()->toString()]);
        $this->assertDatabaseCount('invoice_product_lines', 0);
    }

    public function test_get_round_trips_the_aggregate(): void
    {
        $original = $this->persistedDraft(lines: 2);

        $restored = $this->invoiceRepository()->get($original->id());

        self::assertSame($original->id()->toString(), $restored->id()->toString());
        self::assertSame($original->customerName(), $restored->customerName());
        self::assertSame($original->customerEmail(), $restored->customerEmail());
        self::assertSame($original->status(), $restored->status());
        self::assertSame($original->totalPrice(), $restored->totalPrice());
        self::assertCount(2, $restored->productLines());

        $originalLines = $original->productLines()->toArray();
        $restoredLines = $restored->productLines()->toArray();

        foreach ($originalLines as $index => $line) {
            self::assertSame($line->id->toString(), $restoredLines[$index]->id->toString());
            self::assertSame($line->name, $restoredLines[$index]->name);
            self::assertSame($line->quantity, $restoredLines[$index]->quantity);
            self::assertSame($line->unitPrice, $restoredLines[$index]->unitPrice);
            self::assertSame($line->totalUnitPrice(), $restoredLines[$index]->totalUnitPrice());
        }
    }

    /**
     * The order has to be the repository's, not the engine's. Creation order is
     * deliberately the reverse of the defined order here, so a missing ORDER BY
     * cannot pass by coincidence — which is exactly how this went unnoticed while
     * CI ran SQLite alone: SQLite and PostgreSQL returned insertion order, MySQL
     * returned primary-key order over random UUIDs.
     */
    public function test_product_lines_come_back_in_a_deterministic_order(): void
    {
        $invoice = Invoice::draft(
            customerName: 'Ada Lovelace',
            customerEmail: 'ada@example.com',
            productLines: ProductLineCollection::fromArray([
                ProductLine::create(name: 'Zeta widget', quantity: 1, unitPrice: 100),
                ProductLine::create(name: 'Alpha widget', quantity: 1, unitPrice: 200),
            ]),
        );

        $this->invoiceRepository()->save($invoice);

        $names = array_map(
            static fn (ProductLine $line): string => $line->name,
            $this->invoiceRepository()->get($invoice->id())->productLines()->toArray(),
        );

        self::assertSame(['Alpha widget', 'Zeta widget'], $names);
    }

    public function test_find_returns_null_for_an_unknown_identifier(): void
    {
        self::assertNull($this->invoiceRepository()->find(Uuid::uuid4()));
    }

    public function test_get_throws_for_an_unknown_identifier(): void
    {
        $this->expectException(InvoiceNotFoundException::class);

        $this->invoiceRepository()->get(Uuid::uuid4());
    }

    /**
     * Pins the concurrency primitive the whole send workflow rests on.
     */
    public function test_compare_and_swap_status_updates_only_when_the_current_status_matches(): void
    {
        $invoice = $this->persistedDraft();
        $repository = $this->invoiceRepository();

        self::assertTrue(
            $repository->compareAndSwapStatus($invoice->id(), StatusEnum::Draft, StatusEnum::Sending),
        );
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id()->toString(),
            'status' => StatusEnum::Sending->value,
        ]);

        // The same call again finds no row in the expected state: this is the loser
        // of a concurrent send.
        self::assertFalse(
            $repository->compareAndSwapStatus($invoice->id(), StatusEnum::Draft, StatusEnum::Sending),
        );
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id()->toString(),
            'status' => StatusEnum::Sending->value,
        ]);
    }

    public function test_compare_and_swap_status_reports_failure_for_an_unknown_invoice(): void
    {
        self::assertFalse(
            $this->invoiceRepository()->compareAndSwapStatus(Uuid::uuid4(), StatusEnum::Draft, StatusEnum::Sending),
        );
    }
}
