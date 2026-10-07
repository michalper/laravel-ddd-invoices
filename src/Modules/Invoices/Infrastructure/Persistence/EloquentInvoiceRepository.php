<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Persistence;

use Illuminate\Database\ConnectionInterface;
use Modules\Invoices\Domain\Entities\Invoice;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Exceptions\InvoiceNotFoundException;
use Modules\Invoices\Domain\Repositories\InvoiceRepositoryInterface;
use Modules\Invoices\Infrastructure\Persistence\Eloquent\InvoiceMapper;
use Modules\Invoices\Infrastructure\Persistence\Eloquent\InvoiceModel;
use Modules\Invoices\Infrastructure\Persistence\Eloquent\ProductLineModel;
use Ramsey\Uuid\UuidInterface;

final readonly class EloquentInvoiceRepository implements InvoiceRepositoryInterface
{
    public function __construct(
        private InvoiceMapper $mapper,
        private ConnectionInterface $connection,
    ) {}

    public function find(UuidInterface $id): ?Invoice
    {
        $model = InvoiceModel::query()
            ->with('productLines')
            ->find($id->toString());

        return $model instanceof InvoiceModel
            ? $this->mapper->toDomain($model)
            : null;
    }

    public function get(UuidInterface $id): Invoice
    {
        return $this->find($id) ?? throw InvoiceNotFoundException::withId($id);
    }

    public function save(Invoice $invoice): void
    {
        // Parent and children land together; nesting inside a caller's transaction
        // is fine, Laravel uses a savepoint.
        $this->connection->transaction(function () use ($invoice): void {
            InvoiceModel::query()->create($this->mapper->toInvoiceRow($invoice));

            $rows = $this->mapper->toProductLineRows($invoice);

            if ($rows === []) {
                return;
            }

            $invoiceId = $invoice->id()->toString();

            // One timestamp for the whole batch, taken once. This is what makes the
            // comment on InvoiceModel::productLines() — that all lines of one
            // invoice share a timestamp, so created_at is no tiebreak — literally
            // true rather than true by rounding.
            $now = now();

            ProductLineModel::query()->insert(array_map(
                static fn (array $row): array => [
                    ...$row,
                    'invoice_id' => $invoiceId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $rows,
            ));
        });
    }

    public function compareAndSwapStatus(UuidInterface $id, StatusEnum $from, StatusEnum $to): bool
    {
        // One statement, no row lock, correct on every supported driver. See the
        // interface for why this is not lockForUpdate().
        return InvoiceModel::query()
            ->whereKey($id->toString())
            ->where('status', $from->value)
            ->update(['status' => $to->value]) === 1;
    }
}
