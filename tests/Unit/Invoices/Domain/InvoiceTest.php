<?php

declare(strict_types=1);

namespace Tests\Unit\Invoices\Domain;

use Modules\Invoices\Domain\Entities\Invoice;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Exceptions\InvalidStatusTransitionException;
use Modules\Invoices\Domain\Exceptions\InvoiceWithoutProductLinesException;
use Modules\Invoices\Domain\ValueObjects\ProductLine;
use Modules\Invoices\Domain\ValueObjects\ProductLineCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class InvoiceTest extends TestCase
{
    public function test_draft_invoice_starts_in_draft_status(): void
    {
        $invoice = $this->draft();

        self::assertSame(StatusEnum::Draft, $invoice->status());
    }

    public function test_draft_invoice_can_be_created_without_product_lines(): void
    {
        $invoice = Invoice::draft(
            customerName: 'Ada Lovelace',
            customerEmail: 'ada@example.com',
            productLines: ProductLineCollection::empty(),
        );

        self::assertTrue($invoice->productLines()->isEmpty());
        self::assertSame(StatusEnum::Draft, $invoice->status());
    }

    public function test_draft_generates_an_identifier_when_none_is_given(): void
    {
        self::assertTrue(Uuid::isValid($this->draft()->id()->toString()));
    }

    public function test_draft_accepts_an_explicit_identifier(): void
    {
        $id = $this->id();

        self::assertSame($id, $this->draft(id: $id)->id());
    }

    public function test_customer_details_are_exposed_as_given(): void
    {
        $invoice = $this->draft();

        self::assertSame('Ada Lovelace', $invoice->customerName());
        self::assertSame('ada@example.com', $invoice->customerEmail());
    }

    public function test_total_price_is_the_sum_of_product_line_totals(): void
    {
        $invoice = $this->draft(lines: [
            ProductLine::create(name: 'Widget', quantity: 2, unitPrice: 500),
            ProductLine::create(name: 'Gadget', quantity: 3, unitPrice: 100),
        ]);

        self::assertSame(1300, $invoice->totalPrice());
    }

    public function test_total_price_is_zero_without_product_lines(): void
    {
        self::assertSame(0, $this->draft(lines: [])->totalPrice());
    }

    public function test_mark_as_sending_moves_draft_invoice_to_sending(): void
    {
        $invoice = $this->draft();

        $invoice->markAsSending();

        self::assertSame(StatusEnum::Sending, $invoice->status());
    }

    public function test_mark_as_sending_rejects_invoice_without_product_lines(): void
    {
        $invoice = $this->draft(lines: []);

        $this->expectException(InvoiceWithoutProductLinesException::class);

        try {
            $invoice->markAsSending();
        } finally {
            // The guard must not leave a half-applied transition behind.
            self::assertSame(StatusEnum::Draft, $invoice->status());
        }
    }

    #[DataProvider('statusesThatCannotBeSent')]
    public function test_mark_as_sending_rejects_invoice_that_is_not_a_draft(StatusEnum $status): void
    {
        $invoice = $this->restored($status);

        $this->expectException(InvalidStatusTransitionException::class);

        try {
            $invoice->markAsSending();
        } finally {
            self::assertSame($status, $invoice->status());
        }
    }

    /** @return iterable<string, array{StatusEnum}> */
    public static function statusesThatCannotBeSent(): iterable
    {
        yield 'already sending' => [StatusEnum::Sending];
        yield 'already sent to client' => [StatusEnum::SentToClient];
    }

    public function test_mark_as_sent_to_client_moves_sending_invoice_to_sent_to_client(): void
    {
        $invoice = $this->restored(StatusEnum::Sending);

        $invoice->markAsSentToClient();

        self::assertSame(StatusEnum::SentToClient, $invoice->status());
    }

    #[DataProvider('statusesThatCannotBeDelivered')]
    public function test_mark_as_sent_to_client_rejects_invoice_that_is_not_sending(StatusEnum $status): void
    {
        $invoice = $this->restored($status);

        $this->expectException(InvalidStatusTransitionException::class);

        try {
            $invoice->markAsSentToClient();
        } finally {
            self::assertSame($status, $invoice->status());
        }
    }

    /** @return iterable<string, array{StatusEnum}> */
    public static function statusesThatCannotBeDelivered(): iterable
    {
        yield 'still a draft' => [StatusEnum::Draft];
        yield 'already sent to client' => [StatusEnum::SentToClient];
    }

    public function test_full_lifecycle_is_reachable_through_the_aggregate_alone(): void
    {
        $invoice = $this->draft();

        $invoice->markAsSending();
        $invoice->markAsSentToClient();

        self::assertSame(StatusEnum::SentToClient, $invoice->status());
    }

    public function test_restore_rebuilds_the_aggregate_in_the_given_status(): void
    {
        $id = $this->id();

        $invoice = Invoice::restore(
            id: $id,
            customerName: 'Grace Hopper',
            customerEmail: 'grace@example.com',
            status: StatusEnum::Sending,
            productLines: ProductLineCollection::fromArray([
                ProductLine::create(name: 'Compiler', quantity: 1, unitPrice: 4200),
            ]),
        );

        self::assertSame($id, $invoice->id());
        self::assertSame('Grace Hopper', $invoice->customerName());
        self::assertSame('grace@example.com', $invoice->customerEmail());
        self::assertSame(StatusEnum::Sending, $invoice->status());
        self::assertSame(4200, $invoice->totalPrice());
    }

    /** @param list<ProductLine>|null $lines */
    private function draft(?array $lines = null, ?UuidInterface $id = null): Invoice
    {
        return Invoice::draft(
            customerName: 'Ada Lovelace',
            customerEmail: 'ada@example.com',
            productLines: ProductLineCollection::fromArray(
                $lines ?? [ProductLine::create(name: 'Widget', quantity: 1, unitPrice: 500)],
            ),
            id: $id,
        );
    }

    private function restored(StatusEnum $status): Invoice
    {
        return Invoice::restore(
            id: $this->id(),
            customerName: 'Ada Lovelace',
            customerEmail: 'ada@example.com',
            status: $status,
            productLines: ProductLineCollection::fromArray([
                ProductLine::create(name: 'Widget', quantity: 1, unitPrice: 500),
            ]),
        );
    }

    private function id(): UuidInterface
    {
        return Uuid::fromString('0191b1f0-1111-7111-8111-111111111111');
    }
}
