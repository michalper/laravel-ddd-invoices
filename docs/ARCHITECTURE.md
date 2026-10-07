# Architecture

Directory/class map for anyone working on the code. Not shipped documentation for end users — see
[README.md](../README.md) for that, and [docs/adr/](adr/) for why the load-bearing decisions are
the way they are.

The module is `src/Modules/Invoices`, autoloaded as `Modules\Invoices\` (see `composer.json`).
`src/Modules/Notifications` came with the task and is treated as a published dependency, not as
code to change — the brief calls it a deliberately simple mock.

## Layering

Four layers, with dependencies pointing inwards only:

- `Domain/` — the aggregate, its value objects, its state machine, its exceptions, and the
  repository interface. No framework, no Eloquent, no container. This is the layer that makes the
  invariants unbypassable; see [ADR 0001](adr/0001-keep-the-aggregate-framework-free.md).
- `Application/` — use-case services, the outbound ports, the commands they take, and the
  delivery listener. Depends on Domain only.
- `Infrastructure/` — Eloquent models and the mapper, the repository implementation, the two
  notifier adapters, the outbox, the queue job, the console command, the service provider.
- `Presentation/` — controllers, the form request, the presenter, the exception mapper, routes.

## Configuration

- `config/invoices.php` — `notifier` (`outbox` | `direct`) and `reconcile_after_minutes`
- `.env.example` — `INVOICE_NOTIFIER`, `INVOICE_RECONCILE_AFTER_MINUTES`
- `bootstrap/providers.php` — registers `InvoiceServiceProvider`
- `bootstrap/app.php` — `withExceptions()` calls `InvoiceExceptionMapper::register()`
- `routes/api.php` — requires `src/Modules/Invoices/Presentation/routes.php` first, then the
  Notifications one; the order matters, see [ADR 0006](adr/0006-register-the-provider-non-deferrably.md)
- `config/database.php` — SQLite `busy_timeout`/`journal_mode`/`synchronous`, set because the
  queue worker and the web container write the same file

## Domain

- `Entities/Invoice.php` — the aggregate. `draft()` hardcodes `StatusEnum::Draft`, so "an invoice
  can only be created as a draft" is a state that cannot be represented rather than a rule to
  validate. `restore()` is the only way to build a non-draft invoice and exists for the mapper.
  No public setter: the only route to a new status is `markAsSending()` / `markAsSentToClient()`,
  both of which consult the state machine through one private guard.
- `Enums/StatusEnum.php` — carries the transition map as data, because that is a fact about
  statuses rather than behaviour of the aggregate
- `ValueObjects/ProductLine.php` — enforces positive quantity and unit price, and that their
  product is representable, in the constructor
- `ValueObjects/ProductLineCollection.php` — owns "total is the sum of the line totals", summed
  with an explicit overflow check in the constructor
- `Repositories/InvoiceRepositoryInterface.php` — includes `compareAndSwapStatus()`, the
  persistence-level enforcement of the same precondition the aggregate checks
- `Exceptions/` — `InvoiceException` is the abstract base every module failure extends, which is
  what lets Presentation map them all with one `renderable()`

## Application

- `Services/CreateInvoiceService.php` — builds the aggregate and persists it
- `Services/GetInvoiceService.php` — reads one invoice
- `Services/SendInvoiceService.php` — the send workflow: aggregate guard, then the
  compare-and-swap claim, then the notifier, all in one transaction. The order is load-bearing;
  see [ADR 0004](adr/0004-claim-before-notifying.md).
- `Services/MarkInvoiceDeliveredService.php` — the delivery side. Swallows state anomalies and
  records them, propagates infrastructure failures, because only the latter are worth a provider
  retry.
- `Listeners/WebhookDeliveredListener.php` — adapts `WebhookDeliveredEvent` to the service
- `Ports/InvoiceNotifierInterface.php` — "durably record that this invoice must be notified", not
  "make an HTTP call"; that wording is what lets the default adapter write a row inside the
  caller's transaction
- `Ports/TransactionManagerInterface.php` — exists so the services stay unit-testable without
  booting the framework for a database connection
- `Exceptions/InvoiceSendFailedException.php` — a provider refusal (502). Carries the cause as
  `previous` only, never in the message.

## Infrastructure

- `Persistence/Eloquent/InvoiceModel.php`, `ProductLineModel.php`, `OutboxMessageModel.php` —
  named `*Model` and never leave this namespace
- `Persistence/Eloquent/InvoiceMapper.php` — the whole cost of keeping Eloquent out of the
  domain, about sixty lines
- `Persistence/Eloquent/OutboxStatus.php` — `pending`, `processing`, `processed`, `failed`, plus
  `unsettled()`, the guard every terminal write uses
- `Persistence/EloquentInvoiceRepository.php` — includes `compareAndSwapStatus()`, a conditional
  UPDATE checked by affected-row count; see [ADR 0002](adr/0002-compare-and-swap-over-locking.md)
- `Persistence/OutboxRepository.php` — `enqueue()`, `claim()`, `release()`, `markProcessed()`,
  `markFailed()`, `recordAttempt()`, `stalled()`, `failed()`, `countFailed()`,
  `invoicesSendingWithoutOutbox()`
- `Persistence/DatabaseTransactionManager.php` — the port's one production implementation
- `Notifications/OutboxInvoiceNotifier.php` — the default adapter: writes the outbox row in the
  caller's transaction and dispatches the job `afterCommit()`; see
  [ADR 0003](adr/0003-transactional-outbox-for-the-send-intent.md)
- `Notifications/NotificationFacadeInvoiceNotifier.php` — the `direct` adapter, kept as an
  exhibit of the trade-off it makes
- `Notifications/InvoiceNotificationMessage.php` — subject and body for the provider
- `Notifications/ProcessOutboxMessageJob.php` — drains one row. Claims it conditionally first, so
  a duplicate delivery cannot notify twice. Carries only the row id, never a serialised model.
- `Console/ReconcileInvoiceSendingCommand.php` — `invoices:reconcile`, with `--minutes` and
  `--strict`; see [ADR 0007](adr/0007-reconcile-from-the-outbox-row.md)
- `Providers/InvoiceServiceProvider.php` — bindings, the notifier switch, the event listener.
  Deliberately not deferrable.

## Presentation

- `Http/Controllers/CreateInvoiceController.php` — `POST /api/invoices` → 201 with `Location`
- `Http/Controllers/ViewInvoiceController.php` — `GET /api/invoices/{invoiceId}` → 200
- `Http/Controllers/SendInvoiceController.php` — `POST /api/invoices/{invoiceId}/send` → 202
- `Http/Requests/CreateInvoiceRequest.php` — guards the shape of the request; the aggregate
  guards the invariant, and the overlap is deliberate
- `Http/InvoiceExceptionMapper.php` — one `renderable()` typed against the abstract base maps
  every module exception to a status; adding a failure mode costs one row in a table. See
  [ADR 0005](adr/0005-one-renderable-for-domain-exceptions.md).
- `Presenters/InvoicePresenter.php` — the JSON shape
- `routes.php` — the three routes. The parameter is named `invoiceId` rather than `reference` or
  `action` on purpose: the Notifications routes file registers those names with
  `Route::pattern()`, which is router-global.

## Database

- `database/migrations/*_create_invoices_table.php` — came with the task
- `database/migrations/*_create_invoice_product_lines_table.php` — came with the task
- `database/migrations/2026_10_07_120000_create_invoice_notification_outbox_table.php` — the
  outbox. Two indexes: `['status', 'updated_at']` for the stalled query, `['status',
  'created_at']` for the failure report.

There is no `version` column on `invoices` and the provided schema is not ours to change, which
is why the status precondition is enforced with a conditional UPDATE rather than optimistic
locking.

## Tests

- `tests/Unit/` — domain and application services. No container, no database, no
  `RefreshDatabase`; the concrete dividend of keeping the aggregate framework-free.
- `tests/Feature/` — the integration layer: routing, container wiring, validation, ORM mapping,
  transactional behaviour, event registration, against a real HTTP stack and a real database. The
  only double sits at the module boundary, the Notifications `DriverInterface`.
- `tests/E2E/` — extends PHPUnit's `TestCase` rather than Laravel's, on purpose: these tests know
  nothing about the application's internals, not even its container, and talk to a running
  instance over the network. Excluded from the default run by `defaultTestSuite` in
  `phpunit.xml`; needs `E2E_BASE_URL`.
- `tests/Architecture/` — the layering, enforced rather than described. Not PHPUnit tests: phpat
  compiles them into PHPStan rules, so they run inside the analysis step and a violation is
  reported at the offending line. Eight rules, each corresponding to a decision in `docs/adr/`.
- `tests/Support/Invoices/` — `CreatesInvoices` (the shared trait), `RecordingDriver`,
  `RecordingLogger`, `SpyNotifier`, `ThrowingDriver`, `CallLog`,
  `ImmediateTransactionManager`. Fakes rather than doubles where nothing is verified through the
  doubling API.

Two levels exist because a property could not be reached any other way:

- The **database matrix** (`ci.yml`) runs the Feature suite on MySQL and PostgreSQL as well as
  SQLite, because the compare-and-swap's correctness is a claim about every driver. It earned its
  place immediately by finding that product-line ordering was engine-dependent.
- The **smoke job** below is the only one that boots the application the way a reviewer does.
- The **smoke job** (`smoke.yml`) runs `./start.sh` on a fresh clone and walks the lifecycle with
  curl, because everything else boots the application with `artisan serve` and never touches the
  path a reviewer follows.

### Concurrency

`tests/Feature/Invoices/Infrastructure/ConcurrentClaimTest.php` is the one place where a race is
actually raced: two real transactions on two connections, with the second blocked on the row the
first holds. It departs from the rest of the suite twice, both deliberately — `DatabaseTruncation`
instead of `RefreshDatabase`, because writes have to commit to be contended for, and
`DB::setDefaultConnection()` to run the real repository method on each connection rather than
re-implementing its SQL in the test. It skips on SQLite, which has no concurrent writers at all,
so it runs in the database matrix.

Its power was measured rather than assumed: with the affected-row check replaced by `return true`
it fails 6 runs out of 6, on both MySQL and PostgreSQL.

An earlier HTTP-level attempt was built and removed, because the same measurement showed it had
none — eight parallel sends still reported a single acceptance in 6 runs out of 6 with the same
break in place. Two things defeated it. PHP's built-in server is a development server, and under
parallel load it returned three HTTP 500s that never reached PHP at all, logging nothing and
completing in 0.03ms. And on SQLite the second transaction simply waits, then reads `sending` and
is rejected by the aggregate's guard, so the conditional UPDATE is never reached. The lesson is
kept here because it is the reason this test lives at the database level and not over HTTP.

## Quality gates

All of these run in CI; see `.github/workflows/`.

- `phpunit.xml` — `defaultTestSuite="Unit,Feature"`, coverage measured over `src`
- `phpstan.neon` — level max with larastan, `tmpDir: build/phpstan` so the result cache is
  cacheable. The baseline holds only errors in the two test files that shipped with the task.
- `rector.php` — targets `UP_TO_PHP_85` to match `composer.json`, runs as `--dry-run` in CI
- `infection.json5` — mutation testing, gated at 75 MSI, with log-call mutations ignored by
  configuration and the reasoning recorded
- `tools/check-coverage.php` — the line-coverage floor, because PHPUnit has no built-in one
