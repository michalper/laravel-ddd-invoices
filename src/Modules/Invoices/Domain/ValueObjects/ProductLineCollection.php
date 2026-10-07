<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\ValueObjects;

use Countable;

/**
 * Keeps the aggregate small and gives "total price is the sum of the line totals"
 * exactly one home.
 */
final readonly class ProductLineCollection implements Countable
{
    /** @param list<ProductLine> $lines */
    private function __construct(
        private array $lines,
    ) {}

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
        return array_sum(array_map(
            static fn (ProductLine $line): int => $line->totalUnitPrice(),
            $this->lines,
        ));
    }

    /** @return list<ProductLine> */
    public function toArray(): array
    {
        return $this->lines;
    }
}
