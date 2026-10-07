<?php

declare(strict_types=1);

namespace Tests\Feature\Invoices\Infrastructure;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Repositories\InvoiceRepositoryInterface;
use Tests\Support\Invoices\CreatesInvoices;

/**
 * Two real transactions contending for one invoice.
 *
 * Every other race in this suite is simulated — call compareAndSwapStatus() twice in
 * a row, or stub it to return false. That covers the handling of a lost race but
 * never produces one, so the claim ADR 0002 rests on was reasoned rather than tested.
 *
 * Two deliberate departures from the rest of the suite make this possible:
 *
 * - **DatabaseTruncation, not RefreshDatabase.** RefreshDatabase wraps each test in a
 *   transaction, so a second connection could not see the invoice at all. Writes here
 *   have to commit to be contended for.
 * - **Two connections, switched by DB::setDefaultConnection().** The models resolve
 *   their connection at query time, so this runs the real
 *   EloquentInvoiceRepository::compareAndSwapStatus() on each one rather than
 *   re-implementing its SQL in the test and proving nothing about our code.
 *
 * An earlier attempt did this over HTTP with parallel requests and was removed: PHP's
 * built-in server is not robust under parallel load, and on SQLite the conditional
 * UPDATE is never even reached. The story is recorded in the "Concurrency" section of
 * docs/ARCHITECTURE.md.
 */
final class ConcurrentClaimTest extends BaseTestCase
{
    use CreatesInvoices;
    use DatabaseTruncation;

    /** A second connection to the same database, registered in setUp(). */
    private const string SECOND = 'concurrent';

    /**
     * tearDown() still runs after a skip, and on SQLite the skip happens before the
     * second connection exists or the schema is guaranteed — so the cleanup below has
     * to know whether there is anything to clean.
     */
    private bool $didRun = false;

    protected function setUp(): void
    {
        parent::setUp();

        $default = config()->string('database.default');

        if ($default === 'sqlite') {
            self::markTestSkipped(
                'SQLite has no concurrent writers: the second transaction waits for the first to commit, '
                .'then the aggregate rejects it before the conditional UPDATE is reached. Run this against '
                .'MySQL or PostgreSQL — the database matrix in ci.yml does.'
            );
        }

        config([
            'database.connections.'.self::SECOND => config('database.connections.'.$default),
        ]);

        $this->didRun = true;
    }

    protected function tearDown(): void
    {
        if (! $this->didRun) {
            parent::tearDown();

            return;
        }

        DB::setDefaultConnection(config()->string('database.default'));
        DB::purge(self::SECOND);

        // If an assertion failed while the first transaction was still open, the
        // deletes below would run inside it and vanish with its rollback — leaking
        // committed rows into later tests, but only on the failure path, which is
        // the worst place for a leak to hide.
        while (DB::connection()->transactionLevel() > 0) {
            DB::connection()->rollBack();
        }

        // DatabaseTruncation cleans up *before* each test, so without this the last
        // test in this class leaves committed rows behind — and the rest of the suite
        // runs inside RefreshDatabase transactions, which roll back their own writes
        // but never see, let alone remove, somebody else's. That showed up as
        // assertDatabaseCount() failures in EloquentInvoiceRepositoryTest, several
        // files away, which is exactly the kind of action at a distance that makes a
        // suite untrustworthy.
        foreach (['invoice_notification_outbox', 'invoice_product_lines', 'invoices'] as $table) {
            DB::table($table)->delete();
        }

        parent::tearDown();
    }

    /**
     * The whole guarantee, in one test: the first claim wins, the second cannot even
     * reach the row while the first holds it, and once the first commits the second
     * matches nothing rather than overwriting it.
     *
     * The middle step is what a sequential test can never show. Without the row lock
     * both transactions would read `draft` and both would believe they had won.
     */
    public function test_only_one_of_two_contending_transactions_claims_the_invoice(): void
    {
        $invoice = $this->persistedDraft();
        $id = $invoice->id();

        $first = DB::connection();
        $first->beginTransaction();

        $wonByFirst = $this->repository()->compareAndSwapStatus($id, StatusEnum::Draft, StatusEnum::Sending);
        self::assertTrue($wonByFirst, 'The first transaction must claim a draft invoice.');

        // The claim is written but not committed, so the row is locked. The second
        // connection has to be given a deadline or it would wait forever and hang the
        // suite instead of failing it.
        $this->giveUpQuicklyOnLocks();

        DB::setDefaultConnection(self::SECOND);

        $blocked = false;

        try {
            $this->repository()->compareAndSwapStatus($id, StatusEnum::Draft, StatusEnum::Sending);
        } catch (QueryException) {
            $blocked = true;
        }

        self::assertTrue($blocked, 'The second transaction must block on the row the first one holds.');

        DB::setDefaultConnection(config()->string('database.default'));
        $first->commit();

        // The row is free again, and now says `sending`. The conditional UPDATE must
        // therefore match nothing — this is the affected-row count doing the work that
        // a version column would do if the provided schema had one.
        DB::setDefaultConnection(self::SECOND);

        $wonBySecond = $this->repository()->compareAndSwapStatus($id, StatusEnum::Draft, StatusEnum::Sending);

        self::assertFalse($wonBySecond, 'The loser must observe zero affected rows, not overwrite the winner.');
    }

    /**
     * Postgres and MySQL spell the same idea differently, and neither has a useful
     * default here: without a deadline the blocked statement waits for the lock
     * indefinitely.
     */
    private function giveUpQuicklyOnLocks(): void
    {
        $connection = DB::connection(self::SECOND);

        match ($connection->getDriverName()) {
            'pgsql' => $connection->statement("SET lock_timeout = '1s'"),
            'mysql', 'mariadb' => $connection->statement('SET SESSION innodb_lock_wait_timeout = 1'),
            default => null,
        };
    }

    private function repository(): InvoiceRepositoryInterface
    {
        return $this->app->make(InvoiceRepositoryInterface::class);
    }
}
