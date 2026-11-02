<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Delivery Challan against a Proforma Invoice, or straight against a
 * confirmed Sales Order — see DeliveryController.
 */
class Delivery extends Model
{
    protected $fillable = [
        'delivery_no',
        'proforma_invoice_id',
        'sales_order_id',
        'site_id',
        'delivery_date',
        'usd_rate',
        'sale_amount_usd',
        'sale_amount',
        'cost_amount',
        'vehicle_no',
        'receiver_name',
        'note',
        'created_by',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'usd_rate' => 'decimal:4',
        'sale_amount_usd' => 'decimal:2',
        'sale_amount' => 'decimal:2',
        'cost_amount' => 'decimal:2',
    ];

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    /**
     * Set when the challan went out straight against the order. It keeps
     * this link after a PI covering the order picks the challan up.
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /**
     * The document the challan was delivered against: its PI if it has
     * one, otherwise the Sales Order.
     */
    public function source(): ProformaInvoice|SalesOrder
    {
        return $this->proformaInvoice ?? $this->salesOrder;
    }

    public function sourceNo(): string
    {
        return $this->proformaInvoice?->pi_no ?? $this->salesOrder->order_no;
    }

    public function sourceUrl(): string
    {
        return $this->proformaInvoice
            ? route('proforma-invoices.show', $this->proformaInvoice)
            : route('sales-orders.show', $this->salesOrder);
    }

    public function party(): Party
    {
        return $this->source()->party;
    }

    public function isVisibleTo(User $user): bool
    {
        return $this->source()->isVisibleTo($user);
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
