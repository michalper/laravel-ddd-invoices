<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Invoices\Application\Commands\CreateInvoiceCommand;
use Modules\Invoices\Application\Commands\CreateProductLineCommand;
use Modules\Invoices\Application\Services\CreateInvoiceService;

/**
 * One draft invoice, so `./start.sh --demo` leaves something to look at.
 *
 * Deliberately NOT referenced from DatabaseSeeder. A plain `db:seed` still seeds
 * nothing, and `./start.sh` still leaves an empty database, because fixture data in
 * an API someone is reviewing is a choice rather than a default — it makes it harder
 * to tell what the application created from what was handed to it. Run it by name
 * when you want it:
 *
 *     php artisan db:seed --class=DemoInvoiceSeeder
 *
 * It goes through CreateInvoiceService rather than writing rows directly, so the
 * demo data is created by the same path as a real request and cannot drift into a
 * state the aggregate would refuse. That also means this seeder exercises the
 * container wiring: if the module is misconfigured, seeding fails loudly.
 */
final class DemoInvoiceSeeder extends Seeder
{
    public function run(CreateInvoiceService $service): void
    {
        $invoice = $service->create(new CreateInvoiceCommand(
            customerName: 'Ada Lovelace',
            customerEmail: 'ada@example.com',
            productLines: [
                new CreateProductLineCommand(name: 'Analytical engine, assembly', quantity: 2, unitPrice: 125_00),
                new CreateProductLineCommand(name: 'Punch cards, box of 500', quantity: 3, unitPrice: 12_50),
            ],
        ));

        $id = $invoice->id()->toString();

        $this->command?->info("Demo invoice created: {$id}");
        $this->command?->line("  View: GET  /api/invoices/{$id}");
        $this->command?->line("  Send: POST /api/invoices/{$id}/send");
    }
}
