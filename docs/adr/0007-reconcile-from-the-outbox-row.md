# ADR 0007: Treat the outbox row as the source of truth and reconcile from it

## Status

Accepted.

## Context

The transactional outbox removes the dual write, but it does not by itself make "nothing is lost"
true. A process that dies between the commit and the queue dispatch leaves a pending row that
nobody is driving. A worker killed mid-delivery leaves a row it had claimed. Neither is visible
to the queue, because the queue never received the message or already dropped it.

## Decision

The row is the source of truth; the queue dispatch is only a latency optimisation.
`invoices:reconcile` re-drives anything unsettled and reports anything that failed permanently.

Specifics that matter:

- `OutboxRepository::stalled()` selects both `pending` *and* `processing` rows, because an
  abandoned claim is exactly the stall this command exists to clear.
- It filters on `updated_at`, not `created_at`, so the question is "has anything happened to this
  row lately" rather than "is this row old".
- Re-dispatching is safe to overlap, because claiming is a conditional UPDATE: a duplicate job
  simply fails to claim and returns. Without that, every reconcile run would multiply
  deliveries.
- The threshold comes from `invoices.reconcile_after_minutes`, overridable per run with
  `--minutes`.
- `--strict` turns the findings into a non-zero exit so CI can gate on them. A scheduled run
  stays quiet, because "found work and did it" is not a failure.
- Per-row failure logging is bounded, with the total still reported, because nothing prunes a
  failed row and an unbounded report would drown the signal exactly as the problem grows.
- The orphan probe (`invoicesSendingWithoutOutbox()`) runs only under the outbox adapter. Under
  `direct` there is legitimately no row per send, so reporting those would make the one signal
  worth alerting on fire on every run.

## Consequences

- Nothing is lost without a human having to notice, and the recovery path is a single idempotent
  command suitable for cron.
- There was no acknowledgement or pruning step, so `invoice_notification_outbox` grew
  monotonically and the failed set was never cleared. The reporting bound mitigated the noise,
  not the growth. Closed by [ADR 0008](0008-an-outbox-row-needs-somewhere-to-end-up.md).
- `--strict` is sharp enough to be racy if used naively. The end-to-end job polls it rather than
  calling it once, because the suite finishes within a second or two of dispatching its last job
  and a single immediate check races the worker's own startup.
- Reconciliation can mask a genuinely broken worker in the aggregate statistics: rows do settle,
  just via the reconciler rather than the dispatch. Distinguishing the two needs the
  `attempts` column or the delivery log, not this command's exit code.
