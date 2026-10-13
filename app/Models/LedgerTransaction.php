<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LedgerTransaction extends Model
{
    /**
     * Fixed voucher types. Every module that posts to the ledger (Payment,
     * Expense, ...) uses one of these — never a free-form string — so
     * voucher numbering and future reporting can rely on it.
     */
    public const TYPES = [
        'payment_out',
        'payment_in',
        'expense',
        'income',
        'transfer',
        'investment',
        'drawing',
        'opening_balance',
        'journal',
        'salary',
        'internal_consumption',
        'purchase',
        'sale',
    ];

    /**
     * Voucher number prefix per type — see LedgerService::nextVoucherNo().
     */
    public const PREFIXES = [
        'payment_out' => 'PAY',
        'payment_in' => 'REC',
        'expense' => 'EXP',
        'income' => 'INC',
        'transfer' => 'TRF',
        'investment' => 'CAP',
        'drawing' => 'DRW',
        'opening_balance' => 'OB',
        'journal' => 'JNL',
        'salary' => 'SLR',
        'internal_consumption' => 'ICN',
        'purchase' => 'PUR',
        'sale' => 'SAL',
    ];

    /**
     * Human-readable label per type — used by the Day Book (and anywhere
     * else that lists mixed-type vouchers) instead of the raw type string.
     */
    public const TYPE_LABELS = [
        'payment_out' => 'Payment',
        'payment_in' => 'Collection',
        'expense' => 'Expense',
        'income' => 'Income',
        'transfer' => 'Fund Transfer',
        'investment' => 'Investment',
        'drawing' => 'Drawing',
        'opening_balance' => 'Opening Balance',
        'journal' => 'Journal',
        'salary' => 'Salary Payment',
        'internal_consumption' => 'Internal Consumption',
        'purchase' => 'Purchase',
        'sale' => 'Sale (Delivery)',
    ];

    protected $fillable = [
        'voucher_no',
        'date',
        'type',
        'reference_type',
        'reference_id',
        'narration',
        'site_id',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(LedgerTransactionLine::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Where the Day Book row's "View" link should point — the specific
     * document this voucher was posted for, not the voucher itself (which
     * has no dedicated screen of its own). Null for types with no
     * per-voucher screen (e.g. a future manual Journal entry).
     */
    public function referenceUrl(): ?string
    {
        if (! $this->reference_type || ! $this->reference_id) {
            return null;
        }

        return match ($this->reference_type) {
            \App\Models\Payment::class => route('payments.show', $this->reference_id),
            \App\Models\Collection::class => route('collections.show', $this->reference_id),
            \App\Models\Expense::class => route('expenses.show', $this->reference_id),
            \App\Models\PayrollRunItem::class => route('payroll-runs.items.payslip', $this->reference_id),
            \App\Models\Income::class => route('incomes.show', $this->reference_id),
            \App\Models\FundTransfer::class => route('fund-transfers.show', $this->reference_id),
            \App\Models\CapitalTransaction::class => route('capital-transactions.show', $this->reference_id),
            \App\Models\Party::class => route('parties.ledger', $this->reference_id),
            \App\Models\StockMovement::class => route('products.history', $this->reference->product_id),
            \App\Models\PurchaseReceipt::class => route('purchases.show', $this->reference->purchase_id),
            \App\Models\Delivery::class => route('deliveries.show', $this->reference_id),
            default => null,
        };
    }
}
