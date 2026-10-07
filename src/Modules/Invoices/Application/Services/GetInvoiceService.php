<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Services;

use Modules\Invoices\Domain\Entities\Invoice;
use Modules\Invoices\Domain\Exceptions\InvoiceNotFoundException;
use Modules\Invoices\Domain\Repositories\InvoiceRepositoryInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class GetInvoiceService
{
    public function __construct(
        private InvoiceRepositoryInterface $repository,
    ) {}

    /** @throws InvoiceNotFoundException */
    public function get(UuidInterface $id): Invoice
    {
        return $this->repository->get($id);
    }
}
