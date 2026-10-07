<?php

declare(strict_types=1);

namespace Tests\Unit\Invoices\Domain;

use InvalidArgumentException;
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
