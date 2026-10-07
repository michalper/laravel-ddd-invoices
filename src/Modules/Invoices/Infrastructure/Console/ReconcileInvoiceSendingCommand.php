<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Invoices\Infrastructure\Notifications\ProcessOutboxMessageJob;
use Modules\Invoices\Infrastructure\Persistence\OutboxRepository;
use Psr\Log\LoggerInterface;

/**
 * Closes the last gap in the send workflow.
 *
 * The outbox makes the intent durable, but a process that dies between the commit
 * and the queue dispatch leaves a pending row nobody is driving. This command is
 * what makes "nothing is lost" true rather than aspirational, and it is why the row
 * is the source of truth instead of the queue message.
 */
final class ReconcileInvoiceSendingCommand extends Command
{
    protected $signature = 'invoices:reconcile {--minutes=15 : How long a pending message may sit before it is re-driven}';

    protected $description = 'Re-drive stalled invoice notifications and report ones that failed permanently';

    public function handle(OutboxRepository $outbox, LoggerInterface $logger): int
    {
        $minutes = (int) $this->option('minutes');
        $threshold = now()->subMinutes($minutes);

        $stale = $outbox->stalePending($threshold);

        foreach ($stale as $message) {
            ProcessOutboxMessageJob::dispatch($message->id);
        }

        $this->components->info(sprintf(
            '%d stalled notification(s) re-dispatched (pending for over %d minute(s)).',
            count($stale),
            $minutes,
        ));

        $failed = $outbox->failed();

        foreach ($failed as $message) {
            $logger->critical('Invoice notification needs attention.', [
                'outbox_id' => $message->id,
                'invoice_id' => $message->invoice_id,
                'attempts' => $message->attempts,
                'last_error' => $message->last_error,
            ]);
        }

        $orphans = $outbox->invoicesSendingWithoutOutbox($threshold);

        foreach ($orphans as $invoiceId) {
            $logger->critical('Invoice is sending but has no notification record at all.', [
                'invoice_id' => $invoiceId,
            ]);
        }

        if ($failed !== [] || $orphans !== []) {
            $this->components->warn(sprintf(
                '%d permanently failed and %d orphaned invoice(s) reported.',
                count($failed),
                count($orphans),
            ));
        }

        return self::SUCCESS;
    }
}
