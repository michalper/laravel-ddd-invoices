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

    /**
     * The ordering is not decoration, it is the contract.
     *
     * Without an explicit ORDER BY the row order is whatever the engine happens to
     * return. SQLite and PostgreSQL gave insertion order by accident; MySQL does
     * not, because InnoDB clusters on the primary key and these ids are random
     * UUIDs — so `product_lines` in the API response came back in a different,
     * effectively arbitrary order there. Two tests had quietly encoded SQLite's
     * accident as the expected shape.
     *
     * `name` rather than `created_at`, because every line of one invoice is written
     * in a single statement and therefore shares a timestamp to the microsecond,
     * which makes created_at no tiebreak at all. `id` is the final tiebreak so
     * duplicate names are still deterministic.
     *
     * This defines an order rather than preserving the order the client submitted:
     * the provided schema records no line position, and adding one would mean
     * altering a table that came with the task.
     *
     * @return HasMany<ProductLineModel, $this>
     */
    public function productLines(): HasMany
    {
        return $this->hasMany(ProductLineModel::class, 'invoice_id')
            ->orderBy('name')
            ->orderBy('id');
    }
}
