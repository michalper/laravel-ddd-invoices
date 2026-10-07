# ADR 0008: Give an outbox row somewhere to end up other than "failed forever"

## Status

Accepted. Extends [ADR 0007](0007-reconcile-from-the-outbox-row.md), whose consequences section
recorded both of these as outstanding.

## Context

The outbox made the send intent durable and `invoices:reconcile` re-drove anything stalled, but
a row that ran out of road had nowhere to go.

`markProcessed()` and `markFailed()` are guarded by `unsettled()` — `pending` and `processing` —
and `stalled()` selects only those two. So `failed` was terminal in the strongest sense: nothing
in the application could move a row out of it. A message that exhausted its five attempts left
its invoice in `sending` permanently, and `invoices:reconcile` reported it as critical on every
run while offering no remedy. An operator who had just fixed the provider outage that caused it
had exactly one option: write UPDATE statements against production.

The same rows never stopped alerting either. Bounding how many the reconciler names per run kept
the log volume survivable, but not the permanence — a problem somebody decided weeks ago not to
pursue still shouted today, and an alert that never stops is an alert nobody reads. The only way
to end it was to delete the row, which buys quiet by destroying the record that anything
happened.

And nothing pruned. One `processed` row per invoice sent, kept for ever. The uninteresting cost is
disk; the interesting one is that `payload` holds the customer's name, e-mail and the message
body, so an untended outbox is a second copy of personal data living outside whatever retention
policy applies to invoices — and nothing in the application knows it is there.

## Decision

A fourth status and three commands.

`OutboxStatus::Abandoned` is terminal like `Failed` but *acknowledged*. `failed()` and
`countFailed()` still select only `Failed`, so abandoning a message genuinely stops the alert
rather than muting the channel, and the row survives to say it happened.

- **`invoices:outbox:retry {id}`**, or `--all` for a provider-wide outage, moves `failed` back to
  `pending` and re-dispatches. Guarded on `failed`, so it cannot resurrect a message in flight or
  one somebody closed deliberately. `attempts` is not reset: it is the history of what this
  message cost, not a quota.
- **`invoices:outbox:abandon {id} --reason=`** moves `failed` to `abandoned`. The reason is
  mandatory, because it is the entire difference between closing a problem and hiding one.
- **`invoices:outbox:prune --days=N`** empties the payload of resolved rows past the retention
  window, set by `INVOICE_RETAIN_PAYLOAD_DAYS` (30 days by default).

Pruning *redacts rather than deletes*, because the two things have different useful lifetimes:
that this invoice was notified, when, and after how many attempts stays worth keeping long after
what was said does not. A `redacted_at` column makes the empty payload legible as a decision
instead of a bug, and stops the next run revisiting the same rows.

`failed` rows are never pruned. They are terminal but unresolved, and destroying the provider's
error message is destroying the one thing whoever has to diagnose it needs.

Both bulk commands do one bounded pass and say when they stopped at their limit, rather than
looping until the set empties. A loop would need a guard against reading rows it cannot reopen —
reachable only when another process is changing them underneath — and that guard is a branch no
test can honestly exercise.

The schema change is a second migration rather than an edit to the first, which has already been
applied; rewriting applied migrations is how environments drift apart.

## Consequences

- A permanently failed notification is recoverable without touching the database by hand, which
  is the difference between an operable system and one that merely reports its own problems.
- The critical alert now means "needs a human", not "has ever needed a human", so it stays worth
  paging on.
- Personal data in the outbox has a bounded lifetime, independent of how long invoices are kept.
- Abandoning is deliberately a one-way door with no "unabandon": reopening it would need the same
  guard relaxed, and a message somebody consciously closed should be re-sent through a new send,
  not resurrected.
- `invoices:outbox:prune` needs scheduling to be worth anything. Nothing in this repository
  schedules it, because where cron lives is a deployment decision rather than an application one —
  but an unscheduled prune is exactly the dead config this module has already had to remove once.
- The retry path can produce a duplicate notification: if the original attempt reached the
  provider but failed to record it, re-driving sends again. The invoice id is still the
  idempotency key the provider receives as `reference`, so this is the same at-least-once
  guarantee ADR 0003 already makes, surfaced at a moment a human chose.
