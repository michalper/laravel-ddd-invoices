<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives an outbox row somewhere to end up other than "failed forever".
 *
 * Two gaps this closes. A message that exhausted its retries was terminal with no way
 * back, so the invoice stayed in `sending` permanently and the reconciler reported it
 * as critical on every run with no remedy to offer. And nothing ever pruned a row, so
 * the payload — which carries the customer's name and e-mail — accumulated
 * indefinitely as a second, uncontrolled copy of personal data outside any invoice
 * retention policy.
 *
 * A separate migration rather than an edit to the original: that one has already been
 * applied, and rewriting applied migrations is how environments drift apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_notification_outbox', static function (Blueprint $table): void {
            // Why a human gave up on this message. Distinct from last_error, which is
            // what the provider said; this is what we decided about it.
            $table->text('resolution')->nullable()->after('last_error');

            // Set when the payload has been emptied. Without it a redacted row is
            // indistinguishable from one that was somehow written empty, and the prune
            // would have no way to skip rows it has already handled.
            $table->timestamp('redacted_at')->nullable()->after('processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_notification_outbox', static function (Blueprint $table): void {
            $table->dropColumn(['resolution', 'redacted_at']);
        });
    }
};
