<?php

declare(strict_types=1);

namespace Tests\Feature\Invoices\Infrastructure;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Infrastructure\Notifications\ProcessOutboxMessageJob;
use Modules\Invoices\Infrastructure\Persistence\Eloquent\OutboxStatus;
use Modules\Invoices\Infrastructure\Persistence\OutboxRepository;
use Ramsey\Uuid\Uuid;
use Tests\Support\Invoices\CreatesInvoices;
use Tests\TestCase;

/**
 * What happens to an outbox row after the queue has given up on it.
 *
 * Until these commands existed, `failed` was a dead end: the invoice stayed in
 * `sending` for ever, `invoices:reconcile` reported it as critical on every run, and
 * the only way to act on that was raw SQL. Separately, nothing ever pruned a row, so
 * the payload — the customer's name, e-mail and the message body — accumulated
 * indefinitely as a second copy of personal data outside any invoice retention policy.
 */
final class OutboxLifecycleTest extends TestCase
{
    use CreatesInvoices;

    public function test_retry_puts_a_failed_message_back_in_the_queue(): void
    {
        Queue::fake();
        $messageId = $this->failedMessage();

        $this->artisanFor('invoices:outbox:retry', ['id' => $messageId])->assertSuccessful();

        self::assertSame(OutboxStatus::Pending->value, $this->statusOf($messageId));
        Queue::assertPushed(ProcessOutboxMessageJob::class, 1);
    }

    /**
     * Silence here would be the wrong answer: a mistyped id, a message already in
     * flight and one somebody deliberately abandoned are all things the operator has
     * to know about before they walk away believing it is fixed.
     */
    public function test_retry_refuses_anything_that_is_not_permanently_failed(): void
    {
        Queue::fake();
        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $pending = $this->enqueue($invoice->id()->toString());

        $this->artisanFor('invoices:outbox:retry', ['id' => $pending])->assertFailed();
        $this->artisanFor('invoices:outbox:retry', ['id' => Uuid::uuid4()->toString()])->assertFailed();

        self::assertSame(OutboxStatus::Pending->value, $this->statusOf($pending));
        Queue::assertNothingPushed();
    }

    public function test_retry_all_re_drives_every_failed_message(): void
    {
        Queue::fake();
        $first = $this->failedMessage();
        $second = $this->failedMessage();

        $this->artisanFor('invoices:outbox:retry', ['--all' => true])->assertSuccessful();

        self::assertSame(OutboxStatus::Pending->value, $this->statusOf($first));
        self::assertSame(OutboxStatus::Pending->value, $this->statusOf($second));
        Queue::assertPushed(ProcessOutboxMessageJob::class, 2);
    }

    /** Neither an id nor --all is a request to guess, so it is rejected rather than interpreted. */
    public function test_retry_without_a_target_is_rejected(): void
    {
        $this->artisanFor('invoices:outbox:retry')->assertExitCode(Command::INVALID);
    }

    /**
     * The per-run bound has to announce itself. A run that silently stops at its limit
     * looks exactly like one with nothing left to do, and the remaining backlog is
     * never noticed.
     */
    public function test_retry_all_reports_when_it_hits_its_per_run_limit(): void
    {
        Queue::fake();
        $this->failedMessage();
        $this->failedMessage();

        $this->artisanFor('invoices:outbox:retry', ['--all' => true, '--limit' => '1'])
            ->expectsOutputToContain('1 permanently failed notification(s) re-dispatched')
            ->expectsOutputToContain('Hit the per-run limit')
            ->assertSuccessful();

        Queue::assertPushed(ProcessOutboxMessageJob::class, 1);
    }

    public function test_abandon_records_the_reason_and_closes_the_message(): void
    {
        $messageId = $this->failedMessage();

        $this->artisanFor('invoices:outbox:abandon', [
            'id' => $messageId,
            '--reason' => 'Customer closed their account; no address to deliver to.',
        ])->assertSuccessful();

        $message = $this->outbox()->find($messageId);
        self::assertNotNull($message);
        self::assertSame(OutboxStatus::Abandoned->value, $message->status);
        self::assertStringContainsString('closed their account', (string) $message->resolution);
    }

    /**
     * The reason is the whole difference between closing a problem and hiding one, so
     * the command refuses to proceed without it.
     */
    public function test_abandon_requires_a_reason(): void
    {
        $messageId = $this->failedMessage();

        $this->artisanFor('invoices:outbox:abandon', ['id' => $messageId])->assertExitCode(Command::INVALID);

        self::assertSame(OutboxStatus::Failed->value, $this->statusOf($messageId));
    }

    /** Abandoning something that is not failed would be hiding a live problem, not closing one. */
    public function test_abandon_refuses_anything_that_is_not_permanently_failed(): void
    {
        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $pending = $this->enqueue($invoice->id()->toString());

        $this->artisanFor('invoices:outbox:abandon', [
            'id' => $pending,
            '--reason' => 'trying to silence something still in flight',
        ])->assertFailed();

        self::assertSame(OutboxStatus::Pending->value, $this->statusOf($pending));
    }

