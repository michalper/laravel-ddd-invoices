<?php

declare(strict_types=1);

namespace Tests\E2E;

use Ramsey\Uuid\Uuid;

final class InvoiceLifecycleE2ETest extends E2ETestCase
{
    public function test_an_invoice_travels_from_draft_to_sent_to_client_over_http(): void
    {
        $created = $this->createInvoice([
            ['name' => 'Analytical Engine', 'quantity' => 1, 'unit_price' => 100_000],
            ['name' => 'Punch cards', 'quantity' => 12, 'unit_price' => 250],
        ]);

        self::assertSame(201, $created->getStatusCode());

        $invoice = $this->data($created);
        $id = $this->invoiceId($invoice);

        self::assertSame('draft', $this->str($invoice, 'status'));
        self::assertSame(103_000, $this->int($invoice, 'total_price'));
        self::assertNotSame('', $created->getHeaderLine('Location'));

        // Readable, with the per-line and invoice totals derived on the way out.
        $viewed = $this->data($this->call('GET', "api/invoices/{$id}"));
        self::assertSame(103_000, $this->int($viewed, 'total_price'));
        self::assertSame(100_000, $this->int($this->productLines($viewed)[0], 'total_unit_price'));

        // Accepted, not completed: the terminal state arrives over the webhook.
        $sent = $this->call('POST', "api/invoices/{$id}/send");
        self::assertSame(202, $sent->getStatusCode());
        self::assertSame('sending', $this->str($this->data($sent), 'status'));

        // The provider confirms delivery, quoting the reference it was handed.
        $hook = $this->call('GET', "api/notification/hook/delivered/{$id}");
        self::assertSame(204, $hook->getStatusCode());

        self::assertSame('sent-to-client', $this->awaitStatus($id, 'sent-to-client'));

        // The money survived the journey.
        self::assertSame(103_000, $this->int($this->data($this->call('GET', "api/invoices/{$id}")), 'total_price'));
    }

    public function test_an_invoice_cannot_be_sent_twice(): void
    {
        $id = $this->invoiceId($this->data($this->createInvoice([
            ['name' => 'Widget', 'quantity' => 1, 'unit_price' => 500],
        ])));

        self::assertSame(202, $this->call('POST', "api/invoices/{$id}/send")->getStatusCode());
        self::assertSame(409, $this->call('POST', "api/invoices/{$id}/send")->getStatusCode());
    }

    public function test_an_invoice_without_product_lines_cannot_be_sent(): void
    {
        $id = $this->invoiceId($this->data($this->createInvoice([])));

        $response = $this->call('POST', "api/invoices/{$id}/send");

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('draft', $this->str($this->data($this->call('GET', "api/invoices/{$id}")), 'status'));
    }

    public function test_a_duplicate_delivery_webhook_is_idempotent(): void
    {
        $id = $this->invoiceId($this->data($this->createInvoice([
            ['name' => 'Widget', 'quantity' => 1, 'unit_price' => 500],
        ])));

        $this->call('POST', "api/invoices/{$id}/send");

        self::assertSame(204, $this->call('GET', "api/notification/hook/delivered/{$id}")->getStatusCode());
        self::assertSame('sent-to-client', $this->awaitStatus($id, 'sent-to-client'));

        self::assertSame(204, $this->call('GET', "api/notification/hook/delivered/{$id}")->getStatusCode());
        self::assertSame('sent-to-client', $this->str($this->data($this->call('GET', "api/invoices/{$id}")), 'status'));
    }

    /** Not retryable, so the hook accepts it rather than inviting a retry storm. */
    public function test_a_delivery_webhook_for_an_unknown_resource_is_accepted(): void
    {
        $response = $this->call('GET', 'api/notification/hook/delivered/'.Uuid::uuid4()->toString());

        self::assertSame(204, $response->getStatusCode());
    }

    public function test_an_unknown_invoice_is_not_found(): void
    {
        $response = $this->call('GET', 'api/invoices/'.Uuid::uuid4()->toString());

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('message', $this->decode($response));
    }

    public function test_a_malformed_identifier_is_not_found(): void
    {
        self::assertSame(404, $this->call('GET', 'api/invoices/not-a-uuid')->getStatusCode());
    }

    public function test_an_invalid_payload_is_rejected_with_field_errors(): void
    {
        $response = $this->call('POST', 'api/invoices', [
            'customer_name' => '',
            'customer_email' => 'not-an-email',
            'product_lines' => [['name' => 'Widget', 'quantity' => 0, 'unit_price' => 100]],
        ]);

        self::assertSame(422, $response->getStatusCode());

        $decoded = $this->decode($response);
        self::assertArrayHasKey('errors', $decoded);
        self::assertIsArray($decoded['errors']);
        self::assertArrayHasKey('customer_name', $decoded['errors']);
        self::assertArrayHasKey('customer_email', $decoded['errors']);
        self::assertArrayHasKey('product_lines.0.quantity', $decoded['errors']);
    }

    /**
     * Errors from an API route must be JSON even without an Accept header, which is
     * only observable against a real server.
     */
    public function test_api_errors_are_json_without_an_accept_header(): void
    {
        $response = $this->http->request('GET', 'api/invoices/'.Uuid::uuid4()->toString(), [
            'headers' => ['Accept' => '*/*'],
        ]);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }
}
