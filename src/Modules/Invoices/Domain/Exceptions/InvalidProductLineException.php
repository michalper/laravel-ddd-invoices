<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Exceptions;

final class InvalidProductLineException extends InvoiceException
{
    public static function invalidQuantity(int $quantity): self
    {
        return new self("Product line quantity must be a positive integer, got {$quantity}.");
    }

    public static function invalidUnitPrice(int $unitPrice): self
    {
        return new self("Product line unit price must be a positive integer, got {$unitPrice}.");
    }

    public static function blankName(): self
    {
        return new self('Product line name cannot be blank.');
    }

    /**
     * Guards the multiplication in ProductLine::totalUnitPrice(). Without it the
     * product silently becomes a float, and a float returned from a method declared
     * `: int` under strict_types is a TypeError — a 500 for what is really a bad
     * request.
     */
    public static function lineTotalOutOfRange(int $quantity, int $unitPrice): self
    {
        return new self(
            "Product line total is too large to represent: {$quantity} x {$unitPrice} exceeds the maximum of ".PHP_INT_MAX.'.',
        );
    }

    /** The same hazard one level up, where the line totals are summed. */
    public static function invoiceTotalOutOfRange(): self
    {
        return new self('The invoice total is too large to represent; reduce the quantities or the number of product lines.');
    }
}
