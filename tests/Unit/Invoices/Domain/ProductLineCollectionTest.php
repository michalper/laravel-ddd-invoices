<?php

declare(strict_types=1);

namespace Tests\Unit\Invoices\Domain;

use InvalidArgumentException;
use Modules\Invoices\Domain\Exceptions\InvalidProductLineException;
use Modules\Invoices\Domain\ValueObjects\ProductLine;
use Modules\Invoices\Domain\ValueObjects\ProductLineCollection;
use PHPUnit\Framework\TestCase;

final class ProductLineCollectionTest extends TestCase
{
    public function test_empty_collection_is_empty_and_totals_zero(): void
    {
        $collection = ProductLineCollection::empty();

        self::assertTrue($collection->isEmpty());
        self::assertCount(0, $collection);
        self::assertSame(0, $collection->total());
        self::assertSame([], $collection->toArray());
    }

    public function test_total_is_the_sum_of_line_totals(): void
    {
        $collection = ProductLineCollection::fromArray([
            ProductLine::create(name: 'Widget', quantity: 2, unitPrice: 500),
            ProductLine::create(name: 'Gadget', quantity: 3, unitPrice: 100),
        ]);

        self::assertFalse($collection->isEmpty());
        self::assertCount(2, $collection);
        self::assertSame(1300, $collection->total());
    }

    /**
     * Each line here is individually representable, so only the sum overflows. That
     * is the case array_sum() would have turned into a float and therefore a
     * TypeError out of `total(): int` — a 500 for what is really a 422. The guard
     * lives in the constructor, so the collection cannot be built at all.
     */
    public function test_a_collection_whose_total_cannot_be_represented_is_rejected(): void
    {
        $this->expectException(InvalidProductLineException::class);

        ProductLineCollection::fromArray([
            ProductLine::create(name: 'Widget', quantity: PHP_INT_MAX, unitPrice: 1),
            ProductLine::create(name: 'Gadget', quantity: PHP_INT_MAX, unitPrice: 1),
        ]);
    }

    public function test_a_total_at_the_representable_maximum_is_accepted(): void
    {
        $collection = ProductLineCollection::fromArray([
            ProductLine::create(name: 'Widget', quantity: PHP_INT_MAX - 1, unitPrice: 1),
            ProductLine::create(name: 'Gadget', quantity: 1, unitPrice: 1),
        ]);

        self::assertSame(PHP_INT_MAX, $collection->total());
    }

    public function test_from_array_rejects_foreign_elements(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProductLineCollection::fromArray(['not a product line']);
    }

    public function test_from_array_accepts_an_empty_list(): void
    {
        self::assertTrue(ProductLineCollection::fromArray([])->isEmpty());
    }
}
