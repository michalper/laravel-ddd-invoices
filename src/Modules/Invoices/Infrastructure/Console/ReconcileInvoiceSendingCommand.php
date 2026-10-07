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

    /**
     * How many stalled messages one run re-drives. Unbounded, this command's worst
     * case grew with the outage that caused it: every run hydrated the whole backlog
     * and re-enqueued all of it, so queue depth became runs x backlog. A bounded run
     * that says what it left behind catches up over a few passes instead.
     */
    public const int SWEEP_LIMIT = 500;

    #[\Override]
    protected $signature = 'invoices:reconcile
        {--minutes= : How long a message may sit before it is re-driven; defaults to invoices.reconcile_after_minutes}
        {--limit= : Maximum stalled messages to re-drive in this run}
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
        $limit = is_numeric($cap = $this->option('limit')) ? (int) $cap : self::SWEEP_LIMIT;

        // Stale claims first: a worker killed mid-delivery leaves `processing`
        // behind, and claim() only admits `pending`, so without this release the
        // re-dispatched job would fail to claim, log at info, and change nothing —
        // the invoice would hang in `sending` with no alert, for ever. The ids are
        // collected BEFORE the release because releasing bumps updated_at, which
        // takes the rows back out of the staleness window.
        $abandoned = $outbox->staleClaims($threshold, $limit);
        $outbox->releaseClaims($abandoned);

        if ($abandoned !== []) {
            $logger->warning('Released outbox claims abandoned by a dead worker.', [
                'count' => count($abandoned),
            ]);
        }

        $stalled = array_values(array_unique([...$abandoned, ...$outbox->stalled($threshold, $limit)]));
        $dispatched = 0;

        try {
            foreach ($stalled as $messageId) {
                ProcessOutboxMessageJob::dispatch($messageId);
                $dispatched++;
            }
        } catch (\Throwable $e) {
            // Almost certainly the queue itself is down — which is also the most
            // likely reason there is a backlog at all. Swallowing the rest of the
            // run here would silence the failed/orphan reports at exactly the moment
            // they matter, so the error is recorded and reporting continues.
            $logger->error('Re-dispatching stalled notifications failed partway; reporting continues.', [
                'dispatched' => $dispatched,
                'planned' => count($stalled),
                'reason' => $e->getMessage(),
            ]);
        }

        $this->components->info(sprintf(
            '%d stalled notification(s) re-dispatched (unsettled for over %d minute(s)).',
            $dispatched,
            $minutes,
        ));

        if ($limit > 0 && (count($abandoned) === $limit || count($stalled) >= $limit)) {
            $this->components->warn('Hit the per-run limit; run again to continue the backlog.');
        }

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
