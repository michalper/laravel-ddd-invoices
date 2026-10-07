<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Persistence\Eloquent;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $invoice_id
 * @property string $status
 * @property array{to_email: string, subject: string, message: string} $payload
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonInterface|null $processed_at
 * @property CarbonInterface $created_at
 */
final class OutboxMessageModel extends Model
{
    #[\Override]
    protected $table = 'invoice_notification_outbox';

    #[\Override]
    protected $keyType = 'string';

    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $guarded = [];

    #[\Override]
    protected $casts = [
        'payload' => 'array',
        'attempts' => 'int',
        'processed_at' => 'datetime',
    ];
}
