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
    protected $table = 'invoice_product_lines';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'int',
        'unit_price' => 'int',
    ];
}
