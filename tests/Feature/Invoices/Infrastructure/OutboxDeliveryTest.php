<?php

declare(strict_types=1);

namespace Tests\Feature\Invoices\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Infrastructure\Console\ReconcileInvoiceSendingCommand;
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

        $this->age($messageId);

        $this->reconcile()->assertSuccessful();

        Queue::assertPushed(ProcessOutboxMessageJob::class, 1);
    }

    /**
     * A worker killed between claiming a row and settling it leaves the claim
     * behind. Nothing will ever release it, so the reconciler has to treat a stale
     * `processing` row as stalled too — otherwise the one failure mode the outbox
     * exists to survive would strand the message permanently.
     */
    public function test_reconcile_recovers_a_message_abandoned_mid_delivery(): void
    {
        Queue::fake();

        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $messageId = $this->enqueue($invoice->id()->toString());

        self::assertTrue($this->outbox()->claim($messageId));
        $this->age($messageId);

        $this->reconcile()->assertSuccessful();

        Queue::assertPushed(ProcessOutboxMessageJob::class, 1);
    }

    /**
     * The claim is what makes delivery exactly-once rather than at-least-once under
     * a provider call slower than the queue's retry_after: the queue releases the
     * job while the first worker is still inside notify(), and a status read would
     * let the second worker through.
     */
    public function test_a_message_already_in_flight_cannot_be_claimed_again(): void
    {
        $driver = $this->fakeNotificationDriver();
        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $messageId = $this->enqueue($invoice->id()->toString());

        self::assertTrue($this->outbox()->claim($messageId));
        self::assertFalse($this->outbox()->claim($messageId));

        // A second worker running the job now must find the row taken and leave.
        $this->runJob($messageId);

        self::assertSame(0, $driver->count());
    }

    /**
     * Terminal writes are guarded, so the row's final state is not decided by write
     * order. Without the guard a slow attempt that exhausts its retries would flip a
     * genuinely delivered message to `failed`, and the reconciler would then report
     * it as critical on every run, forever.
     */
    public function test_a_late_failure_cannot_overwrite_a_delivered_message(): void
    {
        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $messageId = $this->enqueue($invoice->id()->toString());

        $this->outbox()->markProcessed($messageId);
        $this->outbox()->markFailed($messageId, 'a straggler attempt gave up');

        $message = $this->outbox()->find($messageId);
        self::assertNotNull($message);
        self::assertSame(OutboxStatus::Processed->value, $message->status);
        self::assertNull($message->last_error);
    }

    /**
     * The option is absent here, so the configured threshold is the only thing that
     * can make a freshly written message count as stalled. That is the assertion:
     * INVOICE_RECONCILE_AFTER_MINUTES has to actually reach the query.
     */
    public function test_reconcile_falls_back_to_the_configured_threshold(): void
    {
        Queue::fake();
        config()->set('invoices.reconcile_after_minutes', 0);

        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $this->enqueue($invoice->id()->toString());

        $this->reconcile()->assertSuccessful();

        Queue::assertPushed(ProcessOutboxMessageJob::class, 1);
    }

    /**
     * The other half of the precedence rule: an explicit option must beat the
     * configured value, or the end-to-end gate could not force a zero threshold.
     */
    public function test_an_explicit_minutes_option_beats_the_configured_threshold(): void
    {
        Queue::fake();
        config()->set('invoices.reconcile_after_minutes', 10_000);

        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $this->enqueue($invoice->id()->toString());

        $this->reconcile(['--minutes' => '0'])->assertSuccessful();

        Queue::assertPushed(ProcessOutboxMessageJob::class, 1);
    }

    /**
     * Nothing prunes a failed row, so the per-row logging is capped: an unbounded
     * report would re-log the entire history on every run and drown the signal
     * exactly as the problem grows. The total still has to be reported, otherwise
     * the cap would hide it.
     */
    public function test_reconcile_caps_the_rows_it_names_but_still_reports_the_total(): void
    {
        Queue::fake();

        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $overTheCap = ReconcileInvoiceSendingCommand::FAILED_REPORT_LIMIT + 1;

        for ($i = 0; $i < $overTheCap; $i++) {
            $this->outbox()->markFailed($this->enqueue($invoice->id()->toString()), "attempt {$i} gave up");
        }

        $logger = $this->recordingLogger();

        $this->reconcile()->assertSuccessful();

        self::assertCount(
            ReconcileInvoiceSendingCommand::FAILED_REPORT_LIMIT,
            $logger->withMessage('Invoice notification needs attention.'),
        );

        $summary = $logger->withMessage('More permanently failed notifications than this run reported.');
        self::assertCount(1, $summary);
        self::assertSame($overTheCap, $summary[0]['context']['total']);
    }

    /** Scheduled runs stay quiet; --strict turns the same information into a gate. */
    public function test_reconcile_is_quiet_by_default_but_fails_in_strict_mode(): void
    {
        Queue::fake();

        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $messageId = $this->enqueue($invoice->id()->toString());
        $this->outbox()->markFailed($messageId, 'provider gone');

        $this->reconcile()->assertSuccessful();
        $this->reconcile(['--strict' => true])->assertFailed();
    }

    /**
     * The orphan probe assumes a row per send, which only the outbox adapter
     * promises. Under `direct` every invoice legitimately waiting for its delivery
     * webhook has no row, so reporting them would make the one signal worth alerting
     * on fire on every single run.
     */
    public function test_reconcile_does_not_report_orphans_under_the_direct_notifier(): void
    {
        Queue::fake();
        config()->set('invoices.notifier', 'direct');

        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);

        DB::table('invoices')
            ->where('id', $invoice->id()->toString())
            ->update(['updated_at' => now()->subHour()]);

        $logger = $this->expectLogger();
        $logger->expects($this->never())->method('critical');

        $this->reconcile()->assertSuccessful();
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

    /** @param array<string, bool|string> $options */
    private function reconcile(array $options = []): PendingCommand
    {
        $pending = $this->artisan('invoices:reconcile', $options);

        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    /**
     * Pushes a row past the staleness threshold. Both timestamps move: the query
     * filters on updated_at, because "stalled" means nothing has happened to this
     * row lately rather than that it was created a while ago.
     */
    private function age(string $messageId): void
    {
        DB::table('invoice_notification_outbox')
            ->where('id', $messageId)
            ->update([
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour(),
            ]);
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
