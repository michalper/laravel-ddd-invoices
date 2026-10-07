<?php

declare(strict_types=1);

namespace Tests\Feature\Invoices\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Infrastructure\Notifications\ProcessOutboxMessageJob;
use Modules\Invoices\Infrastructure\Persistence\Eloquent\OutboxStatus;
use Modules\Invoices\Infrastructure\Persistence\OutboxRepository;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Tests\Support\Invoices\CreatesInvoices;
use Tests\TestCase;

final class OutboxDeliveryTest extends TestCase
{
    use CreatesInvoices;

    public function test_the_job_delivers_a_pending_message_and_settles_it(): void
    {
        $driver = $this->fakeNotificationDriver();
        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $messageId = $this->enqueue($invoice->id()->toString());

        $this->runJob($messageId);

        self::assertSame(1, $driver->count());
        self::assertSame($invoice->id()->toString(), $driver->first()['reference']);

        $this->assertDatabaseHas('invoice_notification_outbox', [
            'id' => $messageId,
            'status' => OutboxStatus::Processed->value,
        ]);
        self::assertNotNull($this->outbox()->find($messageId)?->processed_at);
    }

    /** A redelivered job must not notify the customer twice. */
    public function test_the_job_is_idempotent(): void
    {
        $driver = $this->fakeNotificationDriver();
        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $messageId = $this->enqueue($invoice->id()->toString());

        $this->runJob($messageId);
        $this->runJob($messageId);

        self::assertSame(1, $driver->count());
    }

    public function test_the_job_ignores_a_message_that_no_longer_exists(): void
    {
        $driver = $this->fakeNotificationDriver();

        $this->runJob(Uuid::uuid4()->toString());

        self::assertSame(0, $driver->count());
    }

    /**
     * A provider failure must be recorded and rethrown, so the queue applies the
     * backoff instead of silently dropping the message.
     */
    public function test_a_provider_failure_is_recorded_and_rethrown_for_retry(): void
    {
        $driver = $this->failingNotificationDriver('smtp refused');
        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $messageId = $this->enqueue($invoice->id()->toString());

        try {
            $this->runJob($messageId);
            self::fail('The job should rethrow so the queue can retry it.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('smtp refused', $e->getMessage());
        }

        self::assertSame(1, $driver->calls);

        $message = $this->outbox()->find($messageId);
        self::assertNotNull($message);
        self::assertSame(OutboxStatus::Pending->value, $message->status);
        self::assertSame(1, $message->attempts);
        self::assertStringContainsString('smtp refused', (string) $message->last_error);
    }

    public function test_exhausting_the_retries_settles_the_message_as_failed(): void
    {
        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $messageId = $this->enqueue($invoice->id()->toString());

        new ProcessOutboxMessageJob($messageId)->failed(new RuntimeException('provider gone'));

        $message = $this->outbox()->find($messageId);
        self::assertNotNull($message);
        self::assertSame(OutboxStatus::Failed->value, $message->status);
        self::assertStringContainsString('provider gone', (string) $message->last_error);
    }

    public function test_reconcile_redispatches_a_stalled_message(): void
    {
        Queue::fake();

        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $messageId = $this->enqueue($invoice->id()->toString());

        // Age the message past the threshold.
        DB::table('invoice_notification_outbox')
            ->where('id', $messageId)
            ->update(['created_at' => now()->subHour()]);

        $this->reconcile()->assertSuccessful();

        Queue::assertPushed(ProcessOutboxMessageJob::class, 1);
    }

    public function test_reconcile_leaves_a_fresh_message_alone(): void
    {
        Queue::fake();

        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $this->enqueue($invoice->id()->toString());

        $this->reconcile()->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_reconcile_reports_a_permanently_failed_message(): void
    {
        Queue::fake();
        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $messageId = $this->enqueue($invoice->id()->toString());
        $this->outbox()->markFailed($messageId, 'provider gone');

        $logger = $this->expectLogger();
        $logger->expects($this->atLeastOnce())->method('critical');

        $this->reconcile()->assertSuccessful();
    }

    /**
     * An invoice claiming to be sending with no notification record at all should be
     * impossible, since both writes share a transaction — which is exactly why it is
     * worth alerting on.
     */
    public function test_reconcile_reports_an_invoice_sending_without_any_notification_record(): void
    {
        Queue::fake();
        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);

        DB::table('invoices')
            ->where('id', $invoice->id()->toString())
            ->update(['updated_at' => now()->subHour()]);

        $logger = $this->expectLogger();
        $logger->expects($this->atLeastOnce())->method('critical');

        $this->reconcile()->assertSuccessful();
    }

    /**
     * The column is bounded, provider errors are not. Asserting the exact boundary
     * with multibyte input pins both the limit and the use of mb_substr.
     */
    public function test_a_long_provider_error_is_truncated_to_the_column_limit(): void
    {
        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $messageId = $this->enqueue($invoice->id()->toString());

        $this->outbox()->recordAttempt($messageId, str_repeat('ą', 5_000));

        $message = $this->outbox()->find($messageId);
        self::assertNotNull($message);
        self::assertSame(OutboxRepository::MAX_ERROR_LENGTH, mb_strlen((string) $message->last_error));
    }

    private function reconcile(): PendingCommand
    {
        $pending = $this->artisan('invoices:reconcile');

        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    private function enqueue(string $invoiceId): string
    {
        return $this->outbox()->enqueue(Uuid::fromString($invoiceId), [
            'to_email' => 'ada@example.com',
            'subject' => 'Your invoice is on its way',
            'message' => 'Hello Ada Lovelace, your invoice is being sent.',
        ]);
    }

    private function runJob(string $messageId): void
    {
        $this->app->call([new ProcessOutboxMessageJob($messageId), 'handle']);
    }

    private function outbox(): OutboxRepository
    {
        return $this->app->make(OutboxRepository::class);
    }
}
