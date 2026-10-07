# ADR 0003: Make the send intent durable with a transactional outbox

## Status

Accepted.

## Context

Sending an invoice has to do two things that cannot share a transaction: change the invoice
status, and cause an external effect (the customer's e-mail). That is a dual write, and the task
adds three requirements that cannot all hold with a synchronous send:

1. the specification's ordering (the status changes after the notification is sent),
2. no I/O inside a database transaction,
3. exactly one e-mail under concurrency.

They conflict because preventing a double send means claiming the invoice before calling the
provider, and claiming it *is* a status write.

Asynchrony alone does not resolve this, it relocates it. A job guarded by `ShouldBeUnique` moves
the "exactly once" guarantee into Redis — a different store from the invoice state, so the two
can disagree. Automatic retries make the ordering hole worse rather than better: a provider
timeout that actually delivered leaves the status unwritten, so every retry sends again.

## Decision

The intent is recorded in the same transaction as the state change. `OutboxInvoiceNotifier` (the
default, selected by `INVOICE_NOTIFIER=outbox`) writes a row to `invoice_notification_outbox`
inside the caller's transaction and dispatches `ProcessOutboxMessageJob` with `afterCommit()`.
Reaching the provider becomes a separate, retried step, with the invoice id doubling as the
idempotency key the provider already receives as `reference`.

This is what the port's wording buys: `InvoiceNotifierInterface::notifyInvoiceSent()` is
specified as "durably record that this invoice must be notified", not "make an HTTP call", so the
application service does not change when the adapter does.

`NotificationFacadeInvoiceNotifier` (`INVOICE_NOTIFIER=direct`) is kept deliberately as an
exhibit of the trade-off: it reaches the provider inside the caller's transaction, holding a row
lock across someone else's latency, and exists so the flow can be demonstrated without a worker.

## Consequences

- There is one commit instead of two systems that can disagree. No window exists in which a send
  is lost.
- Delivery is at-least-once, not exactly-once, at the provider boundary. The invoice id is the
  idempotency key, and the provider is expected to honour it.
- **The default configuration requires a running queue worker.** Without one, invoices sit in
  `sending` forever and nothing says why. This is the single most likely way to misread the
  implementation as broken, which is why it is called out in `README.md` and why
  `docker-compose.yml` starts a worker.
- The outbox row is the source of truth and the queue dispatch is only a latency optimisation,
  which is what makes `invoices:reconcile` possible. See
  [ADR 0007](0007-reconcile-from-the-outbox-row.md).
- The outbox table is a new schema object, unlike everything else here, which only works because
  the provided migrations were additive rather than fixed.
