<?php

declare(strict_types=1);

namespace Tests\Unit\Invoices\Domain;

use Modules\Invoices\Domain\Exceptions\InvalidProductLineException;
use Modules\Invoices\Domain\ValueObjects\ProductLine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class ProductLineTest extends TestCase
{
    #[DataProvider('totals')]
    public function test_total_unit_price_is_quantity_times_unit_price(int $quantity, int $unitPrice, int $expected): void
    {
        $line = ProductLine::create(name: 'Widget', quantity: $quantity, unitPrice: $unitPrice);

        self::assertSame($expected, $line->totalUnitPrice());
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function totals(): iterable
    {
        yield 'single unit' => [1, 500, 500];
        yield 'several units' => [3, 250, 750];
        yield 'smallest possible line' => [1, 1, 1];
    }

    /**
     * totalUnitPrice() is declared `: int`, and PHP turns an overflowing
     * multiplication into a float — which from an int-typed method is a TypeError,
     * so an oversized line would answer 500 instead of 422. Rejecting the pair in
     * the constructor means the unrepresentable line cannot exist in the first
     * place. The boundary is asserted from both sides so the comparison cannot
     * drift by one.
     */
    #[DataProvider('unrepresentableTotals')]
    public function test_a_line_whose_total_cannot_be_represented_is_rejected(int $quantity, int $unitPrice): void
    {
        $this->expectException(InvalidProductLineException::class);

        ProductLine::create(name: 'Widget', quantity: $quantity, unitPrice: $unitPrice);
    }

    /** @return iterable<string, array{int, int}> */
    public static function unrepresentableTotals(): iterable
    {
        yield 'just over the limit' => [intdiv(PHP_INT_MAX, 2) + 1, 2];
        yield 'both factors large' => [PHP_INT_MAX, PHP_INT_MAX];
        yield 'maximum quantity against a price of two' => [PHP_INT_MAX, 2];
    }

    public function test_the_largest_representable_line_is_accepted(): void
    {
        $line = ProductLine::create(name: 'Widget', quantity: intdiv(PHP_INT_MAX, 2), unitPrice: 2);

        self::assertSame(intdiv(PHP_INT_MAX, 2) * 2, $line->totalUnitPrice());
    }

    #[DataProvider('nonPositiveValues')]
    public function test_non_positive_quantity_is_rejected(int $quantity): void
    {
        $this->expectException(InvalidProductLineException::class);

        ProductLine::create(name: 'Widget', quantity: $quantity, unitPrice: 500);
    }

    #[DataProvider('nonPositiveValues')]
    public function test_non_positive_unit_price_is_rejected(int $unitPrice): void
    {
        $this->expectException(InvalidProductLineException::class);

        ProductLine::create(name: 'Widget', quantity: 1, unitPrice: $unitPrice);
    }

    /** @return iterable<string, array{int}> */
    public static function nonPositiveValues(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('blankNames')]
    public function test_blank_name_is_rejected(string $name): void
    {
        $this->expectException(InvalidProductLineException::class);

        ProductLine::create(name: $name, quantity: 1, unitPrice: 500);
    }

    /** @return iterable<string, array{string}> */
    public static function blankNames(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
    }

    public function test_create_generates_an_identifier(): void
    {
        $line = ProductLine::create(name: 'Widget', quantity: 1, unitPrice: 500);

        self::assertTrue(Uuid::isValid($line->id->toString()));
    }

    public function test_identifier_can_be_supplied_for_deterministic_reconstruction(): void
    {
        $id = Uuid::fromString('11111111-1111-4111-8111-111111111111');

        $line = new ProductLine(id: $id, name: 'Widget', quantity: 2, unitPrice: 300);

        self::assertSame($id, $line->id);
        self::assertSame(600, $line->totalUnitPrice());
    }
}
