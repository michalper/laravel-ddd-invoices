<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Invoices\Infrastructure\Persistence\Eloquent\OutboxMessageModel;
use Modules\Invoices\Infrastructure\Persistence\Eloquent\OutboxStatus;
use Modules\Invoices\Infrastructure\Persistence\OutboxRepository;
use Modules\Notifications\Api\Data\NotifyData;
use Modules\Notifications\Api\NotificationFacadeInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Drains one outbox row to the notification provider.
 *
 * Carries only the row id, never a serialised model or DTO: the row is the source
 * of truth, and a queue payload that outlives a deploy should not pin a class
 * shape.
 */
final class ProcessOutboxMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        private readonly string $messageId,
    ) {}

    /** @return list<int> seconds between attempts */
    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function handle(
        OutboxRepository $outbox,
        NotificationFacadeInterface $facade,
        LoggerInterface $logger,
    ): void {
        $message = $outbox->find($this->messageId);

        if (! $message instanceof OutboxMessageModel) {
            // The send rolled back after the job was queued, or the row was pruned.
            $logger->info('Outbox message no longer exists; nothing to deliver.', [
                'outbox_id' => $this->messageId,
            ]);

            return;
        }

        // Idempotent: a duplicate delivery of this job must not notify twice.
        if ($message->status !== OutboxStatus::Pending->value) {
            $logger->info('Outbox message already settled; skipping.', [
                'outbox_id' => $this->messageId,
                'status' => $message->status,
            ]);

            return;
        }

        try {
            $facade->notify(new NotifyData(
                resourceId: Uuid::fromString($message->invoice_id),
                toEmail: $message->payload['to_email'],
                subject: $message->payload['subject'],
                message: $message->payload['message'],
            ));
        } catch (Throwable $e) {
            $outbox->recordAttempt($this->messageId, $e->getMessage());

            // Rethrow so the queue applies the backoff above; failed() settles the
            // row once the attempts are exhausted.
            throw $e;
        }

        $outbox->markProcessed($this->messageId);

        $logger->info('Invoice notification delivered to the provider.', [
            'outbox_id' => $this->messageId,
            'invoice_id' => $message->invoice_id,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        /** @var OutboxRepository $outbox */
        $outbox = app(OutboxRepository::class);
        $outbox->markFailed($this->messageId, $exception->getMessage());

        /** @var LoggerInterface $logger */
        $logger = app(LoggerInterface::class);
        $logger->critical('Invoice notification permanently failed; invoice is stuck in sending.', [
            'outbox_id' => $this->messageId,
            'reason' => $exception->getMessage(),
        ]);
    }
}
