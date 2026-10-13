<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    /**
     * Pending (editable, nothing received) → Partially Received → Received,
     * with Cancelled as the exit for whatever hasn't arrived yet. Status
     * only moves forward — see recalculateStatus().
     */
    public const STATUSES = [
        'pending' => 'Pending',
        'partial' => 'Partially Received',
        'received' => 'Received',
        'cancelled' => 'Cancelled',
    ];

    public const STATUS_COLORS = [
        'pending' => 'amber',
        'partial' => 'sky',
        'received' => 'emerald',
        'cancelled' => 'rose',
    ];

    protected $fillable = [
        'purchase_no',
        'purchase_requisition_id',
        'proforma_invoice_id',
        'party_id',
        'vendor_name',
        'vendor_phone',
        'site_id',
        'order_date',
        'expected_date',
        'status',
        'total_amount',
        'note',
        'created_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_date' => 'date',
        'total_amount' => 'decimal:2',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOneTimeVendor(): bool
    {
        return $this->party_id === null;
    }

    public function vendorName(): string
    {
        return $this->party?->name ?? $this->vendor_name ?? '—';
    }

    public function isReceivable(): bool
    {
        return in_array($this->status, ['pending', 'partial'], true);
    }

    /**
     * pending → partial → received from received vs ordered quantities.
     * Never moves backward (a cancelled order is never recalculated).
     */
    public function recalculateStatus(): void
    {
        $this->load('items');

        $ordered = (float) $this->items->sum('quantity');
        $received = (float) $this->items->sum('received_quantity');

        $status = match (true) {
            $received <= 0 => 'pending',
            $received + 0.00001 >= $ordered => 'received',
            default => 'partial',
        };

        $this->update(['status' => $status]);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
