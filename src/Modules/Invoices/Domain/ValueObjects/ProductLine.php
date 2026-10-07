<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\ValueObjects;

use Modules\Invoices\Domain\Exceptions\InvalidProductLineException;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * A value object, not an entity: nothing in the specification addresses, updates
 * or removes an individual line, and an invoice is created whole. The id exists
 * only so the row round-trips through persistence and the API can expose a stable
 * identifier; equality is by value, not by id.
 *
 * The invariant is enforced in the constructor, which has a consequence worth
 * knowing: a ProductLine with a non-positive quantity or unit price cannot be
 * represented at all, so the spec's send-time rule ("lines must have positive
 * quantity and unit price") collapses into "there must be at least one line".
 */
final readonly class ProductLine
{
    public function __construct(
        public UuidInterface $id,
        public string $name,
        public int $quantity,
        public int $unitPrice,
    ) {
        if (trim($name) === '') {
            throw InvalidProductLineException::blankName();
        }

        if ($quantity < 1) {
            throw InvalidProductLineException::invalidQuantity($quantity);
        }

        if ($unitPrice < 1) {
            throw InvalidProductLineException::invalidUnitPrice($unitPrice);
        }
    }

    public static function create(string $name, int $quantity, int $unitPrice): self
    {
        return new self(
            id: Uuid::uuid4(),
            name: $name,
            quantity: $quantity,
            unitPrice: $unitPrice,
        );
    }

    public function totalUnitPrice(): int
    {
        return $this->quantity * $this->unitPrice;
    }
}
