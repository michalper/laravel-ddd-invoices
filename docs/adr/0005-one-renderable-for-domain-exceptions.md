# ADR 0005: Map domain exceptions to HTTP in one renderable, and keep provider errors out of the body

## Status

Accepted.

## Context

Six module failures need six different status codes: unknown invoice (404), illegal transition or
a lost race (409), no product lines (422), an invalid product line (422), and a provider refusal
(502). The usual shapes are try/catch in each controller, which repeats the mapping and grows
with every new failure mode, or one `match` in a handler, which has to be kept in step by hand.

A second problem sat on top of it. `InvoiceSendFailedException::forInvoice()` interpolated
`$previous->getMessage()` into its own message, and the mapper returns `getMessage()` in the
response body.

## Decision

`InvoiceExceptionMapper::register()` installs a single `renderable()` typed against the module's
abstract base, `InvoiceException`. Laravel matches these callbacks with `is_a()`, so one callback
catches every module exception, and the mapping is a `class-string => int` table. Adding a
failure mode costs one row. The controllers contain no try/catch at all.

The response is unconditionally JSON. An earlier content-negotiation guard here was unreachable —
`bootstrap/app.php` already forces JSON for `api/*` — which mutation testing established by
surviving three mutations of it.

The cause of a send failure travels as `previous` only, never in the message. The client gets a
stable sentence naming the invoice; the chain still reaches the log.

## Consequences

- One place knows how a module failure looks over HTTP, so the domain stays ignorant of status
  codes and a new failure mode cannot be forgotten in one controller but not another.
- Because a `renderable()` callback runs *before* the framework's own rendering, `APP_DEBUG=false`
  does not mask its body the way it masks an unhandled 500. Anything put in an exception message
  here is published to API clients. That is why the provider's error is excluded, and it is a
  constraint to remember when adding the next failure mode.
- Exceptions are still reported, so they appear in the log with full stack traces even when the
  response is a tidy 422. The e2e run's log is therefore noisy by design.
- The mapping is keyed on the exact class (`$e::class`), not on inheritance, so a future subclass
  of an existing exception falls through to 500 rather than inheriting its parent's status. That
  is deliberate — it fails loudly instead of guessing — but it is a trap if someone subclasses
  without adding a row.
