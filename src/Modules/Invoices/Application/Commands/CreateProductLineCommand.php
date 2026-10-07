<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Commands;

final readonly class CreateProductLineCommand
{
    public function __construct(
        public string $name,
        public int $quantity,
        public int $unitPrice,
    ) {}
}
