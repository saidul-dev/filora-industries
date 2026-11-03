<?php

namespace App\Models\Concerns;

use App\Models\Party;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Production and Purchase Orders raised for a buyer's order — under a
 * Proforma Invoice, or straight from a confirmed Sales Order before any PI
 * exists. A PI issued later for that order adopts them, so the PI (when
 * set) is always the document to show.
 */
trait SourcedFromSalesDocument
{
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function sourceNo(): string
    {
        return $this->proformaInvoice?->pi_no ?? $this->salesOrder?->order_no ?? '—';
    }

    public function sourceUrl(): ?string
    {
        return match (true) {
            $this->proformaInvoice !== null => route('proforma-invoices.show', $this->proformaInvoice),
            $this->salesOrder !== null => route('sales-orders.show', $this->salesOrder),
            default => null,
        };
    }

    /**
     * The customer the goods are for.
     */
    public function buyer(): ?Party
    {
        return $this->proformaInvoice?->party ?? $this->salesOrder?->party;
    }
}
