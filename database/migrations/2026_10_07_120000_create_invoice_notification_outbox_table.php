<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The transactional outbox for invoice notifications.
 *
 * Its reason to exist: the invoice status and the intent to notify must commit
 * together, or the two can disagree and a send is either lost or duplicated. Both
 * writes land in this database in one transaction, which removes the dual write
 * entirely; reaching the provider then becomes a separate, retryable step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_notification_outbox', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->string('status');
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('invoices');

            // One index per reconciliation query. Stalled messages are selected by
            // "nothing has happened to this row lately", which is updated_at;
            // permanently failed ones are reported oldest-problem-first, which is
            // created_at. Leading with status keeps both selective, since the vast
            // majority of rows settle as `processed`.
            $table->index(['status', 'updated_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_notification_outbox');
    }
};
