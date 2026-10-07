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

    /** Retries exhausted. Still needs a human; the reconciler alerts on exactly this. */
    case Failed = 'failed';

    /**
     * A human looked at a failed message and decided not to pursue it.
     *
     * Terminal like Failed, but acknowledged — which is the whole point. Without this
     * state the only way to stop a resolved problem alerting on every reconcile run
     * forever is to delete the row, losing the record that it happened at all.
     */
    case Abandoned = 'abandoned';

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

    /**
     * Rows whose story is over and whose payload is therefore no longer needed.
     *
     * Failed is deliberately absent: it is terminal but unresolved, and redacting the
     * payload of a message somebody still has to deal with would destroy the evidence
     * they need.
     *
     * @return list<string>
     */
    public static function resolved(): array
    {
        return [self::Processed->value, self::Abandoned->value];
    }
}
