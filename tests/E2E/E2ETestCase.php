<?php

declare(strict_types=1);

namespace Tests\E2E;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Base for the end-to-end suite.
 *
 * Deliberately extends PHPUnit's TestCase rather than Laravel's: these tests must
 * know nothing about the application's internals, not even its service container.
 * They talk to a running instance over the network, exactly as a reviewer would,
 * which is the only level that exercises the real web server, the real queue
 * connection and a real worker process — none of which the feature suite can reach.
 *
 * Skipped unless E2E_BASE_URL is set, so the default test run stays hermetic.
 */
abstract class E2ETestCase extends TestCase
{
    protected Client $http;

    protected function setUp(): void
    {
        parent::setUp();

        $baseUrl = getenv('E2E_BASE_URL');

        if (! is_string($baseUrl) || $baseUrl === '') {
            self::markTestSkipped('Set E2E_BASE_URL to run the end-to-end suite against a booted application.');
        }

        $this->http = new Client([
            'base_uri' => rtrim($baseUrl, '/').'/',
            // Status codes are assertions here, not exceptions.
            'http_errors' => false,
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    /** @param array<string, mixed>|null $body */
    protected function call(string $method, string $path, ?array $body = null): ResponseInterface
    {
        return $this->http->request($method, ltrim($path, '/'), $body === null ? [] : ['json' => $body]);
    }

    /** @return array<string, mixed> */
    protected function decode(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** @return array<string, mixed> */
    protected function data(ResponseInterface $response): array
    {
        $decoded = $this->decode($response);

        self::assertArrayHasKey('data', $decoded);
        self::assertIsArray($decoded['data']);

        /** @var array<string, mixed> $data */
        $data = $decoded['data'];

        return $data;
    }

    /** @param list<array{name: string, quantity: int, unit_price: int}> $lines */
    protected function createInvoice(array $lines): ResponseInterface
    {
        return $this->call('POST', 'api/invoices', [
            'customer_name' => 'Ada Lovelace',
            'customer_email' => 'ada@example.com',
            'product_lines' => $lines,
        ]);
    }

    /**
     * Typed accessors, so reading a response is itself an assertion about its shape
     * rather than a cast that quietly accepts anything.
     *
     * @param  array<string, mixed>  $data
     */
    protected function str(array $data, string $key): string
    {
        self::assertArrayHasKey($key, $data);
        self::assertIsString($data[$key]);

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    protected function int(array $data, string $key): int
    {
        self::assertArrayHasKey($key, $data);
        self::assertIsInt($data[$key]);

        return $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    protected function productLines(array $data): array
    {
        self::assertArrayHasKey('product_lines', $data);
        self::assertIsArray($data['product_lines']);

        $lines = [];

        foreach ($data['product_lines'] as $line) {
            self::assertIsArray($line);

            /** @var array<string, mixed> $line */
            $lines[] = $line;
        }

        return $lines;
    }

    /** @param array<string, mixed> $body */
    protected function invoiceId(array $body): string
    {
        return $this->str($body, 'id');
    }

    /**
     * The send endpoint answers before the worker has necessarily drained the
     * outbox, so a status that depends on asynchronous work is polled rather than
     * asserted once.
     */
    protected function awaitStatus(string $invoiceId, string $expected, int $attempts = 20): string
    {
        $status = '';

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $status = $this->str($this->data($this->call('GET', "api/invoices/{$invoiceId}")), 'status');

            if ($status === $expected) {
                return $status;
            }

            usleep(250_000);
        }

        return $status;
    }
}
