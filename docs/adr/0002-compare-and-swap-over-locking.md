# ADR 0002: Enforce the status precondition with a compare-and-swap, not pessimistic locking

## Status

Accepted.

## Context

Two concurrent `POST /api/invoices/{id}/send` requests both read a draft invoice and both clear
the aggregate's in-memory guard, because each is working on its own copy. Something at the
database level has to decide which one wins, or both proceed and the customer is notified twice.

The two usual answers do not fit:

- **Optimistic locking** needs a `version` column. The provided schema has none, and the schema
  is not ours to change.
- **Pessimistic locking** (`lockForUpdate()`) is worse than unavailable here, it is silently
  ineffective: `SQLiteGrammar::compileLock()` returns an empty string, so on the default driver
  the lock is a no-op. A lock-based design would test green on SQLite while guaranteeing nothing.

## Decision

`InvoiceRepositoryInterface::compareAndSwapStatus($id, $from, $to)` performs a conditional
`UPDATE ... WHERE id = ? AND status = ?` and reports whether exactly one row matched, by checking
the affected-row count. `SendInvoiceService` treats a miss as
`InvalidStatusTransitionException::concurrentModification()`, which maps to 409.

This is deliberately a persistence primitive rather than a business rule. The rule lives in the
aggregate; this is a second, database-level enforcement of the same precondition. `status` is the
only mutable field on this aggregate, so a conditional UPDATE gives the same guarantee a version
column would.

## Consequences

- Correct on SQLite, MySQL and PostgreSQL alike, with no driver-specific behaviour and no schema
  change.
- The precondition is stated twice, in the aggregate and in the UPDATE. That is intentional
  duplication, not redundancy: the first gives a clear error for the ordinary case, the second is
  what actually holds under concurrency.
- An earlier version of this record warned that MySQL's matched-vs-changed row counting
  (`PDO::MYSQL_ATTR_FOUND_ROWS`) could weaken the guarantee. The audit showed that warning was
  wrong: this compare-and-swap always has `from != to`, so every row the WHERE matches is also a
  row the SET changes, and the two counts cannot differ here. The flag is irrelevant to this
  design, and the characterisation test written to guard it was deleted — it could not fail.
- CI only ran SQLite for most of this module's life, so the cross-driver claim above was reasoned
  rather than tested. See the database matrix in `.github/workflows/ci.yml`.
