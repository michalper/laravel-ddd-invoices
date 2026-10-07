<?php

declare(strict_types=1);

namespace Tests\Support\Invoices;

use Modules\Notifications\Infrastructure\Drivers\DriverInterface;

/**
 * Stands in for the notification provider and remembers what it was asked to send.
 *
 * Deliberately a driver rather than a facade double: swapping the driver leaves the
 * real NotificationFacade in the path, so a test actually verifies that NotifyData
 * is built with the invoice id as resourceId — the exact value the delivery webhook
 * echoes back as `reference`, and therefore the link that makes the whole
 * send/deliver loop work.
 */
final class RecordingDriver implements DriverInterface
{
    /** @var list<array{toEmail: string, subject: string, message: string, reference: string}> */
    public array $sent = [];

    public function send(string $toEmail, string $subject, string $message, string $reference): void
    {
        $this->sent[] = [
            'toEmail' => $toEmail,
            'subject' => $subject,
            'message' => $message,
            'reference' => $reference,
        ];
    }

    public function count(): int
    {
        return count($this->sent);
    }

    /** @return array{toEmail: string, subject: string, message: string, reference: string} */
    public function first(): array
    {
        return $this->sent[0];
    }
}
