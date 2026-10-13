<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Delivery Challan against a Proforma Invoice — see DeliveryController.
 */
class Delivery extends Model
{
    protected $fillable = [
        'delivery_no',
        'proforma_invoice_id',
        'site_id',
        'delivery_date',
        'sale_amount',
        'cost_amount',
        'vehicle_no',
        'receiver_name',
        'note',
        'created_by',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'sale_amount' => 'decimal:2',
        'cost_amount' => 'decimal:2',
    ];

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function ledgerTransaction(): MorphOne
    {
        return $this->morphOne(LedgerTransaction::class, 'reference');
    }
}
