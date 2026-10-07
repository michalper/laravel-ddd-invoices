# ADR 0004: Claim the invoice before notifying the provider

## Status

Accepted. Supersedes the original statement order in `SendInvoiceService`.

## Context

`SendInvoiceService::send()` originally ran the aggregate guard, then the notifier, then the
compare-and-swap, in that order, to read like the specification's "change the status to sending
after sending the notification". A comment claimed the order was free because both writes commit
together, so a loser's rollback took its recorded intent with it and the customer was notified
once.

That reasoning only holds for the outbox adapter, where notifying is another row in the same
transaction. Under `INVOICE_NOTIFIER=direct` it was false: two concurrent requests both read a
draft, both pass `markAsSending()`, and both reach `facade->notify()` before either runs the
compare-and-swap. The customer gets two e-mails, and the loser then receives a 409 for a message
that had already gone out. A rollback cannot recall an e-mail.

## Decision

The compare-and-swap runs before the notifier. The order inside `send()` is now: the aggregate's
guard, then the conditional UPDATE that claims the invoice, then the notification.

The specification is still satisfied. Nothing inside a transaction is observable until the
commit, so "the status changes after the notification is sent" is satisfied by the commit rather
than by which line comes first — for the outbox adapter both writes land together either way.
What the order buys is that the only thing able to decide a race now runs before the only thing
that cannot be rolled back.

## Consequences

- Exactly one request reaches the provider under either adapter, so `direct` stops being a
  correctness trap and becomes merely a latency trade-off.
- The loser of a race notifies nobody at all, which is asserted directly rather than inferred
  from the rollback.
- `SendInvoiceServiceTest` asserts the order explicitly (`['swap', 'notify']`) through a shared
  call log. That test exists to stop the order being "tidied" back, since the code reads equally
  naturally either way and only one way is correct.
- A failing provider call under `direct` still rolls the status back to draft, so no compensating
  transition is needed and the state machine keeps exactly the three transitions the
  specification defines.
- One window remains under `direct`, and it is inherent to doing I/O in a transaction rather than
  to this ordering: if the provider call succeeds and the commit then fails, the e-mail went out
  while the status stayed `draft`. This is one more reason `outbox` is the default.
