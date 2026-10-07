<?php

declare(strict_types=1);

namespace Tests\Support\Invoices;

/**
 * Shared ordering log, so a test can assert that the notification is recorded
 * before the status is swapped rather than merely that both happened.
 */
final class CallLog
{
    /** @var list<string> */
    private array $calls = [];

    public function record(string $call): void
    {
        $this->calls[] = $call;
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->calls;
    }
}
