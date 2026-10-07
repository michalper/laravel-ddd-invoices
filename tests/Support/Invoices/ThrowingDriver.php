<?php

declare(strict_types=1);

namespace Tests\Support\Invoices;

use Modules\Notifications\Infrastructure\Drivers\DriverInterface;
use RuntimeException;

final class ThrowingDriver implements DriverInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly string $reason = 'provider unavailable',
    ) {}

    public function send(string $toEmail, string $subject, string $message, string $reference): void
    {
        $this->calls++;

        throw new RuntimeException($this->reason);
    }
}
