<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Repositories;

use Modules\Invoices\Domain\Entities\Invoice;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Exceptions\InvoiceNotFoundException;
use Ramsey\Uuid\UuidInterface;

interface InvoiceRepositoryInterface
{
    public function find(UuidInterface $id): ?Invoice;

    /** @throws InvoiceNotFoundException */
    public function get(UuidInterface $id): Invoice;

    public function save(Invoice $invoice): void;

    /**
     * Atomically move an invoice from one status to another, reporting whether it
     * actually happened.
     *
     * This is a persistence primitive, not a business rule — the rule lives in the
     * aggregate, and this is a second, database-level enforcement of the same
     * precondition. It exists instead of optimistic locking because the provided
     * schema has no `version` column and the schema is not ours to change; status
     * is the only mutable field on this aggregate, so a conditional UPDATE gives
     * the same guarantee.
     *
     * Deliberately not `lockForUpdate()`: SQLiteGrammar::compileLock() returns an
     * empty string, so pessimistic locking is a silent no-op on the default driver
     * and a lock-based design would test green while guaranteeing nothing. A
     * conditional UPDATE is correct on SQLite, MySQL and PostgreSQL alike.
     */
    public function compareAndSwapStatus(UuidInterface $id, StatusEnum $from, StatusEnum $to): bool;
}
