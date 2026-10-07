<?php

declare(strict_types=1);

namespace Tests\Feature\Invoices\Delivery;

use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Notifications\Api\Events\WebhookDeliveredEvent;
use Ramsey\Uuid\Uuid;
use Tests\Support\Invoices\CreatesInvoices;
use Tests\TestCase;

final class DeliveryWebhookTest extends TestCase
{
    use CreatesInvoices;

    public function test_delivery_webhook_marks_a_sending_invoice_as_sent_to_client(): void
    {
        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);

        $this->getJson($this->hook($invoice->id()->toString()))->assertNoContent();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id()->toString(),
            'status' => StatusEnum::SentToClient->value,
        ]);
    }

    public function test_a_duplicate_delivery_webhook_is_idempotent(): void
    {
        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);
        $hook = $this->hook($invoice->id()->toString());

        $this->getJson($hook)->assertNoContent();
        $this->getJson($hook)->assertNoContent();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id()->toString(),
            'status' => StatusEnum::SentToClient->value,
        ]);
    }

    /**
     * Not retryable: the invoice will still be a draft on attempt fifty, so the hook
     * accepts the call and records the anomaly instead of inviting a retry storm.
     */
    public function test_delivery_webhook_for_an_invoice_that_was_never_sent_is_accepted_and_warned(): void
    {
        $logger = $this->expectLogger();
        $logger->expects($this->once())->method('warning');

        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Draft);

        $this->getJson($this->hook($invoice->id()->toString()))->assertNoContent();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id()->toString(),
            'status' => StatusEnum::Draft->value,
        ]);

    }

    /**
     * The event carries an opaque resource id and Invoices is one of potentially
     * several listeners, so an id that is not an invoice is simply not addressed to
     * us — not an error.
     */
    public function test_delivery_webhook_for_an_unknown_resource_is_accepted(): void
    {
        $logger = $this->expectLogger();
        $logger->expects($this->never())->method('warning');

        $this->getJson($this->hook(Uuid::uuid4()->toString()))->assertNoContent();
    }

    public function test_delivery_webhook_for_an_already_delivered_invoice_does_not_warn(): void
    {
        $logger = $this->expectLogger();
        $logger->expects($this->never())->method('warning');

        $invoice = $this->persistedInvoiceInStatus(StatusEnum::SentToClient);

        $this->getJson($this->hook($invoice->id()->toString()))->assertNoContent();
    }

    /**
     * Localises the failure that an HTTP-level test would only show indirectly: if
     * InvoiceServiceProvider were ever made deferrable, nothing in a webhook request
     * would resolve from this module, boot() would never run, and deliveries would
     * stop working while still answering 204.
     */
    public function test_the_listener_is_registered_for_the_module_event(): void
    {
        $invoice = $this->persistedInvoiceInStatus(StatusEnum::Sending);

        event(new WebhookDeliveredEvent(resourceId: $invoice->id()));

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id()->toString(),
            'status' => StatusEnum::SentToClient->value,
        ]);
    }

    private function hook(string $reference): string
    {
        return route('notification.hook', ['action' => 'delivered', 'reference' => $reference]);
    }
}
