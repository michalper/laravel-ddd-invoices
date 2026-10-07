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

    public function markProcessed(string $id): void
    {
        OutboxMessageModel::query()->whereKey($id)->update([
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

    public function markFailed(string $id, string $error): void
    {
        OutboxMessageModel::query()->whereKey($id)->update([
            'status' => OutboxStatus::Failed->value,
            'last_error' => mb_substr($error, 0, self::MAX_ERROR_LENGTH),
        ]);
    }

    /**
     * Messages the queue should have drained by now. The outbox row is the source of
     * truth and the queue dispatch is only a latency optimisation, so anything still
     * pending past the threshold gets re-driven.
     *
     * @return list<OutboxMessageModel>
     */
    public function stalePending(CarbonInterface $olderThan): array
    {
        /** @var list<OutboxMessageModel> */
        return OutboxMessageModel::query()
            ->where('status', OutboxStatus::Pending->value)
            ->where('created_at', '<=', $olderThan)
            ->orderBy('created_at')
            ->get()
            ->all();
    }

    /** @return list<OutboxMessageModel> */
    public function failed(): array
    {
        /** @var list<OutboxMessageModel> */
        return OutboxMessageModel::query()
            ->where('status', OutboxStatus::Failed->value)
            ->orderBy('created_at')
            ->get()
            ->all();
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
