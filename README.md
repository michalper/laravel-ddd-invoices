# Invoices Module

[![CI](https://github.com/michalper/laravel-ddd-invoices/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/michalper/laravel-ddd-invoices/actions/workflows/ci.yml)
[![End-to-end](https://github.com/michalper/laravel-ddd-invoices/actions/workflows/e2e.yml/badge.svg?branch=main)](https://github.com/michalper/laravel-ddd-invoices/actions/workflows/e2e.yml)
[![Mutation testing](https://github.com/michalper/laravel-ddd-invoices/actions/workflows/mutation.yml/badge.svg?branch=main)](https://github.com/michalper/laravel-ddd-invoices/actions/workflows/mutation.yml)

![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-2a5d8f)
![Coverage gate](https://img.shields.io/badge/coverage%20gate-95%25-brightgreen)
![Mutation gate](https://img.shields.io/badge/MSI%20gate-75%25-brightgreen)

The three status badges are live. The five below them state the thresholds CI
enforces rather than the current measurements, so they cannot quietly go stale as
the numbers move: the gates live in `phpunit.xml`, `phpstan.neon`,
`tools/check-coverage.php` and `infection.json5`, and a run that falls under one
fails. At the time of writing the module sits at 100% line coverage and 80%
covered-code MSI.

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

## Running the test suites

The default run is the `Unit` and `Feature` suites, which need no services and no
migration step — they build an in-memory SQLite database per test class:

```bash
vendor/bin/phpunit
```

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
vendor/bin/phpstan analyse        # level max, no baseline entries for this module
vendor/bin/pint --test            # style, check only
vendor/bin/rector process --dry-run
vendor/bin/infection --threads=max   # mutation score, gated at 75 MSI
php tools/check-coverage.php coverage.xml src/Modules/Invoices 95
```

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
```

`INVOICE_RECONCILE_AFTER_MINUTES` (default 15) sets how long a message may sit
before it counts as stalled; `--minutes` overrides it per run. `--strict` exists so
CI can gate on the result — a scheduled run stays quiet, because "found work and
did it" is not a failure.

Re-dispatching is safe to overlap: claiming an outbox row is a conditional UPDATE,
so a duplicate job cannot produce a duplicate notification.

## Known limitation

The delivery webhook (`GET /api/notification/hook/delivered/{reference}`) is
unauthenticated and unsigned, and it is what moves an invoice to the terminal
`sent-to-client` state. Anyone who learns an invoice id can therefore forge a
delivery confirmation. That route, its controller and its event belong to the
provided Notifications module, which the brief calls a deliberately simple mock, so
it has been left exactly as delivered rather than redesigned. In a real system it
would need a signed URL or a provider HMAC, and it should be a `POST`. It is listed
here rather than silently inherited.
