<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Enums;

enum StatusEnum: string
{
    case Draft = 'draft';
    case Sending = 'sending';
    case SentToClient = 'sent-to-client';

    /**
     * The state machine as data. It lives here because "which statuses follow
     * which" is a fact about statuses, and the exhaustive `match` turns adding a
     * case into a compile-time obligation rather than a forgotten branch.
     *
     * Enforcement deliberately does NOT live here — Invoice::transitionTo() does
     * the throwing, so every caller shares one guard and no entry point can set a
     * status directly.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Sending],
            self::Sending => [self::SentToClient],
            self::SentToClient => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }
}
