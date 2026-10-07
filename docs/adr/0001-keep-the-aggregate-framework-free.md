# ADR 0001: Keep the aggregate framework-free and confine Eloquent to Infrastructure

## Status

Accepted.

## Context

The obvious Laravel shape for this task is an Eloquent `Invoice` model with a `status` column.
The task is explicitly about invariants, though: an invoice may only be created as a draft, may
only move `draft → sending → sent-to-client`, and may only be sent if it has at least one line
with positive quantity and unit price.

An Eloquent model keeps `$invoice->update(['status' => 'sent-to-client'])` one line away from any
caller. Every invariant the exercise is about could be bypassed without the bypass looking
unusual, and no amount of guarding in a service prevents it, because the model sits in the
container and is reachable from anywhere.

## Decision

`Domain/Entities/Invoice.php` is a plain object with no framework dependency. Eloquent lives only
in `Infrastructure/Persistence/Eloquent/`, behind `InvoiceMapper`, and the models are named
`*Model` and never leave that namespace.

The aggregate has no public setter at all. `Invoice::draft()` hardcodes `StatusEnum::Draft`, so
"an invoice can only be created as a draft" becomes a state that cannot be represented rather
than a rule to validate; `Invoice::restore()` is the only way to construct a non-draft invoice
and exists for the mapper. Status changes go through `markAsSending()` and
`markAsSentToClient()`, both of which consult `StatusEnum::allowedTransitions()` via a single
private guard.

`ProductLine` enforces positive quantity and unit price in its constructor, so a line with
non-positive values cannot exist. The specification's send-time rule therefore collapses into
"there must be at least one line", and `markAsSending()` only has to check emptiness.

## Consequences

- The invariants are unbypassable rather than merely documented. There is no second path to a
  status change for a future caller to find, which is why the HTTP endpoint and the delivery
  webhook can share one guard.
- `tests/Unit/` needs no container, no database and no `RefreshDatabase`. That is the concrete
  dividend rather than an aesthetic one: the whole send workflow is exercised with an inline
  transaction fake.
- The mapper is the price — about sixty lines of explicit field copying that has to be kept in
  step with the schema by hand, with nothing but tests to catch a drift.
- Reading an invoice costs a mapping step instead of returning a model, so anything wanting
  Eloquent's conveniences (eager loading, scopes, serialisation) has to be expressed in the
  repository rather than leaned on at the call site.
- Nothing in the language enforces the layering; it is a convention. See the architecture tests
  for the mechanical enforcement that convention needs.
