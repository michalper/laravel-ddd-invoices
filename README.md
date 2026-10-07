# Invoices Module

[![CI](https://github.com/michalper/laravel-ddd-invoices/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/michalper/laravel-ddd-invoices/actions/workflows/ci.yml)
[![End-to-end](https://github.com/michalper/laravel-ddd-invoices/actions/workflows/e2e.yml/badge.svg?branch=main)](https://github.com/michalper/laravel-ddd-invoices/actions/workflows/e2e.yml)
[![Mutation testing](https://github.com/michalper/laravel-ddd-invoices/actions/workflows/mutation.yml/badge.svg?branch=main)](https://github.com/michalper/laravel-ddd-invoices/actions/workflows/mutation.yml)
[![Smoke](https://github.com/michalper/laravel-ddd-invoices/actions/workflows/smoke.yml/badge.svg?branch=main)](https://github.com/michalper/laravel-ddd-invoices/actions/workflows/smoke.yml)

[![Quality Gate](https://sonarcloud.io/api/project_badges/measure?project=michalper_laravel-ddd-invoices&metric=alert_status)](https://sonarcloud.io/project/overview?id=michalper_laravel-ddd-invoices)
[![Maintainability](https://sonarcloud.io/api/project_badges/measure?project=michalper_laravel-ddd-invoices&metric=sqale_rating)](https://sonarcloud.io/project/overview?id=michalper_laravel-ddd-invoices)
[![Security](https://sonarcloud.io/api/project_badges/measure?project=michalper_laravel-ddd-invoices&metric=security_rating)](https://sonarcloud.io/project/overview?id=michalper_laravel-ddd-invoices)
[![Reliability](https://sonarcloud.io/api/project_badges/measure?project=michalper_laravel-ddd-invoices&metric=reliability_rating)](https://sonarcloud.io/project/overview?id=michalper_laravel-ddd-invoices)
[![codecov](https://codecov.io/gh/michalper/laravel-ddd-invoices/graph/badge.svg)](https://codecov.io/gh/michalper/laravel-ddd-invoices)

![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-2a5d8f)
![Mutation gate](https://img.shields.io/badge/MSI%20gate-75%25-brightgreen)

Three kinds of badge, on purpose. The first row is live workflow status. The second
row is live measurement, served by SonarCloud and Codecov from the latest analysis —
those numbers move with the code and cannot go stale. Coverage appears exactly once,
on the Codecov badge, because a current reading and an enforced floor are different
facts: the floor is 95% over `src/Modules/Invoices` (`tools/check-coverage.php`) and
a run below it fails. The third row states the remaining enforced thresholds. As of
the last full audit the module sits at 100% line coverage, 100% mutation code
coverage and 78% covered-code MSI.

## Invoice Structure:

The invoice should contain the following fields:
* **Invoice ID**: Auto-generated during creation.
* **Invoice Status**: Possible states include `draft,` `sending,` and `sent-to-client`.
* **Customer Name** 
* **Customer Email** 
* **Invoice Product Lines**, each with:
  * **Product Name**
  * **Quantity**: Integer, must be positive. 
  * **Unit Price**: Integer, must be positive.
  * **Total Unit Price**: Calculated as Quantity x Unit Price. 
* **Total Price**: Sum of all Total Unit Prices.

## Required Endpoints:

1. **View Invoice**: Retrieve invoice data in the format above.
2. **Create Invoice**: Initialize a new invoice.
3. **Send Invoice**: Handle the sending of an invoice.

## Functional Requirements:

### Invoice Criteria:

* An invoice can only be created in `draft` status. 
* An invoice can be created with empty product lines. 
* An invoice can only be sent if it is in `draft` status. 
* An invoice can only be marked as `sent-to-client` if its current status is `sending`. 
* To be sent, an invoice must contain product lines with both quantity and unit price as positive integers greater than **zero**.

### Invoice Sending Workflow:

* **Send an email notification** to the customer using the `NotificationFacade`. 
  * The email's subject and message may be hardcoded or customized as needed. 
  * Change the **Invoice Status** to `sending` after sending the notification.

### Delivery:

* Upon successful delivery by the Dummy notification provider:
  * The **Notification Module** triggers a `ResourceDeliveredEvent` via webhook.
  * The **Invoice Module** listens for and captures this event.
  * The **Invoice Status** is updated from `sending` to `sent-to-client`.
  * **Note**: This transition requires that the invoice is currently in the `sending` status.

## Technical Requirements:

* **Preferred Approach**: Domain-Driven Design (DDD) is preferred for this project. If you have experience with DDD, please feel free to apply this methodology. However, if you are more comfortable with another approach, you may choose an alternative structure.
* **Alternative Submission**: If you have a different, comparable project or task that showcases your skills, you may submit that instead of creating this task.
* **Tests**: Core invoice logic must be covered by tests. Choose the testing strategy that best demonstrates the correctness of your solution.
* **Documentation**: Candidates are encouraged to document their decisions and reasoning in comments or a README file, explaining why specific implementations or structures were chosen.

## Evaluation Criteria

We look at the overall shape of the solution, not a checklist. In particular:
* Architecture, separation of concerns, and clarity of module boundaries.
* Testing strategy — what you chose to test and why.
* How the send/deliver workflow behaves when things do not go as expected.

## Note on the Notification Module:

The Notification module included in this repository is a minimal, mock integration example. It is intentionally simple and should not be treated as a reference for DDD structure or for the expected invoice design.

## Setup Instructions:

* Start the project by running `./start.sh`.
* To access the container environment, use: `docker compose exec app bash`.

---

# Implementation Notes

Everything above is the task brief as delivered. This section documents the
implementation and is the "docs in README" that `phpunit.xml` and the CI workflows
point at.

## Deviations from the brief

Two, both deliberate, both with the reasoning recorded — listed here so neither reads as an
oversight.

**The status is claimed before the notification is recorded.** The brief says to change the
status to `sending` *after* sending the notification. Inside one transaction nothing is
observable until the commit, so the customer-visible contract is unchanged — but the statement
order matters for correctness: the conditional UPDATE that decides a race has to run before the
only step a rollback cannot undo. Under the default outbox adapter the real `NotificationFacade`
call happens later still, from the queue worker, after the commit. The full argument is
[ADR 0003](docs/adr/0003-transactional-outbox-for-the-send-intent.md) and
[ADR 0004](docs/adr/0004-claim-before-notifying.md).

**The delivery event is `WebhookDeliveredEvent`, not `ResourceDeliveredEvent`.** The brief names
the latter; the Notifications module that ships with the task dispatches the former. The scaffold
is used exactly as delivered, so the listener subscribes to the event that actually exists.

Two companion documents carry the detail that does not belong in a brief:

* **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** — the directory and class map, layer by layer,
  for anyone working on the code.
* **[docs/adr/](docs/adr/)** — the decision records. Eight decisions shape this module, and each
  one has the context, the alternatives that were rejected, and the costs it imposes:
  keeping the aggregate framework-free, compare-and-swap instead of locking, the transactional
  outbox, claiming before notifying, one renderable for the exception mapping, a
  non-deferrable provider, reconciling from the outbox row, and an exit for failed outbox rows.

## The API

Three endpoints, all under `/api`, all JSON in and JSON out. Every response wraps the
invoice in a `data` key; every error is `{"message": "..."}`, and a validation failure
adds Laravel's `errors` object.

### Create an invoice — `POST /api/invoices`

`product_lines` is optional: an invoice may be created with none, and only gains the
obligation to have one when it is sent.

```bash
curl -X POST http://localhost:8080/api/invoices \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{
        "customer_name": "Ada Lovelace",
        "customer_email": "ada@example.com",
        "product_lines": [
          {"name": "Widget", "quantity": 2, "unit_price": 500}
        ]
      }'
```

Answers `201` with a `Location` header pointing at the new invoice:

```json
{
  "data": {
    "id": "0191b1f0-2222-7222-8222-222222222222",
    "status": "draft",
    "customer_name": "Ada Lovelace",
    "customer_email": "ada@example.com",
    "product_lines": [
      {
        "id": "4a1c...",
        "name": "Widget",
        "quantity": 2,
        "unit_price": 500,
        "total_unit_price": 1000
      }
    ],
    "total_price": 1000
  }
}
```

Prices and totals are integers in the currency's minor unit; nothing here is a float.
`quantity` and `unit_price` must be integers of at least 1 — `"2"` as a string is
rejected, because this API is explicitly typed rather than coercive. Product lines
come back in a defined order (by name, then id), not in the order they were sent: the
provided schema records no line position.

### View an invoice — `GET /api/invoices/{invoiceId}`

```bash
curl http://localhost:8080/api/invoices/0191b1f0-2222-7222-8222-222222222222 \
  -H 'Accept: application/json'
```

Answers `200` with the same shape. A malformed identifier is a `404` from the route
constraint, before any controller runs.

### Send an invoice — `POST /api/invoices/{invoiceId}/send`

```bash
curl -X POST http://localhost:8080/api/invoices/0191b1f0-2222-7222-8222-222222222222/send \
  -H 'Accept: application/json'
```

Answers **`202 Accepted`**, not `200`, and the distinction is deliberate: the request
is accepted and the intent is durable, but the terminal state arrives later over the
delivery webhook. `sending` is literally a transitional state.

### Delivery

The provider confirms delivery by calling the Notifications module's webhook, which
is what moves the invoice to its terminal state. With the shipped `DummyDriver`
nothing really calls it, so it is driven by hand:

```bash
curl http://localhost:8080/api/notification/hook/delivered/0191b1f0-2222-7222-8222-222222222222 \
  -H 'Accept: application/json'
```

Answers `204`. The invoice is then `sent-to-client`. A duplicate call is absorbed
rather than refused, because a provider retrying is behaving correctly.

### Status codes

| Code | When |
| --- | --- |
| `201` | invoice created |
| `202` | send accepted; the notification is durably recorded |
| `204` | delivery webhook accepted |
| `404` | no such invoice, or a malformed identifier |
| `409` | the invoice is not in a state that allows this — already sending, already sent, or the loser of two concurrent sends. The payload is fine; the resource's state conflicts |
| `422` | the payload is invalid, or the invoice has no product lines to send |
| `502` | the notification provider refused the message. The cause is logged, not returned |

## Running the test suites

The default run is the `Unit` and `Feature` suites, which need no services and no
migration step — they build an in-memory SQLite database per test class:

```bash
vendor/bin/phpunit
```

`./start.sh` leaves an empty database on purpose — in an API someone is reviewing, fixture data
makes it harder to tell what the application created from what it was handed. Pass `--demo` for a
sample invoice:

```bash
./start.sh --demo                                  # or, in a running container:
php artisan db:seed --class=DemoInvoiceSeeder
```

The seeder goes through `CreateInvoiceService`, so the demo data is created by the same path as a
real request and cannot reach a state the aggregate would refuse.

The `E2E` suite is excluded from that run (`defaultTestSuite` in `phpunit.xml`)
because it talks to a booted application over real HTTP rather than through the
test kernel. It needs a running instance and the base URL in the environment, and
it is the only level that exercises the asynchronous path for real — a genuine web
server, a genuine queue connection and a separate worker process:

```bash
./start.sh
E2E_BASE_URL=http://localhost:8080 vendor/bin/phpunit --testsuite=E2E
```

Without `E2E_BASE_URL` the suite skips itself, so selecting it by accident is
harmless.

The quality gates, all of which CI runs:

```bash
vendor/bin/phpstan analyse        # level max, plus the architecture rules
vendor/bin/pint --test            # style, check only
vendor/bin/rector process --dry-run
vendor/bin/infection --threads=max   # mutation score, gated at 75 MSI
php tools/check-coverage.php coverage.xml src/Modules/Invoices 95
```

The module boundaries are enforced, not just documented: `tests/Architecture/` is compiled into
PHPStan rules by phpat, so a domain class reaching for the framework, or Presentation reaching
into Infrastructure, fails the analysis step above at the offending line.

CI also runs the Feature suite against MySQL and PostgreSQL, because the compare-and-swap's
correctness is a claim about every driver, and a smoke job that runs `./start.sh` on a fresh clone
and walks the lifecycle with curl.

Concurrency is tested with two real contending transactions rather than simulated, on MySQL and
PostgreSQL — SQLite has no concurrent writers, so the test skips there. Its power was measured:
breaking the compare-and-swap makes it fail 6 runs out of 6. See
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for why an earlier HTTP-level attempt was removed
instead of weakened.

## Notification delivery: `INVOICE_NOTIFIER`

The invoice status and the customer's e-mail cannot share a transaction, so the
send path is a dual write. `INVOICE_NOTIFIER` selects how that is resolved:

* **`outbox`** (the default) records the intent in the *same transaction* as the
  status change, so the two cannot disagree, and a queue worker reaches the
  provider afterwards with retries. **This requires a running queue worker.**
  `./start.sh` starts one; without it, invoices sit in `sending` and the
  notification is never delivered, which is the symptom to recognise.
* **`direct`** calls the provider inline and needs no worker, which makes it handy
  for a quick demo. It holds a row lock across an external call, which is exactly
  why it is not the default.

## Reconciliation

The outbox row is the source of truth and the queue dispatch is only a latency
optimisation, so a process that dies between the commit and the dispatch leaves a
row nobody is driving. `invoices:reconcile` re-drives those and reports anything
that failed permanently:

```bash
php artisan invoices:reconcile                 # threshold from config
php artisan invoices:reconcile --minutes=0     # treat everything unsettled as stalled
php artisan invoices:reconcile --strict        # exit non-zero if anything is outstanding
php artisan invoices:reconcile --limit=100     # cap one run's re-dispatches (default 500)
```

`INVOICE_RECONCILE_AFTER_MINUTES` (default 15) sets how long a message may sit
before it counts as stalled; `--minutes` overrides it per run. `--strict` exists so
CI can gate on the result — a scheduled run stays quiet, because "found work and
did it" is not a failure.

Re-dispatching is safe to overlap: claiming an outbox row is a conditional UPDATE,
so a duplicate job cannot produce a duplicate notification.

## When a notification fails for good

A message that exhausts its retries leaves its invoice in `sending`, and
`invoices:reconcile` reports it as critical on every run. Three commands act on that
([ADR 0008](docs/adr/0008-an-outbox-row-needs-somewhere-to-end-up.md)):

```bash
php artisan invoices:outbox:retry <id>          # provider is back: re-drive it
php artisan invoices:outbox:retry --all         # ...or everything, after an outage
php artisan invoices:outbox:retry --all --limit=10   # bounded; says when it truncates
php artisan invoices:outbox:abandon <id> --reason='customer account closed'
php artisan invoices:outbox:prune               # redact payloads past the window
php artisan invoices:outbox:prune --days=7 --limit=100   # override window and batch
```

`abandon` is what stops a resolved problem alerting for ever. It records why, and
keeps the row — deleting it would buy quiet by destroying the evidence that anything
happened. A `--reason` is required for the same reason.

`prune` empties the payload of resolved messages older than
`INVOICE_RETAIN_PAYLOAD_DAYS` (30 by default), because the payload carries the
customer's name and e-mail and would otherwise outlive every retention policy written
for invoices. It redacts rather than deletes: that an invoice was notified, when, and
after how many attempts stays useful long after the message body does. Messages still
in `failed` are never touched — they are unresolved, and the provider's error is what
somebody needs to diagnose them.

Both bulk operations do one bounded pass and say so when they stop at their limit.

The autonomous commands are scheduled in the application itself (`routes/console.php`):
`invoices:reconcile` every five minutes, `invoices:outbox:prune` daily, both
`withoutOverlapping()->onOneServer()`. Deployment only has to run `schedule:run` from
cron or keep `schedule:work` alive — docker-compose ships a `scheduler` service doing
exactly that, next to the queue worker. `retry` and `abandon` are deliberately NOT
scheduled: they are operator tools, and a cron'd `retry --all` would resurrect every
permanently failed message for ever, defeating the point of the `failed` status.

## Known limitation

The delivery webhook (`GET /api/notification/hook/delivered/{reference}`) is
unauthenticated and unsigned, and it is what moves an invoice to the terminal
`sent-to-client` state. Anyone who learns an invoice id can therefore forge a
delivery confirmation. That route, its controller and its event belong to the
provided Notifications module, which the brief calls a deliberately simple mock, so
it has been left exactly as delivered rather than redesigned. In a real system it
would need a signed URL or a provider HMAC, and it should be a `POST`. It is listed
here rather than silently inherited.