    /**
     * The point of the whole status: an acknowledged message must stop shouting. The
     * row survives, so the record that it happened is not traded away for quiet.
     */
    public function test_an_abandoned_message_stops_being_reported_as_critical(): void
    {
        Queue::fake();
        $messageId = $this->failedMessage();

        $this->artisanFor('invoices:outbox:abandon', [
            'id' => $messageId,
            '--reason' => 'Duplicate of an invoice already delivered by hand.',
        ])->assertSuccessful();

        $logger = $this->expectLogger();
        $logger->expects($this->never())->method('critical');

        $this->artisanFor('invoices:reconcile')->assertSuccessful();

        $this->assertDatabaseHas('invoice_notification_outbox', ['id' => $messageId]);
    }

    public function test_prune_redacts_resolved_payloads_past_the_window(): void
    {
        $messageId = $this->processedMessage();
        $this->age($messageId, days: 60);

        $this->artisanFor('invoices:outbox:prune', ['--days' => '30'])->assertSuccessful();

        $message = $this->outbox()->find($messageId);
        self::assertNotNull($message);
        self::assertSame([], $message->payload);
        self::assertNotNull($message->redacted_at);

        // The row — and with it the record that this invoice was notified at all —
        // outlives the message body deliberately.
        self::assertSame(OutboxStatus::Processed->value, $message->status);
        self::assertNotNull($message->processed_at);
    }

    public function test_prune_leaves_recent_payloads_alone(): void
    {
        $messageId = $this->processedMessage();

        $this->artisanFor('invoices:outbox:prune', ['--days' => '30'])->assertSuccessful();

        $message = $this->outbox()->find($messageId);
        self::assertNotNull($message);
        self::assertArrayHasKey('to_email', $message->payload);
        self::assertNull($message->redacted_at);
    }

    /**
     * A failed message is terminal but unresolved. Redacting it would destroy the
     * evidence of what the provider said, which is the one thing whoever has to
     * diagnose it will want.
     */
    public function test_prune_never_touches_a_message_that_still_needs_attention(): void
    {
        $messageId = $this->failedMessage();
        $this->age($messageId, days: 365);

        $this->artisanFor('invoices:outbox:prune', ['--days' => '30'])->assertSuccessful();

        $message = $this->outbox()->find($messageId);
        self::assertNotNull($message);
        self::assertArrayHasKey('to_email', $message->payload);
        self::assertNull($message->redacted_at);
    }

    /** Same reasoning as the retry bound: a truncated run must say so. */
    public function test_prune_reports_when_it_hits_its_per_run_limit(): void
    {
        $first = $this->processedMessage();
        $second = $this->processedMessage();
        $this->age($first, days: 60);
        $this->age($second, days: 60);

        $this->artisanFor('invoices:outbox:prune', ['--days' => '30', '--limit' => '1'])
            ->expectsOutputToContain('1 resolved notification payload(s) redacted')
            ->expectsOutputToContain('Hit the per-run limit')
            ->assertSuccessful();
    }

    /** redacted_at is what stops a scheduled run redacting the same rows for ever. */
    public function test_prune_does_not_revisit_rows_it_has_already_redacted(): void
    {
        $messageId = $this->processedMessage();
        $this->age($messageId, days: 60);

        $this->artisanFor('invoices:outbox:prune', ['--days' => '30'])->assertSuccessful();
        $first = $this->outbox()->find($messageId)?->redacted_at;

        $this->artisanFor('invoices:outbox:prune', ['--days' => '30'])
            ->expectsOutputToContain('0 resolved notification payload(s) redacted')
            ->assertSuccessful();

        self::assertEquals($first, $this->outbox()->find($messageId)?->redacted_at);
    }

    /** The option wins when given; otherwise the configured window has to be the one that applies. */
    public function test_prune_falls_back_to_the_configured_retention_window(): void
    {
        config()->set('invoices.retain_payload_days', 0);

        $messageId = $this->processedMessage();

        $this->artisanFor('invoices:outbox:prune')->assertSuccessful();

        self::assertNotNull($this->outbox()->find($messageId)?->redacted_at);
    }

    /** @param array<string, bool|string> $arguments */
    private function artisanFor(string $command, array $arguments = []): PendingCommand
    {
        $pending = $this->artisan($command, $arguments);

        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    private function failedMessage(): string
    {
        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $messageId = $this->enqueue($invoice->id()->toString());

        $this->outbox()->markFailed($messageId, 'provider refused the message');

        return $messageId;
    }

    private function processedMessage(): string
    {
        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $messageId = $this->enqueue($invoice->id()->toString());

        $this->outbox()->markProcessed($messageId);

        return $messageId;
    }

    private function enqueue(string $invoiceId): string
    {
        return $this->outbox()->enqueue(Uuid::fromString($invoiceId), [
            'to_email' => 'ada@example.com',
            'subject' => 'Your invoice is on its way',
            'message' => 'Hello Ada Lovelace, your invoice is being sent.',
        ]);
    }

    private function age(string $messageId, int $days): void
    {
        DB::table('invoice_notification_outbox')
            ->where('id', $messageId)
            ->update(['updated_at' => now()->subDays($days)]);
    }

    private function statusOf(string $messageId): string
    {
        return (string) $this->outbox()->find($messageId)?->status;
    }

    private function outbox(): OutboxRepository
    {
        return $this->app->make(OutboxRepository::class);
    }
}
