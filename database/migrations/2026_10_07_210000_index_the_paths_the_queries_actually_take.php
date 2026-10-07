<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the queries the application actually runs — found by a performance
 * audit that asked, for every query, which index serves it.
 *
 * The one that matters: PostgreSQL does not index foreign key columns automatically
 * (MySQL does), so `invoice_product_lines.invoice_id` had no index there, and every
 * read of an invoice — the GET, the send, every delivery webhook — scanned the whole
 * lines table through the eager-loaded relation. That is the only hot-path entry
 * here; the rest serve the scheduled commands.
 *
 * Additive migrations on the two tables that shipped with the task follow the same
 * precedent as the outbox table itself: the schema files are not edited, extended
 * only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_product_lines', static function (Blueprint $table): void {
            // Serves the productLines relation on every invoice read, on engines
            // that do not index FK columns on their own.
            $table->index('invoice_id');
        });

        Schema::table('invoices', static function (Blueprint $table): void {
            // Serves invoicesSendingWithoutOutbox(): one reconcile-run scan of the
            // handful of `sending` rows instead of the whole table.
            $table->index(['status', 'updated_at']);
        });

        Schema::table('invoice_notification_outbox', static function (Blueprint $table): void {
            // The anti-join side of the orphan probe, and the FK's lookup column.
            $table->index('invoice_id');

            // Serves redactable(). Without redacted_at inside the index, every prune
            // run walks the ever-growing redacted history at the old end of the
            // (status, updated_at) range just to discard it — the command would get
            // slower with every message ever sent.
            $table->index(['status', 'redacted_at', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_product_lines', static function (Blueprint $table): void {
            $table->dropIndex(['invoice_id']);
        });

        Schema::table('invoices', static function (Blueprint $table): void {
            $table->dropIndex(['status', 'updated_at']);
        });

        Schema::table('invoice_notification_outbox', static function (Blueprint $table): void {
            $table->dropIndex(['invoice_id']);
            $table->dropIndex(['status', 'redacted_at', 'updated_at']);
        });
    }
};
