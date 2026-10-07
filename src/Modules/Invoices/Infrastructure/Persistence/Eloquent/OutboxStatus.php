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

    /**
     * A worker has claimed this row and is talking to the provider. The state exists
     * so claiming can be a conditional UPDATE: without it, "read pending, then call
     * the provider, then mark processed" is a check-then-act that two workers can
     * both pass, which is exactly how a duplicate delivery happens.
     */
    case Processing = 'processing';

    case Processed = 'processed';
    case Failed = 'failed';

    /**
     * The two states a row can still move out of, and therefore the guard every
     * terminal write uses.
     *
     * @return list<string>
     */
    public static function unsettled(): array
    {
        return [self::Pending->value, self::Processing->value];
    }
}
