<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\PicksProducts;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\LedgerAccount;
use App\Models\Party;
use App\Models\Purchase;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseRequisition;
use App\Models\Site;
use App\Models\StockMovement;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase Orders against approved Inventory Requisitions, and the Goods
 * Receipts that bring the goods into Factory/Office stock and record the
 * purchase cost in Accounts (docs/order-to-delivery.md).
 */
class PurchaseController extends Controller implements HasMiddleware
{
    use PicksProducts;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:purchases.view', only: ['index', 'show', 'print']),
            new Middleware('permission:purchases.create', only: ['create', 'store']),
            new Middleware('permission:purchases.edit', only: ['edit', 'update', 'cancel']),
            new Middleware('permission:purchases.receive', only: ['receiveForm', 'receive']),
        ];
    }

    public function index(Request $request)
    {
        $status = array_key_exists($request->get('status'), Purchase::STATUSES) ? $request->get('status') : null;
        $q = $request->get('q');

        $purchases = Purchase::with(['party', 'site', 'proformaInvoice', 'requisition'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($q, fn ($query) => $query->where(fn ($query) => $query
                ->where('purchase_no', 'like', "%{$q}%")
                ->orWhere('vendor_name', 'like', "%{$q}%")
                ->orWhereHas('party', fn ($p) => $p->where('name', 'like', "%{$q}%"))))
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $awaitingOrder = PurchaseRequisition::where('status', 'approved')->count();

        return view('admin.purchases.index', compact('purchases', 'status', 'q', 'awaitingOrder'));
    }

    /**
     * Only from an approved requisition — the cart is pre-filled from its
     * lines (quantities/estimated costs stay editable, lines removable).
     */
    public function create(Request $request)
    {
        $requisition = $request->filled('from_requisition')
            ? PurchaseRequisition::where('status', 'approved')
                ->with(['proformaInvoice.party', 'site', 'items.product.stockUnit', 'items.productVariant.attributeValues'])
                ->find($request->integer('from_requisition'))
            : null;

        $approvedRequisitions = PurchaseRequisition::where('status', 'approved')->with('proformaInvoice')->orderByDesc('id')->get()
            ->map(fn ($r) => (object) ['id' => $r->id, 'name' => "{$r->requisition_no} — {$r->proformaInvoice->pi_no}"]);

        $items = $requisition
            ? $requisition->items->map(fn ($item) => $this->itemRow($item, [
                'quantity' => (float) $item->quantity,
                'unit_cost' => (float) $item->estimated_unit_cost,
            ]))->values()
            : collect();

        return view('admin.purchases.create', $this->formOptions() + compact('requisition', 'approvedRequisitions', 'items'));
    }

    /**
     * Places the order only — no stock or ledger effect until goods are
     * received (see receive()).
     */
    public function store(Request $request)
    {
        [$validated, $rows, $total] = $this->validateOrder($request, true);

        $purchase = DB::transaction(function () use ($validated, $rows, $total) {
            $requisition = PurchaseRequisition::whereKey($validated['purchase_requisition_id'])
                ->where('status', 'approved')
                ->lockForUpdate()
                ->first();

            if (! $requisition) {
                throw ValidationException::withMessages(['purchase_requisition_id' => 'This requisition is no longer available to order against.']);
            }

            $purchase = Purchase::create([
                'purchase_no' => 'PENDING',
                'purchase_requisition_id' => $requisition->id,
                'proforma_invoice_id' => $requisition->proforma_invoice_id,
                'party_id' => $validated['party_id'] ?? null,
                'vendor_name' => empty($validated['party_id']) ? $validated['vendor_name'] : null,
                'vendor_phone' => empty($validated['party_id']) ? ($validated['vendor_phone'] ?? null) : null,
                'site_id' => $validated['site_id'],
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'status' => 'pending',
                'total_amount' => $total,
                'note' => $validated['note'] ?? null,
                'created_by' => Auth::id(),
            ]);
            $purchase->update(['purchase_no' => 'PO-'.str_pad($purchase->id, 6, '0', STR_PAD_LEFT)]);

            $this->createItems($purchase, $rows);

            $requisition->update(['status' => 'ordered']);

            return $purchase;
        });

        return redirect()->route('purchases.show', $purchase)
            ->with('success', "Purchase Order {$purchase->purchase_no} placed with {$purchase->vendorName()}.");
    }

    public function show(Purchase $purchase)
    {
        $purchase->load([
            'party', 'site', 'creator', 'requisition', 'proformaInvoice.party',
            'items.product.stockUnit', 'items.productVariant.attributeValues',
            'receipts.site', 'receipts.receivedBy', 'receipts.paidFrom', 'receipts.items.purchaseItem.product',
        ]);

        return view('admin.purchases.show', compact('purchase'));
    }

    public function edit(Purchase $purchase)
    {
        if ($purchase->status !== 'pending') {
            return redirect()->route('purchases.show', $purchase)->with('error', 'Only a pending Purchase Order (nothing received yet) can be edited.');
        }

        $purchase->load(['requisition.proformaInvoice.party', 'items.product.stockUnit', 'items.productVariant.attributeValues']);

        $items = $purchase->items->map(fn ($item) => $this->itemRow($item, [
            'quantity' => (float) $item->quantity,
            'unit_cost' => (float) $item->unit_cost,
        ]))->values();

        return view('admin.purchases.edit', $this->formOptions() + [
            'purchase' => $purchase,
            'requisition' => $purchase->requisition,
            'items' => $items,
        ]);
    }

    /**
     * Pending only — nothing has been received, so no receipt references
     * these lines and replacing them wholesale is safe.
     */
    public function update(Request $request, Purchase $purchase)
    {
        if ($purchase->status !== 'pending') {
            return back()->with('error', 'Only a pending Purchase Order can be edited.');
        }

        [$validated, $rows, $total] = $this->validateOrder($request, false);

        DB::transaction(function () use ($purchase, $validated, $rows, $total) {
            $purchase->update([
                'party_id' => $validated['party_id'] ?? null,
                'vendor_name' => empty($validated['party_id']) ? $validated['vendor_name'] : null,
                'vendor_phone' => empty($validated['party_id']) ? ($validated['vendor_phone'] ?? null) : null,
                'site_id' => $validated['site_id'],
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'total_amount' => $total,
                'note' => $validated['note'] ?? null,
            ]);

            $purchase->items()->delete();
            $this->createItems($purchase, $rows);
        });

        return redirect()->route('purchases.show', $purchase)->with('success', "Purchase Order {$purchase->purchase_no} updated.");
    }

    /**
     * Cancels whatever hasn't arrived yet — receipts already posted stand.
     * If nothing at all was received, the requisition goes back to Approved
     * so it can be ordered from someone else.
     */
    public function cancel(Purchase $purchase)
    {
        if (! $purchase->isReceivable()) {
            return back()->with('error', 'This Purchase Order can no longer be cancelled.');
        }

        DB::transaction(function () use ($purchase) {
            $nothingReceived = $purchase->status === 'pending';
            $purchase->update(['status' => 'cancelled']);

            if ($nothingReceived && $purchase->requisition?->status === 'ordered') {
                $purchase->requisition->update(['status' => 'approved']);
            }
        });

        return back()->with('success', "Purchase Order {$purchase->purchase_no} cancelled.");
    }

    /**
     * Receiving form — every line with something still to arrive; leaving a
     * line at 0 skips it, so the same form covers full and partial receipts.
     */
    public function receiveForm(Purchase $purchase)
    {
        if (! $purchase->isReceivable()) {
            return redirect()->route('purchases.show', $purchase)->with('error', 'This Purchase Order is not open to receive.');
        }

        $purchase->load(['party', 'site', 'items.product.stockUnit', 'items.productVariant.attributeValues']);

        return view('admin.purchases.receive', [
            'purchase' => $purchase,
            'sites' => Site::where('status', true)->orderBy('name')->get(),
            'cashBankAccounts' => LedgerAccount::where('group', 'cash_bank')->where('status', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Goods Receipt: one `purchase` stock movement per line at the receiving
     * Site (Factory or Office) plus exactly one ledger posting for the whole
     * receipt — Dr Inventory against Cr Accounts Payable (registered
     * supplier) or Cr the cash/bank account it was paid from (one-time
     * vendor). That ledger line is the purchase cost record in Accounts.
     */
    public function receive(Request $request, Purchase $purchase)
    {
        if (! $purchase->isReceivable()) {
            return back()->with('error', 'This Purchase Order is not open to receive.');
        }

        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'received_date' => ['required', 'date'],
            'paid_from_account_id' => [$purchase->isOneTimeVendor() ? 'required' : 'nullable', 'integer', 'exists:ledger_accounts,id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
        ]);

        $paidFrom = $purchase->isOneTimeVendor()
            ? LedgerAccount::where('group', 'cash_bank')->findOrFail($validated['paid_from_account_id'])
            : null;

        $purchase->load('items.product');

        $lines = [];
        foreach ($validated['items'] as $purchaseItemId => $row) {
            $quantity = (float) ($row['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $item = $purchase->items->firstWhere('id', (int) $purchaseItemId);

            if (! $item) {
                throw ValidationException::withMessages(["items.{$purchaseItemId}" => 'Invalid item.']);
            }

            if ($quantity > $item->remaining() + 0.00001) {
                throw ValidationException::withMessages([
                    "items.{$purchaseItemId}.quantity" => "{$item->product->name}: cannot receive more than the remaining {$item->remaining()}.",
                ]);
            }

            $lines[] = ['item' => $item, 'quantity' => $quantity];
        }

        if (! $lines) {
            throw ValidationException::withMessages(['items' => 'Enter a received quantity for at least one item.']);
        }

        $receipt = DB::transaction(function () use ($purchase, $validated, $lines, $paidFrom) {
            $receipt = PurchaseReceipt::create([
                'receipt_no' => 'PENDING',
                'purchase_id' => $purchase->id,
                'site_id' => $validated['site_id'],
                'received_date' => $validated['received_date'],
                'paid_from_account_id' => $paidFrom?->id,
                'note' => $validated['note'] ?? null,
                'received_by' => Auth::id(),
            ]);
            $receipt->update(['receipt_no' => 'GRN-'.str_pad($receipt->id, 6, '0', STR_PAD_LEFT)]);

            $total = 0.0;

            foreach ($lines as $row) {
                $item = $row['item'];

                $receipt->items()->create([
                    'purchase_item_id' => $item->id,
                    'quantity' => $row['quantity'],
                    'unit_cost' => $item->unit_cost,
                ]);

                StockMovement::create([
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'site_id' => $validated['site_id'],
                    'type' => 'purchase',
                    'quantity' => $row['quantity'],
                    'unit_cost' => $item->unit_cost,
                    'moved_at' => $validated['received_date'],
                    'created_by' => Auth::id(),
                    'reference_type' => PurchaseReceipt::class,
                    'reference_id' => $receipt->id,
                ]);

                $item->update(['received_quantity' => (float) $item->received_quantity + $row['quantity']]);

                $total += round($row['quantity'] * (float) $item->unit_cost, 2);
            }

            $total = round($total, 2);
            $receipt->update(['total_amount' => $total]);

            if ($total > 0) {
                LedgerService::post([
                    'type' => 'purchase',
                    'date' => $validated['received_date'],
                    'site_id' => $validated['site_id'],
                    'narration' => "Goods received against {$purchase->purchase_no} ({$receipt->receipt_no}) from {$purchase->vendorName()}",
                    'reference' => $receipt,
                    'created_by' => Auth::id(),
                    'lines' => [
                        ['account' => 'inventory', 'debit' => $total],
                        $paidFrom
                            ? ['account' => $paidFrom, 'credit' => $total]
                            : ['account' => 'accounts_payable', 'credit' => $total, 'party_id' => $purchase->party_id],
                    ],
                ]);
            }

            $purchase->recalculateStatus();

            return $receipt;
        });

        return redirect()->route('purchases.show', $purchase)
            ->with('success', "{$receipt->receipt_no}: goods received into {$receipt->site->name} stock.");
    }

    public function print(Purchase $purchase)
    {
        $purchase->load(['party', 'site', 'creator', 'proformaInvoice', 'requisition', 'items.product.stockUnit', 'items.productVariant.attributeValues']);
        $company = CompanySetting::current();

        return view('admin.purchases.print', compact('purchase', 'company'));
    }

    protected function formOptions(): array
    {
        return [
            'suppliers' => Party::where('is_supplier', true)->where('status', true)->orderBy('name')->get(['id', 'name']),
            'sites' => Site::where('status', true)->orderBy('name')->get(),
            'itemOptions' => $this->productOptions(),
        ];
    }

    /**
     * @return array{0: array, 1: array, 2: float}
     */
    protected function validateOrder(Request $request, bool $creating): array
    {
        $validated = $request->validate([
            'purchase_requisition_id' => [$creating ? 'required' : 'nullable', 'integer', 'exists:purchase_requisitions,id'],
            'vendor_type' => ['required', 'in:supplier,one_time'],
            'party_id' => ['nullable', 'required_if:vendor_type,supplier', 'integer', 'exists:parties,id'],
            'vendor_name' => ['nullable', 'required_if:vendor_type,one_time', 'string', 'max:150'],
            'vendor_phone' => ['nullable', 'string', 'max:30'],
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item' => ['required', 'string', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validated['vendor_type'] === 'one_time') {
            $validated['party_id'] = null;
        } elseif (! Party::whereKey($validated['party_id'])->where('is_supplier', true)->exists()) {
            throw ValidationException::withMessages(['party_id' => 'Pick a party marked as a Supplier.']);
        }

        $rows = [];
        foreach ($validated['items'] as $i => $row) {
            [$product, $variant] = $this->resolveItem($row['item']);

            if (! $product) {
                throw ValidationException::withMessages(["items.{$i}.item" => 'Pick a valid product or variant.']);
            }

            $rows[] = [
                'product' => $product,
                'variant' => $variant,
                'quantity' => (float) $row['quantity'],
                'unit_cost' => (float) $row['unit_cost'],
                'subtotal' => round((float) $row['quantity'] * (float) $row['unit_cost'], 2),
            ];
        }

        return [$validated, $rows, round(array_sum(array_column($rows, 'subtotal')), 2)];
    }

    protected function createItems(Purchase $purchase, array $rows): void
    {
        foreach ($rows as $row) {
            $purchase->items()->create([
                'product_id' => $row['product']->id,
                'product_variant_id' => $row['variant']?->id,
                'quantity' => $row['quantity'],
                'unit_cost' => $row['unit_cost'],
                'subtotal' => $row['subtotal'],
            ]);
        }
    }
}
