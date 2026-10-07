<?php

declare(strict_types=1);

namespace Tests\Feature\Invoices\Infrastructure;

use InvalidArgumentException;
use Modules\Invoices\Application\Ports\InvoiceNotifierInterface;
use Modules\Invoices\Infrastructure\Notifications\NotificationFacadeInvoiceNotifier;
use Modules\Invoices\Infrastructure\Notifications\OutboxInvoiceNotifier;
use Tests\TestCase;

/**
 * The INVOICE_NOTIFIER switch, including the case that used to be silent.
 *
 * Two places read this setting, and before validation they fell back in opposite
 * directions: the container binding treated everything except 'direct' as outbox,
 * while the reconcile command treats everything except 'outbox' as not-outbox. A
 * typo like `Outbox` therefore ran the outbox adapter while silently disabling the
 * orphan probe — the one alert that assumption-breakage depends on. Rejecting
 * unknown values makes the typo a loud boot-time failure instead.
 */
final class NotifierSelectionTest extends TestCase
{
    public function test_outbox_selects_the_durable_adapter(): void
    {
        config()->set('invoices.notifier', 'outbox');

        self::assertInstanceOf(OutboxInvoiceNotifier::class, $this->app->make(InvoiceNotifierInterface::class));
    }

    public function test_direct_selects_the_facade_adapter(): void
    {
        config()->set('invoices.notifier', 'direct');

        self::assertInstanceOf(NotificationFacadeInvoiceNotifier::class, $this->app->make(InvoiceNotifierInterface::class));
    }

    public function test_an_unknown_value_is_rejected_loudly(): void
    {
        config()->set('invoices.notifier', 'Outbox');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("INVOICE_NOTIFIER must be 'outbox' or 'direct', got 'Outbox'.");

        $this->app->make(InvoiceNotifierInterface::class);
    }
}
