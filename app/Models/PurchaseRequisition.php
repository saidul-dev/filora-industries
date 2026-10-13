<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequisition extends Model
{
    /**
     * Pending (editable) → Approved → Ordered (a Purchase Order was raised
     * from it), with Rejected / Cancelled as the other exits.
     */
    public const STATUSES = [
        'pending' => 'Pending Approval',
        'approved' => 'Approved',
        'ordered' => 'Ordered',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ];

    public const STATUS_COLORS = [
        'pending' => 'amber',
        'approved' => 'sky',
        'ordered' => 'emerald',
        'rejected' => 'rose',
        'cancelled' => 'slate',
    ];

    /**
     * Requisitions whose quantities still count as "already being sourced"
     * when suggesting what else a PI needs.
     */
    public const OPEN_STATUSES = ['pending', 'approved', 'ordered'];

    protected $fillable = [
        'requisition_no',
        'proforma_invoice_id',
        'site_id',
        'request_date',
        'required_by',
        'status',
        'note',
        'rejection_reason',
        'requested_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'request_date' => 'date',
        'required_by' => 'date',
        'approved_at' => 'datetime',
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
        return $this->hasMany(PurchaseRequisitionItem::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return $this->status === 'pending';
    }

    public function estimatedTotal(): float
    {
        return (float) $this->items->sum(fn ($item) => (float) $item->quantity * (float) $item->estimated_unit_cost);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
