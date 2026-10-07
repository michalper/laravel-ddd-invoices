<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Persistence;

use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Infrastructure\Persistence\Eloquent\InvoiceModel;
use Modules\Invoices\Infrastructure\Persistence\Eloquent\OutboxMessageModel;
use Modules\Invoices\Infrastructure\Persistence\Eloquent\OutboxStatus;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final readonly class OutboxRepository
{
    /** Provider errors can be arbitrarily long; the column is not. */
    public const int MAX_ERROR_LENGTH = 2000;

    /** @param array{to_email: string, subject: string, message: string} $payload */
    public function enqueue(UuidInterface $invoiceId, array $payload): string
    {
        $id = Uuid::uuid4()->toString();

        OutboxMessageModel::query()->create([
            'id' => $id,
            'invoice_id' => $invoiceId->toString(),
            'status' => OutboxStatus::Pending->value,
            'payload' => $payload,
            'attempts' => 0,
        ]);

        return $id;
    }

    public function find(string $id): ?OutboxMessageModel
    {
        $model = OutboxMessageModel::query()->find($id);

        return $model instanceof OutboxMessageModel ? $model : null;
    }

    /**
     * Takes exclusive ownership of a pending row, or reports that someone else
     * already has it.
     *
     * This is the same compare-and-swap primitive the invoice aggregate uses, for
     * the same reason: reading the status and then acting on it is a check-then-act
     * that two workers can both pass. A row can be delivered twice whenever a
     * provider call outlives the queue's `retry_after`, because the queue releases
     * the job while the first worker is still inside notify(). Making the claim a
     * conditional UPDATE checked by affected-row count means exactly one worker
     * reaches the provider, and it is also what makes an overlapping
     * `invoices:reconcile` run harmless rather than a source of duplicates.
     */
    public function claim(string $id): bool
    {
        return OutboxMessageModel::query()
            ->whereKey($id)
            ->where('status', OutboxStatus::Pending->value)
            ->update(['status' => OutboxStatus::Processing->value]) === 1;
    }

    /**
     * Hands a claimed row back so a retry — or the reconciler — can pick it up.
     * Without this a failed attempt would strand the row in `processing` forever.
     */
    public function release(string $id): void
    {
        OutboxMessageModel::query()
            ->whereKey($id)
            ->where('status', OutboxStatus::Processing->value)
            ->update(['status' => OutboxStatus::Pending->value]);
    }

    /**
     * Guarded by the unsettled states, so a terminal write cannot overwrite another
     * terminal write. Without the guard the row's final state would be decided by
     * write order between concurrent deliveries: a slow attempt that exhausts its
     * retries calls markFailed() after a second worker already delivered the message
     * and called markProcessed(), flipping a genuinely delivered row to `failed` —
     * which `invoices:reconcile` would then report as critical on every run.
     */
    public function markProcessed(string $id): void
    {
        OutboxMessageModel::query()
            ->whereKey($id)
            ->whereIn('status', OutboxStatus::unsettled())
            ->update([
                'status' => OutboxStatus::Processed->value,
                'processed_at' => now(),
                'last_error' => null,
            ]);
    }

    public function recordAttempt(string $id, string $error): void
    {
        // Atomic increment: a read-then-write would lose a count under concurrent
        // retries of the same message.
        OutboxMessageModel::query()->whereKey($id)->update([
            'attempts' => DB::raw('attempts + 1'),
            'last_error' => mb_substr($error, 0, self::MAX_ERROR_LENGTH),
        ]);
    }

    /** @see markProcessed() for why the status guard is here. */
    public function markFailed(string $id, string $error): void
    {
        OutboxMessageModel::query()
            ->whereKey($id)
            ->whereIn('status', OutboxStatus::unsettled())
            ->update([
                'status' => OutboxStatus::Failed->value,
                'last_error' => mb_substr($error, 0, self::MAX_ERROR_LENGTH),
            ]);
    }

    /**
     * Pending messages the queue should have drained by now. The outbox row is the
     * source of truth and the queue dispatch is only a latency optimisation, so
     * anything still pending past the threshold gets re-driven.
     *
     * The filter is `updated_at` rather than `created_at` so it means "nothing has
     * happened to this row in N minutes", which is the actual question. Every write
     * here goes through Eloquent, so the timestamp is maintained for free.
     *
     * Only ids, and only a bounded batch. The caller needs nothing but the id to
     * dispatch, and the unbounded version failed in exactly the scenario it existed
     * for: with the worker down and the API still accepting sends, every reconcile
     * run hydrated the whole backlog into memory and re-enqueued all of it — queue
     * depth became runs x backlog, and the biggest outage produced the biggest run.
     *
     * Deliberately NOT `processing`: a stale claim is recovered by releasing it
     * (see staleClaims()/releaseClaims()), because claim() only admits `pending` —
     * re-dispatching a `processing` row produces a job that fails to claim, logs at
     * info, and changes nothing, which is how stuck claims went unrecoverable once.
     *
     * @return list<string>
     */
    public function stalled(CarbonInterface $olderThan, int $limit): array
    {
        /** @var list<string> */
        return OutboxMessageModel::query()
            ->where('status', OutboxStatus::Pending->value)
            ->where('updated_at', '<=', $olderThan)
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    /**
     * Claims whose worker is gone: `processing` rows nothing has touched past the
     * threshold. A worker killed between claiming and settling leaves exactly this
     * behind, and nothing else will ever move it — release() runs only in the
     * failure path of a living job.
     *
     * @return list<string>
     */
    public function staleClaims(CarbonInterface $olderThan, int $limit): array
    {
        /** @var list<string> */
        return OutboxMessageModel::query()
            ->where('status', OutboxStatus::Processing->value)
            ->where('updated_at', '<=', $olderThan)
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    /**
     * Hands abandoned claims back in bulk, guarded on `processing` so a row the
     * original worker settled in the meantime is left alone.
     *
     * The caller must collect the ids BEFORE calling this and dispatch those ids
     * afterwards: this update bumps `updated_at`, so the released rows no longer
     * match the staleness threshold — re-selecting after the release would find
     * nothing and quietly postpone the recovery by a whole threshold period.
     *
     * @param  list<string>  $ids
     */
    public function releaseClaims(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return OutboxMessageModel::query()
            ->whereIn('id', $ids)
            ->where('status', OutboxStatus::Processing->value)
            ->update(['status' => OutboxStatus::Pending->value]);
    }

    /**
     * Bounded on purpose. Nothing acknowledges or prunes a failed row, so an
     * unbounded query would load the entire historical failure set on every run and
     * re-log all of it — the alert degrades into noise precisely as the problem
     * grows. The caller pairs this with countFailed() to say how many it did not
     * show.
     *
     * @return list<OutboxMessageModel>
     */
    public function failed(int $limit): array
    {
        /** @var list<OutboxMessageModel> */
        return OutboxMessageModel::query()
            ->where('status', OutboxStatus::Failed->value)
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function countFailed(): int
    {
        return OutboxMessageModel::query()
            ->where('status', OutboxStatus::Failed->value)
            ->count();
    }

    /**
     * Puts a permanently failed message back in the queue's path.
     *
     * This is the remedy the reconciler could previously only wish for: it reported a
     * failed message as critical on every run, but `failed` was terminal, so an
     * operator who had fixed the provider outage had no supported way to say "try
     * again" — only raw SQL against production.
     *
     * Guarded on `failed`, so it cannot resurrect a message that is already in flight
     * or one somebody else abandoned on purpose. `attempts` is deliberately left
     * alone: it is the history of what this message cost, not a quota to reset.
     */
    public function reopen(string $id): bool
    {
        return OutboxMessageModel::query()
            ->whereKey($id)
            ->where('status', OutboxStatus::Failed->value)
            ->update([
                'status' => OutboxStatus::Pending->value,
                'resolution' => null,
            ]) === 1;
    }

    /**
     * Records that a human decided not to pursue a failed message.
     *
     * Also guarded on `failed`: abandoning anything else would be hiding a live
     * problem rather than closing a resolved one.
     */
    public function abandon(string $id, string $reason): bool
    {
        return OutboxMessageModel::query()
            ->whereKey($id)
            ->where('status', OutboxStatus::Failed->value)
            ->update([
                'status' => OutboxStatus::Abandoned->value,
                'resolution' => mb_substr($reason, 0, self::MAX_ERROR_LENGTH),
            ]) === 1;
    }

    /**
     * Resolved rows still carrying a payload, oldest first.
     *
     * The payload holds the customer's name and e-mail, so an outbox nobody prunes is
     * a second copy of personal data living outside whatever retention policy applies
     * to invoices themselves. Bounded, because this runs on a schedule and a run that
     * tries to redact a year of backlog in one statement is a run that times out.
     *
     * @return list<string>
     */
    public function redactable(CarbonInterface $olderThan, int $limit): array
    {
        /** @var list<string> */
        return OutboxMessageModel::query()
            ->whereIn('status', OutboxStatus::resolved())
            ->whereNull('redacted_at')
            ->where('updated_at', '<=', $olderThan)
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    /**
     * Empties the payload but keeps the row.
     *
     * Deleting would take the audit trail with it — that this invoice was notified,
     * when, and after how many attempts is worth keeping long after the message body
     * is not. `redacted_at` is what makes the empty payload legible as a decision
     * rather than a bug, and what stops the next run redacting the same rows again.
     *
     * @param  list<string>  $ids
     */
    public function redact(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return OutboxMessageModel::query()
            ->whereIn('id', $ids)
            ->update([
                'payload' => [],
                'redacted_at' => now(),
            ]);
    }

    /**
     * Invoices that claimed a send but have no outbox row at all — the one gap the
     * outbox cannot close by itself, because it means the row never got written
     * despite the status change committing. Should be impossible given both happen
     * in one transaction; worth alerting on precisely because it would mean that
     * assumption broke.
     *
     * @return list<string>
     */
    public function invoicesSendingWithoutOutbox(CarbonInterface $olderThan): array
    {
        /** @var list<string> */
        return InvoiceModel::query()
            ->where('status', StatusEnum::Sending->value)
            ->where('updated_at', '<=', $olderThan)
            ->whereNotExists(static function (QueryBuilder $query): void {
                $query->select(DB::raw('1'))
                    ->from('invoice_notification_outbox')
                    ->whereColumn('invoice_notification_outbox.invoice_id', 'invoices.id');
            })
            ->pluck('id')
            ->all();
    }
}
