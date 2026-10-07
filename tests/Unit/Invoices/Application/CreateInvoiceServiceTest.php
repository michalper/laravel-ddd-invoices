<?php

declare(strict_types=1);

namespace Tests\Unit\Invoices\Application;

use Modules\Invoices\Application\Commands\CreateInvoiceCommand;
use Modules\Invoices\Application\Commands\CreateProductLineCommand;
use Modules\Invoices\Application\Services\CreateInvoiceService;
use Modules\Invoices\Domain\Entities\Invoice;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Exceptions\InvalidProductLineException;
use Modules\Invoices\Domain\Repositories\InvoiceRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class CreateInvoiceServiceTest extends TestCase
{
    public function test_create_persists_a_draft_invoice_with_its_product_lines(): void
    {
        $repository = $this->createMock(InvoiceRepositoryInterface::class);

        $saved = null;
        $repository->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (Invoice $invoice) use (&$saved): void {
                $saved = $invoice;
            });

        $invoice = new CreateInvoiceService($repository)->create(new CreateInvoiceCommand(
            customerName: 'Ada Lovelace',
            customerEmail: 'ada@example.com',
            productLines: [
                new CreateProductLineCommand(name: 'Widget', quantity: 2, unitPrice: 500),
                new CreateProductLineCommand(name: 'Gadget', quantity: 1, unitPrice: 300),
            ],
        ));

        self::assertSame($invoice, $saved);
        self::assertSame(StatusEnum::Draft, $invoice->status());
        self::assertCount(2, $invoice->productLines());
        self::assertSame(1300, $invoice->totalPrice());
    }

    public function test_create_accepts_a_command_without_product_lines(): void
    {
        $repository = $this->createMock(InvoiceRepositoryInterface::class);
        $repository->expects($this->once())->method('save');

        $invoice = new CreateInvoiceService($repository)->create(new CreateInvoiceCommand(
            customerName: 'Ada Lovelace',
            customerEmail: 'ada@example.com',
        ));

        self::assertTrue($invoice->productLines()->isEmpty());
        self::assertSame(StatusEnum::Draft, $invoice->status());
    }

    public function test_create_refuses_an_invalid_product_line_and_persists_nothing(): void
    {
        $repository = $this->createMock(InvoiceRepositoryInterface::class);
        $repository->expects($this->never())->method('save');

        $this->expectException(InvalidProductLineException::class);

        new CreateInvoiceService($repository)->create(new CreateInvoiceCommand(
            customerName: 'Ada Lovelace',
            customerEmail: 'ada@example.com',
            productLines: [new CreateProductLineCommand(name: 'Widget', quantity: 0, unitPrice: 500)],
        ));
    }
}
