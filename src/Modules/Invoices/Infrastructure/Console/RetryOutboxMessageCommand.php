<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Invoices\Infrastructure\Notifications\ProcessOutboxMessageJob;
use Modules\Invoices\Infrastructure\Persistence\OutboxRepository;
use Psr\Log\LoggerInterface;

/**
 * The remedy `invoices:reconcile` could previously only ask for.
 *
 * A message that exhausts its retries is terminal, and the invoice it belongs to sits
 * in `sending` indefinitely. The reconciler reports that as critical on every run, but
 * until now there was nothing an operator could do about it except write SQL against
 * production — which is the worst possible answer to "the provider was down for an
 * hour and now it is back".
 */
final class RetryOutboxMessageCommand extends Command
{
    /** Matches the reconciler's reporting bound: one run should not try to re-drive a year of backlog. */
    private const int BATCH = 50;

    #[\Override]
    protected $signature = 'invoices:outbox:retry
        {id? : The outbox message to re-drive}
        {--all : Re-drive permanently failed messages, for a provider-wide outage}
        {--limit= : Maximum messages to re-drive in this run}';

    #[\Override]
    protected $description = 'Put a permanently failed notification back in the queue';

    public function handle(OutboxRepository $outbox, LoggerInterface $logger): int
    {
        $id = $this->argument('id');
        $all = $this->option('all') === true;

        if (is_string($id) && $id !== '') {
            return $this->retryOne($outbox, $logger, $id);
        }

        if (! $all) {
            $this->components->error('Give a message id, or --all to re-drive every failed message.');

            return self::INVALID;
        }

        $limit = is_numeric($cap = $this->option('limit')) ? (int) $cap : self::BATCH;

        return $this->retryAll($outbox, $logger, $limit);
    }

    private function retryOne(OutboxRepository $outbox, LoggerInterface $logger, string $id): int
    {
        if (! $outbox->reopen($id)) {
            // Deliberately not a silent success: the id was wrong, or the message is
            // in flight, or somebody already abandoned it. All three are things the
            // operator needs to know before they walk away.
            $this->components->error("No permanently failed message with id {$id}.");

            return self::FAILURE;
        }

        $this->dispatch($logger, $id);
        $this->components->info("Re-dispatched outbox message {$id}.");

        return self::SUCCESS;
    }

    /**
     * Bounded to one batch, like invoices:outbox:prune, rather than looping until the
     * failed set empties.
     *
     * A loop would have to guard against reading rows it cannot reopen — which only
     * happens if another process is changing them underneath — and that guard is a
     * branch no test can reach honestly. Doing one bounded pass and saying what is
     * left is simpler, has no unreachable code, and gives the operator the same
     * outcome after a second run.
     */
    private function retryAll(OutboxRepository $outbox, LoggerInterface $logger, int $limit): int
    {
        $reopened = 0;

        foreach ($outbox->failed($limit) as $message) {
            if ($outbox->reopen($message->id)) {
                $this->dispatch($logger, $message->id);
                $reopened++;
            }
        }

        $this->components->info(sprintf('%d permanently failed notification(s) re-dispatched.', $reopened));

        if ($reopened === $limit && $limit > 0) {
            $this->components->warn('Hit the per-run limit; run again to continue the backlog.');
        }

        return self::SUCCESS;
    }

    private function dispatch(LoggerInterface $logger, string $id): void
    {
        ProcessOutboxMessageJob::dispatch($id);

        $logger->warning('Permanently failed invoice notification was re-driven by an operator.', [
            'outbox_id' => $id,
        ]);
    }
}
