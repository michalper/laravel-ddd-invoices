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
}
