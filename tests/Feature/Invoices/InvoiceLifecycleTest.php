<?php

declare(strict_types=1);

namespace Tests\Feature\Invoices;

use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Infrastructure\Persistence\Eloquent\OutboxStatus;
use Tests\Support\Invoices\CreatesInvoices;
use Tests\TestCase;

/**
 * The test a reviewer should read first: the whole specification as one story,
 * driven only through the public surface — three endpoints and the delivery hook.
 */
final class InvoiceLifecycleTest extends TestCase
{
    use CreatesInvoices;

    public function test_an_invoice_travels_from_draft_to_sent_to_client(): void
    {
        $driver = $this->fakeNotificationDriver();

        // 1. Created as a draft.
        $created = $this->postJson(route('invoices.create'), [
            'customer_name' => 'Ada Lovelace',
            'customer_email' => 'ada@example.com',
            'product_lines' => [
                ['name' => 'Analytical Engine', 'quantity' => 1, 'unit_price' => 100_000],
                ['name' => 'Punch cards', 'quantity' => 12, 'unit_price' => 250],
            ],
        ])->assertCreated();

        /** @var string $id */
        $id = $created->json('data.id');

        $created->assertJsonPath('data.status', StatusEnum::Draft->value)
            ->assertJsonPath('data.product_lines.0.total_unit_price', 100_000)
            ->assertJsonPath('data.product_lines.1.total_unit_price', 3_000)
            ->assertJsonPath('data.total_price', 103_000);

        // 2. Readable with derived totals.
        $this->getJson(route('invoices.view', ['invoiceId' => $id]))
            ->assertOk()
            ->assertJsonPath('data.total_price', 103_000);

        // 3. Sent: the intent commits with the status change and the worker drains it.
        $this->postJson(route('invoices.send', ['invoiceId' => $id]))
            ->assertAccepted()
            ->assertJsonPath('data.status', StatusEnum::Sending->value);

        self::assertSame(1, $driver->count());
        self::assertSame($id, $driver->first()['reference']);

        $this->assertDatabaseHas('invoice_notification_outbox', [
            'invoice_id' => $id,
            'status' => OutboxStatus::Processed->value,
        ]);

        // 4. The provider confirms delivery on the webhook, quoting the reference it
        //    was given — which is how the listener finds the invoice again.
        $this->getJson(route('notification.hook', [
            'action' => 'delivered',
            'reference' => $driver->first()['reference'],
        ]))->assertNoContent();

        // 5. Terminal state, with the money untouched by the journey.
        $this->getJson(route('invoices.view', ['invoiceId' => $id]))
            ->assertOk()
            ->assertJsonPath('data.status', StatusEnum::SentToClient->value)
            ->assertJsonPath('data.total_price', 103_000);

        // 6. And it cannot be sent again.
        $this->postJson(route('invoices.send', ['invoiceId' => $id]))->assertConflict();

        self::assertSame(1, $driver->count());
    }
}
