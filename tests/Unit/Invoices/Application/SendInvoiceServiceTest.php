<?php

declare(strict_types=1);

namespace Tests\Unit\Invoices\Application;

use Modules\Invoices\Application\Exceptions\InvoiceSendFailedException;
use Modules\Invoices\Application\Services\SendInvoiceService;
use Modules\Invoices\Domain\Entities\Invoice;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Exceptions\InvalidStatusTransitionException;
use Modules\Invoices\Domain\Exceptions\InvoiceNotFoundException;
use Modules\Invoices\Domain\Exceptions\InvoiceWithoutProductLinesException;
use Modules\Invoices\Domain\Repositories\InvoiceRepositoryInterface;
use Modules\Invoices\Domain\ValueObjects\ProductLine;
use Modules\Invoices\Domain\ValueObjects\ProductLineCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Tests\Support\Invoices\CallLog;
use Tests\Support\Invoices\ImmediateTransactionManager;
use Tests\Support\Invoices\SpyNotifier;

final class SendInvoiceServiceTest extends TestCase
{
    public function test_send_claims_the_invoice_before_notifying_the_customer(): void
    {
        $log = new CallLog;
        $invoice = $this->draft();
        $repository = $this->repositoryStub($invoice);

        $repository->method('compareAndSwapStatus')
            ->willReturnCallback(function () use ($log): bool {
                $log->record('swap');

                return true;
            });

        $notifier = new SpyNotifier(callLog: $log);

        $result = $this->service($repository, $notifier)->send($invoice->id());

        // This order is the guarantee, not a preference. The conditional UPDATE is
        // the only thing that can decide a race, so it has to run before anything
        // irreversible: the `direct` adapter reaches the provider immediately, and a
        // rollback cannot recall an e-mail. Asserting the order pins the one
        // property that keeps "exactly one notification" true for both adapters.
        self::assertSame(['swap', 'notify'], $log->all());
        self::assertSame(StatusEnum::Sending, $result->status());
        self::assertSame(1, $notifier->count());
    }

    public function test_send_runs_inside_a_single_transaction(): void
    {
        $invoice = $this->draft();
        $repository = $this->repositoryStub($invoice);
        $repository->method('compareAndSwapStatus')->willReturn(true);

        $transaction = new ImmediateTransactionManager;

        new SendInvoiceService($repository, new SpyNotifier, $transaction)->send($invoice->id());

        self::assertSame(1, $transaction->runs);
    }

    public function test_send_fails_when_the_invoice_does_not_exist_and_notifies_nobody(): void
    {
        $id = $this->id();

        $repository = $this->createMock(InvoiceRepositoryInterface::class);
        $repository->method('get')->willThrowException(InvoiceNotFoundException::withId($id));
        $repository->expects($this->never())->method('compareAndSwapStatus');

        $notifier = new SpyNotifier;

        $this->expectException(InvoiceNotFoundException::class);

        try {
            $this->service($repository, $notifier)->send($id);
        } finally {
            self::assertSame(0, $notifier->count());
        }
    }

    #[DataProvider('statusesThatCannotBeSent')]
    public function test_send_fails_when_the_invoice_is_not_a_draft_and_notifies_nobody(StatusEnum $status): void
    {
        $invoice = $this->restored($status);

        $repository = $this->repositoryReturning($invoice);
        $repository->expects($this->never())->method('compareAndSwapStatus');

        $notifier = new SpyNotifier;

        $this->expectException(InvalidStatusTransitionException::class);

        try {
            $this->service($repository, $notifier)->send($invoice->id());
        } finally {
            self::assertSame(0, $notifier->count());
        }
    }

    /** @return iterable<string, array{StatusEnum}> */
    public static function statusesThatCannotBeSent(): iterable
    {
        yield 'already sending' => [StatusEnum::Sending];
        yield 'already sent to client' => [StatusEnum::SentToClient];
    }

    public function test_send_fails_when_the_invoice_has_no_product_lines_and_notifies_nobody(): void
    {
        $invoice = $this->draft(lines: []);

        $repository = $this->repositoryReturning($invoice);
        $repository->expects($this->never())->method('compareAndSwapStatus');

        $notifier = new SpyNotifier;

        $this->expectException(InvoiceWithoutProductLinesException::class);

        try {
            $this->service($repository, $notifier)->send($invoice->id());
        } finally {
            self::assertSame(0, $notifier->count());
        }
    }

    /**
     * Two concurrent requests both read a draft and both clear the in-memory guard;
     * the conditional UPDATE is what decides. The loser must surface as a conflict,
     * and it must not have notified anybody — that is the whole reason the claim
     * comes before the provider call.
     */
    public function test_send_reports_a_conflict_when_another_request_won_the_race(): void
    {
        $invoice = $this->draft();

        $repository = $this->repositoryStub($invoice);
        $repository->method('compareAndSwapStatus')->willReturn(false);

        $notifier = new SpyNotifier;

        $this->expectException(InvalidStatusTransitionException::class);
        $this->expectExceptionMessageMatches('/modified by another request/');

        try {
            $this->service($repository, $notifier)->send($invoice->id());
        } finally {
            self::assertSame(0, $notifier->count());
        }
    }

    public function test_send_propagates_a_notification_failure_so_the_transaction_rolls_back(): void
    {
        $invoice = $this->draft();

        $repository = $this->repositoryStub($invoice);
        $repository->method('compareAndSwapStatus')->willReturn(true);

        // The claim has already landed in this transaction, so nothing is left to
        // compensate by hand: the rollback takes the status back to draft with it,
        // which is why the state machine needs no "un-send" transition.
        $this->expectException(InvoiceSendFailedException::class);

        $this->service($repository, new SpyNotifier(shouldFail: true))->send($invoice->id());
    }

    private function service(InvoiceRepositoryInterface $repository, SpyNotifier $notifier): SendInvoiceService
    {
        return new SendInvoiceService($repository, $notifier, new ImmediateTransactionManager);
    }

    /**
     * A stub, not a mock: these tests only need canned answers, and PHPUnit rightly
     * points out that a doubled object with no configured expectations is a stub.
     *
     * @return InvoiceRepositoryInterface&Stub
     */
    private function repositoryStub(Invoice $invoice): InvoiceRepositoryInterface
    {
        $repository = $this->createStub(InvoiceRepositoryInterface::class);
        $repository->method('get')->willReturn($invoice);

        return $repository;
    }

    /** A mock, because these tests assert that a call never happens. */
    private function repositoryReturning(Invoice $invoice): InvoiceRepositoryInterface&MockObject
    {
        $repository = $this->createMock(InvoiceRepositoryInterface::class);
        $repository->method('get')->willReturn($invoice);

        return $repository;
    }

    /** @param list<ProductLine>|null $lines */
    private function draft(?array $lines = null): Invoice
    {
        return Invoice::draft(
            customerName: 'Ada Lovelace',
            customerEmail: 'ada@example.com',
            productLines: ProductLineCollection::fromArray(
                $lines ?? [ProductLine::create(name: 'Widget', quantity: 1, unitPrice: 500)],
            ),
            id: $this->id(),
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
        return Uuid::fromString('0191b1f0-2222-7222-8222-222222222222');
    }
}
