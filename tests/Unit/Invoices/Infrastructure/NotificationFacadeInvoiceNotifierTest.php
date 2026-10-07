<?php

declare(strict_types=1);

namespace Tests\Unit\Invoices\Infrastructure;

use Modules\Invoices\Application\Exceptions\InvoiceSendFailedException;
use Modules\Invoices\Domain\Entities\Invoice;
use Modules\Invoices\Domain\ValueObjects\ProductLine;
use Modules\Invoices\Domain\ValueObjects\ProductLineCollection;
use Modules\Invoices\Infrastructure\Notifications\NotificationFacadeInvoiceNotifier;
use Modules\Notifications\Api\Data\NotifyData;
use Modules\Notifications\Api\NotificationFacadeInterface;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * The one place a NotificationFacadeInterface double is the right tool: this is the
 * adapter whose whole job is building that module's DTO.
 */
final class NotificationFacadeInvoiceNotifierTest extends TestCase
{
    public function test_it_sends_the_invoice_id_as_the_resource_identifier(): void
    {
        $invoice = $this->invoice();

        $facade = $this->createMock(NotificationFacadeInterface::class);

        $captured = null;
        $facade->expects($this->once())
            ->method('notify')
            ->willReturnCallback(function (NotifyData $data) use (&$captured): void {
                $captured = $data;
            });

        new NotificationFacadeInvoiceNotifier($facade)->notifyInvoiceSent($invoice);

        self::assertInstanceOf(NotifyData::class, $captured);

        // This is the link that closes the loop: the provider echoes resourceId back
        // as `reference` on the delivery webhook, which is how the listener finds the
        // invoice again.
        self::assertSame($invoice->id()->toString(), $captured->resourceId->toString());
        self::assertSame('ada@example.com', $captured->toEmail);
        self::assertNotSame('', $captured->subject);
        self::assertStringContainsString('Ada Lovelace', $captured->message);
    }

    /**
     * The cause must survive for the log but must not reach the message, because
     * Presentation returns getMessage() in the 502 body and a renderable() callback
     * is not subject to APP_DEBUG masking. Asserting both halves pins the split:
     * the client gets a stable sentence, the operator gets the provider's words.
     */
    public function test_a_provider_failure_becomes_a_domain_level_send_failure(): void
    {
        $cause = new RuntimeException('SMTP connect failed: smtp.internal:587');

        $facade = $this->createStub(NotificationFacadeInterface::class);
        $facade->method('notify')->willThrowException($cause);

        $invoice = $this->invoice();

        try {
            new NotificationFacadeInvoiceNotifier($facade)->notifyInvoiceSent($invoice);
            self::fail('A provider refusal should surface as '.InvoiceSendFailedException::class.'.');
        } catch (InvoiceSendFailedException $e) {
            self::assertStringContainsString($invoice->id()->toString(), $e->getMessage());
            self::assertStringNotContainsString('smtp.internal', $e->getMessage());
            self::assertSame($cause, $e->getPrevious());
        }
    }

    private function invoice(): Invoice
    {
        return Invoice::draft(
            customerName: 'Ada Lovelace',
            customerEmail: 'ada@example.com',
            productLines: ProductLineCollection::fromArray([
                ProductLine::create(name: 'Widget', quantity: 2, unitPrice: 500),
            ]),
            id: Uuid::fromString('0191b1f0-4444-7444-8444-444444444444'),
        );
    }
}
