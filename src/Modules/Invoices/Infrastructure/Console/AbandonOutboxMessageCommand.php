<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Invoices\Infrastructure\Persistence\OutboxRepository;
use Psr\Log\LoggerInterface;

/**
 * Lets a human close a failed message instead of muting the channel it shouts into.
 *
 * `invoices:reconcile` logs every failed message at critical, every run, forever.
 * Bounding how many it names per run keeps the volume survivable but not the
 * permanence: a problem somebody decided weeks ago not to pursue still alerts today,
 * and an alert that never stops is an alert nobody reads.
 *
 * The alternative — deleting the row — would stop the noise by destroying the record
 * that the message ever existed, which is exactly what you want to keep.
 */
final class AbandonOutboxMessageCommand extends Command
{
    #[\Override]
    protected $signature = 'invoices:outbox:abandon
        {id : The permanently failed outbox message to close}
        {--reason= : Why it is not being pursued; recorded on the row}';

    #[\Override]
    protected $description = 'Acknowledge a permanently failed notification so it stops alerting';

    public function handle(OutboxRepository $outbox, LoggerInterface $logger): int
    {
        $id = (string) $this->argument('id');
        $reason = $this->option('reason');

        if (! is_string($reason) || trim($reason) === '') {
            // A reason is the entire difference between closing a problem and hiding
            // one. Whoever reads this row in six months needs to know which it was.
            $this->components->error('A --reason is required: it is the record of why this was not pursued.');

            return self::INVALID;
        }

        if (! $outbox->abandon($id, $reason)) {
            $this->components->error("No permanently failed message with id {$id}.");

            return self::FAILURE;
        }

        $logger->warning('Invoice notification abandoned by an operator.', [
            'outbox_id' => $id,
            'reason' => $reason,
        ]);

        $this->components->info("Outbox message {$id} abandoned.");
        $this->components->warn('The invoice stays in `sending`: nothing will now notify this customer.');

        return self::SUCCESS;
    }
}
