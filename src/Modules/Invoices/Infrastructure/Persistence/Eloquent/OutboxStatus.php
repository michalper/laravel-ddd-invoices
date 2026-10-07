<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Persistence\Eloquent;

/**
 * A persistence concern, not a domain concept — the business has no opinion about
 * how a notification got delivered, only about the invoice's status.
 */
enum OutboxStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';
}
