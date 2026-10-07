<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Console;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
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
    /**
     * How many permanently failed messages to name in one run. The count is always
     * reported in full; this only bounds the per-row logging.
     */
    public const int FAILED_REPORT_LIMIT = 50;

    #[\Override]
    protected $signature = 'invoices:reconcile
        {--minutes= : How long a message may sit before it is re-driven; defaults to invoices.reconcile_after_minutes}
        {--strict : Exit non-zero if anything was stalled, failed or orphaned — for use as a CI gate}';

    #[\Override]
    protected $description = 'Re-drive stalled invoice notifications and report ones that failed permanently';

    public function handle(OutboxRepository $outbox, LoggerInterface $logger, Config $config): int
    {
        // The option wins when given, otherwise the configured value — so
        // INVOICE_RECONCILE_AFTER_MINUTES actually does something. A hardcoded
        // signature default would silently shadow it.
        $minutes = is_numeric($given = $this->option('minutes'))
            ? (int) $given
            : $config->integer('invoices.reconcile_after_minutes');

        $threshold = now()->subMinutes($minutes);

        $stalled = $outbox->stalled($threshold);

        foreach ($stalled as $message) {
            ProcessOutboxMessageJob::dispatch($message->id);
        }

        $this->components->info(sprintf(
            '%d stalled notification(s) re-dispatched (unsettled for over %d minute(s)).',
            count($stalled),
            $minutes,
        ));

        $failedCount = $outbox->countFailed();

        foreach ($outbox->failed(self::FAILED_REPORT_LIMIT) as $message) {
            $logger->critical('Invoice notification needs attention.', [
                'outbox_id' => $message->id,
                'invoice_id' => $message->invoice_id,
                'attempts' => $message->attempts,
                'last_error' => $message->last_error,
            ]);
        }

        if ($failedCount > self::FAILED_REPORT_LIMIT) {
            $logger->critical('More permanently failed notifications than this run reported.', [
                'reported' => self::FAILED_REPORT_LIMIT,
                'total' => $failedCount,
            ]);
        }

        $orphans = $this->orphans($outbox, $config, $threshold);

        foreach ($orphans as $invoiceId) {
            $logger->critical('Invoice is sending but has no notification record at all.', [
                'invoice_id' => $invoiceId,
            ]);
        }

        if ($failedCount > 0 || $orphans !== []) {
            $this->components->warn(sprintf(
                '%d permanently failed and %d orphaned invoice(s) reported.',
                $failedCount,
                count($orphans),
            ));
        }

        // Scheduled runs stay quiet so a cron wrapper does not treat "found work and
        // did it" as a crash; --strict turns the same information into a gate, which
        // is what the end-to-end job needs to assert that the worker settled
        // everything.
        if ($this->option('strict') === true && ($stalled !== [] || $failedCount > 0 || $orphans !== [])) {
            $this->components->error('Reconciliation found unsettled work; see the entries above.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Only the outbox adapter promises a row per send, so this probe is meaningful
     * only when it is the one in use. Under INVOICE_NOTIFIER=direct every invoice
     * legitimately waiting in `sending` for its delivery webhook has no outbox row,
     * and reporting all of them as critical would turn the one signal worth alerting
     * on into a guaranteed false alarm.
     *
     * @return list<string>
     */
    private function orphans(OutboxRepository $outbox, Config $config, CarbonInterface $threshold): array
    {
        if ($config->get('invoices.notifier') !== 'outbox') {
            return [];
        }

        return $outbox->invoicesSendingWithoutOutbox($threshold);
    }
}
