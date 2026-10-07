<?php

declare(strict_types=1);

namespace Tests\Feature\Invoices\Http;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Infrastructure\Notifications\ProcessOutboxMessageJob;
use Modules\Invoices\Infrastructure\Persistence\Eloquent\OutboxStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\Support\Invoices\CreatesInvoices;
use Tests\TestCase;

final class SendInvoiceTest extends TestCase
{
    use CreatesInvoices;

    /**
     * The queue is faked here so the intent can be observed in the state it is
     * committed in. Without faking, the worker drains it inside the same test — see
     * testSendDeliversThroughTheOutboxWorker below.
     */
    public function test_send_claims_the_invoice_and_commits_the_intent_as_pending(): void
    {
        Queue::fake();

        $invoice = $this->persistedDraft();

        $this->postJson(route('invoices.send', ['invoiceId' => $invoice->id()->toString()]))
            ->assertAccepted()
            ->assertJsonPath('data.status', 'sending')
            ->assertJsonPath('data.total_price', 2500);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id()->toString(),
            'status' => StatusEnum::Sending->value,
        ]);

        // The intent is durable and commits with the status change, so the two
        // cannot disagree.
        $this->assertDatabaseHas('invoice_notification_outbox', [
            'invoice_id' => $invoice->id()->toString(),
            'status' => OutboxStatus::Pending->value,
            'attempts' => 0,
        ]);

        Queue::assertPushed(ProcessOutboxMessageJob::class, 1);
    }

    public function test_send_records_the_customer_email_in_the_payload(): void
    {
        Queue::fake();

        $invoice = $this->persistedDraft();

        $this->postJson(route('invoices.send', ['invoiceId' => $invoice->id()->toString()]))
            ->assertAccepted();

        $payload = $this->outboxPayloadFor($invoice->id()->toString());

        self::assertSame('ada@example.com', $payload['to_email']);
        self::assertNotSame('', $payload['subject']);
        self::assertStringContainsString('Ada Lovelace', $payload['message']);
    }

    /**
     * With the queue left alone, the full outbox path runs in-process: Laravel
     * executes afterCommit callbacks immediately under RefreshDatabase, and the
     * testing queue connection is sync. So this asserts the end state the customer
     * actually gets, through the real NotificationFacade.
     */
    public function test_send_delivers_through_the_outbox_worker(): void
    {
        $driver = $this->fakeNotificationDriver();

        $invoice = $this->persistedDraft();

        $this->postJson(route('invoices.send', ['invoiceId' => $invoice->id()->toString()]))
            ->assertAccepted();

        self::assertSame(1, $driver->count());

        // The reference the provider echoes back on the delivery webhook.
        self::assertSame($invoice->id()->toString(), $driver->first()['reference']);
        self::assertSame('ada@example.com', $driver->first()['toEmail']);

        $this->assertDatabaseHas('invoice_notification_outbox', [
            'invoice_id' => $invoice->id()->toString(),
            'status' => OutboxStatus::Processed->value,
        ]);
    }

    public function test_send_refuses_an_invoice_without_product_lines_and_records_nothing(): void
    {
        $invoice = $this->persistedDraft(lines: 0);

        $this->postJson(route('invoices.send', ['invoiceId' => $invoice->id()->toString()]))
            ->assertUnprocessable()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id()->toString(),
            'status' => StatusEnum::Draft->value,
        ]);
        $this->assertDatabaseCount('invoice_notification_outbox', 0);
    }

    public function test_sending_twice_conflicts_and_notifies_only_once(): void
    {
        Queue::fake();

        $invoice = $this->persistedDraft();
        $route = route('invoices.send', ['invoiceId' => $invoice->id()->toString()]);

        $this->postJson($route)->assertAccepted();
        $this->postJson($route)->assertConflict();

        $this->assertDatabaseCount('invoice_notification_outbox', 1);
    }

    #[DataProvider('statusesThatCannotBeSent')]
    public function test_send_conflicts_when_the_invoice_is_not_a_draft(StatusEnum $status): void
    {
        $invoice = $this->persistedInvoiceInStatus($status);

        $this->postJson(route('invoices.send', ['invoiceId' => $invoice->id()->toString()]))
            ->assertConflict();

        $this->assertDatabaseCount('invoice_notification_outbox', 0);
    }

    /** @return iterable<string, array{StatusEnum}> */
    public static function statusesThatCannotBeSent(): iterable
    {
        yield 'already sending' => [StatusEnum::Sending];
        yield 'already sent to client' => [StatusEnum::SentToClient];
    }

    public function test_send_returns_not_found_for_an_unknown_invoice(): void
    {
        $this->postJson(route('invoices.send', ['invoiceId' => Uuid::uuid4()->toString()]))
            ->assertNotFound();
    }

    /**
     * The highest-value test in the suite for "how does this behave when things go
     * wrong": with the direct adapter the provider is reached inside the
     * transaction, so a refusal must leave the invoice exactly as it was, against a
     * real database rather than a mock's expectations.
     */
    public function test_a_provider_refusal_leaves_the_invoice_in_draft_with_nothing_recorded(): void
    {
        config()->set('invoices.notifier', 'direct');

        // A reason shaped like what a real driver leaks: a host and a credential-ish
        // fragment. The 502 body must not carry it. `renderable()` runs before the
        // framework's own rendering, so APP_DEBUG=false would not mask it for us.
        $driver = $this->failingNotificationDriver('SMTP connect failed: smtp.internal:587 user=postmaster');

        $invoice = $this->persistedDraft();

        $response = $this->postJson(route('invoices.send', ['invoiceId' => $invoice->id()->toString()]))
            ->assertStatus(502)
            ->assertJsonStructure(['message']);

        $response->assertJsonMissingPath('exception');

        $message = $response->json('message');
        self::assertIsString($message);
        self::assertStringNotContainsString('smtp.internal', $message);
        self::assertStringNotContainsString('postmaster', $message);

        self::assertSame(1, $driver->calls);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id()->toString(),
            'status' => StatusEnum::Draft->value,
        ]);
        $this->assertDatabaseCount('invoice_notification_outbox', 0);
    }

    public function test_the_direct_adapter_reaches_the_provider_with_the_invoice_id_as_reference(): void
    {
        config()->set('invoices.notifier', 'direct');
        $driver = $this->fakeNotificationDriver();

        $invoice = $this->persistedDraft();

        $this->postJson(route('invoices.send', ['invoiceId' => $invoice->id()->toString()]))
            ->assertAccepted()
            ->assertJsonPath('data.status', 'sending');

        self::assertSame(1, $driver->count());
        self::assertSame($invoice->id()->toString(), $driver->first()['reference']);
        self::assertSame('ada@example.com', $driver->first()['toEmail']);

        // The direct adapter bypasses the outbox entirely.
        $this->assertDatabaseCount('invoice_notification_outbox', 0);
    }

    /** @return array{to_email: string, subject: string, message: string} */
    private function outboxPayloadFor(string $invoiceId): array
    {
        /** @var string $raw */
        $raw = DB::table('invoice_notification_outbox')
            ->where('invoice_id', $invoiceId)
            ->value('payload');

        /** @var array{to_email: string, subject: string, message: string} $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
