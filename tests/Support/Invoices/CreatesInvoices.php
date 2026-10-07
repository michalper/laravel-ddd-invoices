<?php

declare(strict_types=1);

namespace Tests\Support\Invoices;

use Modules\Invoices\Domain\Entities\Invoice;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Repositories\InvoiceRepositoryInterface;
use Modules\Invoices\Domain\ValueObjects\ProductLine;
use Modules\Invoices\Domain\ValueObjects\ProductLineCollection;
use Modules\Notifications\Infrastructure\Drivers\DriverInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Fixtures seed through the module's own repository rather than Eloquent factories,
 * so every fixture exercises the real mapping and persistence knowledge stays in one
 * place.
 */
trait CreatesInvoices
{
    protected function persistedDraft(int $lines = 2): Invoice
    {
        $invoice = Invoice::draft(
            customerName: 'Ada Lovelace',
            customerEmail: 'ada@example.com',
            productLines: $this->productLines($lines),
        );

        $this->invoiceRepository()->save($invoice);

        return $invoice;
    }

    protected function persistedInvoiceInStatus(StatusEnum $status, int $lines = 2): Invoice
    {
        $invoice = $this->persistedDraft($lines);

        if ($status === StatusEnum::Draft) {
            return $invoice;
        }

        $this->invoiceRepository()->compareAndSwapStatus($invoice->id(), StatusEnum::Draft, StatusEnum::Sending);

        if ($status === StatusEnum::Sending) {
            return $this->invoiceRepository()->get($invoice->id());
        }

        $this->invoiceRepository()->compareAndSwapStatus($invoice->id(), StatusEnum::Sending, StatusEnum::SentToClient);

        return $this->invoiceRepository()->get($invoice->id());
    }

    protected function productLines(int $count): ProductLineCollection
    {
        $lines = [];

        for ($i = 1; $i <= $count; $i++) {
            $lines[] = ProductLine::create(
                name: "Widget {$i}",
                quantity: $i,
                unitPrice: 500 * $i,
            );
        }

        return ProductLineCollection::fromArray($lines);
    }

    /**
     * Must be instance(), not bind(): NotificationServiceProvider is a
     * DeferrableProvider whose register() calls scoped(), and resolving the abstract
     * would load it and overwrite a bind(). Application::loadDeferredProviderIfNeeded()
     * explicitly skips loading when an instance is already registered, so this wins.
     */
    protected function fakeNotificationDriver(): RecordingDriver
    {
        $driver = new RecordingDriver;

        $this->app->instance(DriverInterface::class, $driver);

        return $driver;
    }

    protected function failingNotificationDriver(string $reason = 'provider unavailable'): ThrowingDriver
    {
        $driver = new ThrowingDriver($reason);

        $this->app->instance(DriverInterface::class, $driver);

        return $driver;
    }

    /**
     * A typed logger double bound into the container, rather than Log::spy().
     * Mockery's spy API is not expressible to static analysis, and an explicit mock
     * states the expected call count exactly.
     *
     * @return LoggerInterface&MockObject
     */
    protected function expectLogger(): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);

        $this->app->instance(LoggerInterface::class, $logger);

        return $logger;
    }

    /**
     * A recording fake rather than a double, for tests that assert on the log
     * entries themselves instead of on a call expectation.
     */
    protected function recordingLogger(): RecordingLogger
    {
        $logger = new RecordingLogger;

        $this->app->instance(LoggerInterface::class, $logger);

        return $logger;
    }

    protected function invoiceRepository(): InvoiceRepositoryInterface
    {
        return $this->app->make(InvoiceRepositoryInterface::class);
    }
}
