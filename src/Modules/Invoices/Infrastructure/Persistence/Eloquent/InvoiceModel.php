<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A persistence record, not a domain object. It never leaves this namespace — the
 * repository maps it to and from the aggregate, which is what keeps the business
 * rules reachable only through Invoice's named behaviours.
 *
 * @property string $id
 * @property string $customer_name
 * @property string $customer_email
 * @property string $status
 * @property-read Collection<int, ProductLineModel> $productLines
 */
final class InvoiceModel extends Model
{
    #[\Override]
    protected $table = 'invoices';

    #[\Override]
    protected $keyType = 'string';

    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $guarded = [];

    /** @return HasMany<ProductLineModel, $this> */
    public function productLines(): HasMany
    {
        return $this->hasMany(ProductLineModel::class, 'invoice_id');
    }
}
