<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\ValueObjects;

use Countable;
use Modules\Invoices\Domain\Exceptions\InvalidProductLineException;

/**
 * Keeps the aggregate small and gives "total price is the sum of the line totals"
 * exactly one home.
 */
final readonly class ProductLineCollection implements Countable
{
    private int $total;

    /** @param list<ProductLine> $lines */
    private function __construct(
        private array $lines,
    ) {
        // Summed here rather than on demand so a collection whose total cannot be
        // represented simply cannot exist — the same move ProductLine makes for a
        // single line, and the reason total() can be a plain accessor.
        $this->total = $this->sum($lines);
    }

    /**
     * @param  iterable<mixed>  $lines
     */
    public static function fromArray(iterable $lines): self
    {
        $validated = [];

        foreach ($lines as $line) {
            if (! $line instanceof ProductLine) {
                throw new \InvalidArgumentException(sprintf(
                    'Expected %s, got %s.',
                    ProductLine::class,
                    get_debug_type($line),
                ));
            }

            $validated[] = $line;
        }

        return new self($validated);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function count(): int
    {
        return count($this->lines);
    }

    public function total(): int
    {
        return $this->total;
    }

    /**
     * Deliberately not array_sum(): that returns a float on overflow instead of
     * failing, and a float out of a method declared `: int` is a TypeError, so an
     * oversized invoice would answer 500 rather than 422. Every line total is at
     * least 1, so checking the remaining headroom before each addition is exact.
     *
     * @param  list<ProductLine>  $lines
     */
    private function sum(array $lines): int
    {
        $total = 0;

        foreach ($lines as $line) {
            $lineTotal = $line->totalUnitPrice();

            if ($lineTotal > PHP_INT_MAX - $total) {
                throw InvalidProductLineException::invoiceTotalOutOfRange();
            }

            $total += $lineTotal;
        }

        return $total;
    }

    /** @return list<ProductLine> */
    public function toArray(): array
    {
        return $this->lines;
    }
}
