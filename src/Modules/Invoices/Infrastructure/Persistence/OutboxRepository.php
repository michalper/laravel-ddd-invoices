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
     * Messages the queue should have drained by now. The outbox row is the source of
     * truth and the queue dispatch is only a latency optimisation, so anything still
     * unsettled past the threshold gets re-driven.
     *
     * `processing` is included on purpose: a worker killed mid-delivery leaves its
     * claim behind, and a row nobody will ever release is exactly the kind of stall
     * this command exists to clear. Re-dispatching it is safe because claim() is
     * conditional — if the original worker is somehow still alive and settles the
     * row, the re-dispatched job simply fails to claim it and returns.
     *
     * The filter is `updated_at` rather than `created_at` so it means "nothing has
     * happened to this row in N minutes", which is the actual question. Every write
     * here goes through Eloquent, so the timestamp is maintained for free.
     *
     * @return list<OutboxMessageModel>
     */
    public function stalled(CarbonInterface $olderThan): array
    {
        /** @var list<OutboxMessageModel> */
        return OutboxMessageModel::query()
            ->whereIn('status', OutboxStatus::unsettled())
            ->where('updated_at', '<=', $olderThan)
            ->orderBy('updated_at')
            ->get()
            ->all();
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
