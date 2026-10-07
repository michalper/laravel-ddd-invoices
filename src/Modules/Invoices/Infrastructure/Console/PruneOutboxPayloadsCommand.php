<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Modules\Invoices\Infrastructure\Persistence\OutboxRepository;
use Psr\Log\LoggerInterface;

/**
 * Empties the payload of resolved outbox rows once they are old enough to be history.
 *
 * The interesting cost of never pruning is not disk. Each payload holds the customer's
 * name, e-mail and the message body, so an outbox nobody tends becomes a second copy
 * of personal data that outlives every retention policy written for invoices — and
 * nothing in the application knows it is there.
 *
 * Redacting rather than deleting, because the two things have different lifetimes:
 * *that* this invoice was notified, when, and after how many attempts is worth keeping
 * long after *what was said* is not.
 *
 * Rows in `failed` are never touched. They are terminal but unresolved, and destroying
 * the evidence somebody still needs to diagnose them would be the opposite of helpful.
 */
final class PruneOutboxPayloadsCommand extends Command
{
    /**
     * One run redacts at most this many rows. A scheduled command that tries to
     * swallow a year of backlog in a single statement is a command that times out and
     * never makes progress; a bounded one catches up over a few runs.
     */
    private const int BATCH = 500;

    #[\Override]
    protected $signature = 'invoices:outbox:prune
        {--days= : How old a resolved message must be; defaults to invoices.retain_payload_days}
        {--limit= : Maximum rows to redact in this run}';

    #[\Override]
    protected $description = 'Redact the payloads of resolved outbox messages past the retention window';

    public function handle(OutboxRepository $outbox, LoggerInterface $logger, Config $config): int
    {
        $days = is_numeric($given = $this->option('days'))
            ? (int) $given
            : $config->integer('invoices.retain_payload_days');

        $limit = is_numeric($cap = $this->option('limit')) ? (int) $cap : self::BATCH;

        $ids = $outbox->redactable(now()->subDays($days), $limit);
        $redacted = $outbox->redact($ids);

        $this->components->info(sprintf(
            '%d resolved notification payload(s) redacted (resolved for over %d day(s)).',
            $redacted,
            $days,
        ));

        if ($redacted > 0) {
            $logger->info('Outbox payloads redacted.', [
                'count' => $redacted,
                'older_than_days' => $days,
            ]);
        }

        // Saying so matters: a scheduled run that silently stops at its limit looks
        // identical to one with nothing left to do, and the backlog never gets noticed.
        if ($redacted === $limit) {
            $this->components->warn('Hit the per-run limit; run again to continue the backlog.');
        }

        return self::SUCCESS;
    }
}
