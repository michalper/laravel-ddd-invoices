<?php

declare(strict_types=1);

namespace Tests\E2E;

use Psr\Http\Message\ResponseInterface;

/**
 * The only level where the concurrency control is actually concurrent.
 *
 * Everywhere else in this suite a race is simulated sequentially — call claim()
 * twice, or stub compareAndSwapStatus() to return false — which tests the handling
 * of a lost race but never produces one. The guarantees this module is built on
 * (ADR 0002's conditional UPDATE, ADR 0004's claim-before-notify) are precisely the
 * ones a sequential test cannot exercise, because nothing contends.
 *
 * Two things have to be true of the environment or these tests pass vacuously, and
 * both were established by breaking the code and watching the test react rather
 * than by reasoning about it. They are arranged in the `concurrency-control` job in
 * .github/workflows/e2e.yml, not here:
 *
 * - **A server database, not SQLite.** SQLite has no concurrent writers: the second
 *   request's transaction waits for the first to commit, then reads `sending` and is
 *   rejected by the aggregate's in-memory guard, so the conditional UPDATE is never
 *   reached at all. On SQLite this test passes even with the affected-row check
 *   replaced by `return true`.
 * - **`--no-reload` on `artisan serve`.** Laravel's ServeCommand refuses to honour
 *   PHP_CLI_SERVER_WORKERS without it and warns that it is "only creating a single
 *   server" (see ServeCommand::initialize()). A single-process server serialises
 *   these requests into a sequence that always passes. The job greps for that
 *   warning and fails on it, because a silently single-threaded run would make this
 *   whole file decoration.
 *
 * With both in place the test has real teeth: replacing the affected-row check with
 * `return true` makes it report five accepted sends out of eight instead of one.
 */
final class ConcurrentSendE2ETest extends E2ETestCase
{
    /** Enough to contend reliably without turning this into a load test. */
    private const int CONCURRENCY = 8;

    /**
     * Exactly one request may be accepted. The rest must lose cleanly with 409 —
     * not 500, and not a second 202.
     *
     * What this level can observe is "exactly one request was accepted". That it
     * also means exactly one notification follows from the claim preceding the
     * provider call, which SendInvoiceServiceTest pins directly; here the point is
     * that the conditional UPDATE really does admit one winner when the contention
     * is real rather than staged.
     */
    public function test_concurrent_sends_accept_exactly_one_request(): void
    {
        $invoiceId = $this->invoiceId($this->data($this->createInvoice([
            ['name' => 'Widget', 'quantity' => 2, 'unit_price' => 500],
        ])));

        $statuses = $this->concurrently('POST', "api/invoices/{$invoiceId}/send");

        $accepted = array_keys($statuses, 202, true);
        $conflicted = array_keys($statuses, 409, true);

        self::assertCount(1, $accepted, sprintf(
            'Exactly one send may be accepted; got %s.',
            json_encode(array_count_values($statuses)),
        ));
        self::assertCount(self::CONCURRENCY - 1, $conflicted, sprintf(
            'Every loser must be a clean 409; got %s.',
            json_encode(array_count_values($statuses)),
        ));

        // The winner's write landed, and no loser corrupted it on the way out.
        self::assertSame('sending', $this->str($this->data($this->call('GET', "api/invoices/{$invoiceId}")), 'status'));
    }

    /**
     * The provider may legitimately deliver the same webhook more than once, and
     * nothing stops it arriving twice at the same instant. Duplicates must be
     * absorbed rather than raced: the compare-and-swap admits one, and the rest
     * recognise the state as already applied.
     */
    public function test_concurrent_delivery_webhooks_are_absorbed(): void
    {
        $invoiceId = $this->invoiceId($this->data($this->createInvoice([
            ['name' => 'Widget', 'quantity' => 1, 'unit_price' => 100],
        ])));

        self::assertSame(202, $this->call('POST', "api/invoices/{$invoiceId}/send")->getStatusCode());

        $statuses = $this->concurrently('GET', "api/notification/hook/delivered/{$invoiceId}");

        // Every one of them is accepted; the module decides internally which single
        // call actually moves the status.
        self::assertSame(
            [204 => self::CONCURRENCY],
            array_count_values($statuses),
            'A duplicate delivery webhook must never surface an error.',
        );

        self::assertSame('sent-to-client', $this->awaitStatus($invoiceId, 'sent-to-client'));
    }

    /**
     * Fires the same request CONCURRENCY times and returns the status codes.
     *
     * Every request is handed to Guzzle before any of them is waited on, and they
     * all share one curl multi handle — so waiting on them in turn does not
     * serialise them. The multi handle drives every pending transfer while it waits
     * for the first, which is what makes these genuinely overlap rather than go out
     * back to back.
     *
     * A rejected promise throws out of wait(), which is the behaviour we want:
     * http_errors is off for this client, so a 409 is an ordinary response and only
     * a transport-level failure rejects. That should fail the test loudly rather
     * than be counted as a status code.
     *
     * @return list<int>
     */
    private function concurrently(string $method, string $path): array
    {
        $promises = [];

        for ($i = 0; $i < self::CONCURRENCY; $i++) {
            $promises[] = $this->http->requestAsync($method, ltrim($path, '/'));
        }

        $statuses = [];

        foreach ($promises as $promise) {
            $response = $promise->wait();

            self::assertInstanceOf(ResponseInterface::class, $response);

            $statuses[] = $response->getStatusCode();
        }

        return $statuses;
    }
}
