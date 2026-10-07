<?php

declare(strict_types=1);

namespace Tests\Unit\Invoices\Application;

use Modules\Invoices\Application\Services\MarkInvoiceDeliveredService;
use Modules\Invoices\Domain\Entities\Invoice;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Repositories\InvoiceRepositoryInterface;
use Modules\Invoices\Domain\ValueObjects\ProductLine;
use Modules\Invoices\Domain\ValueObjects\ProductLineCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use RuntimeException;

final class MarkInvoiceDeliveredServiceTest extends TestCase
{
    public function test_delivered_marks_a_sending_invoice_as_sent_to_client(): void
    {
        $invoice = $this->restored(StatusEnum::Sending);

        $repository = $this->createMock(InvoiceRepositoryInterface::class);
        $repository->method('find')->willReturn($invoice);
        $repository->expects($this->once())
            ->method('compareAndSwapStatus')
            ->with($invoice->id(), StatusEnum::Sending, StatusEnum::SentToClient)
            ->willReturn(true);

        $this->service($repository, $this->createStub(LoggerInterface::class))
            ->markDelivered($invoice->id());
    }

    public function test_delivered_ignores_an_unknown_resource_and_records_it(): void
    {
        $repository = $this->createMock(InvoiceRepositoryInterface::class);
        $repository->method('find')->willReturn(null);
        $repository->expects($this->never())->method('compareAndSwapStatus');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');
        $logger->expects($this->never())->method('warning');

        $this->service($repository, $logger)->markDelivered($this->id());
    }

    public function test_delivered_is_idempotent_for_an_invoice_already_sent_to_client(): void
    {
        $invoice = $this->restored(StatusEnum::SentToClient);

        $repository = $this->createMock(InvoiceRepositoryInterface::class);
        $repository->method('find')->willReturn($invoice);
        $repository->expects($this->never())->method('compareAndSwapStatus');

        $logger = $this->createMock(LoggerInterface::class);
        // A duplicate webhook is routine, not an anomaly.
        $logger->expects($this->never())->method('warning');
        $logger->expects($this->once())->method('info');

        $this->service($repository, $logger)->markDelivered($invoice->id());
    }

    /**
     * A draft invoice is the one case that deserves to be loud: either the send path
     * lost its commit, or the webhook was forged or misrouted.
     */
    public function test_delivered_ignores_a_draft_invoice_but_warns_about_it(): void
    {
        $invoice = $this->restored(StatusEnum::Draft);

        $repository = $this->createMock(InvoiceRepositoryInterface::class);
        $repository->method('find')->willReturn($invoice);
        $repository->expects($this->never())->method('compareAndSwapStatus');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $this->service($repository, $logger)->markDelivered($invoice->id());
    }

    public function test_delivered_accepts_losing_the_race_to_a_concurrent_webhook(): void
    {
        $invoice = $this->restored(StatusEnum::Sending);

        $repository = $this->createStub(InvoiceRepositoryInterface::class);
        $repository->method('find')->willReturn($invoice);
        $repository->method('compareAndSwapStatus')->willReturn(false);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');
        $logger->expects($this->never())->method('critical');

        $this->service($repository, $logger)->markDelivered($invoice->id());
    }

    /**
     * The dividing line: state anomalies are swallowed because a retry cannot fix
     * them, but an infrastructure failure means the change genuinely did not happen,
     * so it must escape and let the provider retry.
     */
    public function test_delivered_propagates_infrastructure_failures(): void
    {
        $repository = $this->createStub(InvoiceRepositoryInterface::class);
        $repository->method('find')->willThrowException(new RuntimeException('database is unreachable'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database is unreachable');

        $this->service($repository, $this->createStub(LoggerInterface::class))
            ->markDelivered($this->id());
    }

    private function service(InvoiceRepositoryInterface $repository, LoggerInterface $logger): MarkInvoiceDeliveredService
    {
        return new MarkInvoiceDeliveredService($repository, $logger);
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
        return Uuid::fromString('0191b1f0-3333-7333-8333-333333333333');
    }
}
