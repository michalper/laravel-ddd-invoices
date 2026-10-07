<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Commands;

final readonly class CreateInvoiceCommand
{
    /** @param list<CreateProductLineCommand> $productLines */
    public function __construct(
        public string $customerName,
        public string $customerEmail,
        public array $productLines = [],
    ) {}
}
