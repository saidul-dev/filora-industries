<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Third-party work billed against a Production Order — dyeing, printing,
 * embroidery and the like. Posted to the supplier's payable when entered.
 */
class ProductionOrderService extends Model
{
    /**
     * Suggestions on the form; any other text is allowed.
     */
    public const COMMON_SERVICES = ['Dyeing', 'Printing', 'Embroidery', 'Washing', 'Finishing', 'Coating', 'Packing', 'Transport'];

    protected $fillable = [
        'production_order_id',
        'production_order_item_id',
        'party_id',
        'service',
        'service_date',
        'quantity',
        'rate',
        'amount',
        'reference_no',
        'note',
        'ledger_transaction_id',
        'created_by',
    ];

    protected $casts = [
        'service_date' => 'date',
        'quantity' => 'decimal:4',
        'rate' => 'decimal:4',
        'amount' => 'decimal:2',
    ];

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderItem::class, 'production_order_item_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
