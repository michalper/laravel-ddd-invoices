<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $invoice_id
 * @property string $name
 * @property int $quantity
 * @property int $unit_price
 */
final class ProductLineModel extends Model
{
    #[\Override]
    protected $table = 'invoice_product_lines';

    #[\Override]
    protected $keyType = 'string';

    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $guarded = [];

    #[\Override]
    protected $casts = [
        'quantity' => 'int',
        'unit_price' => 'int',
    ];
}
