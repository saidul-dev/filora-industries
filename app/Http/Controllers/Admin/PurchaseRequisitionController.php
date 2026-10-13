<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\PicksProducts;
use App\Http\Controllers\Controller;
use App\Models\ProformaInvoice;
use App\Models\PurchaseRequisition;
use App\Models\Site;
use App\Services\PiFulfillmentPlanner;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inventory Requisitions — what has to be bought for a Proforma Invoice
 * (finished goods for its direct-purchase lines, raw materials for its
 * production lines). Always raised under a live PI; approved requisitions
 * are what Purchase Orders are made against (docs/order-to-delivery.md).
 */
class PurchaseRequisitionController extends Controller implements HasMiddleware
{
    use PicksProducts;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:requisitions.view', only: ['index', 'show']),
            new Middleware('permission:requisitions.create', only: ['create', 'store']),
            new Middleware('permission:requisitions.edit', only: ['edit', 'update']),
            new Middleware('permission:requisitions.delete', only: ['destroy']),
            new Middleware('permission:requisitions.approve', only: ['approve', 'reject']),
        ];
    }

    public function index(Request $request)
    {
        $status = array_key_exists($request->get('status'), PurchaseRequisition::STATUSES) ? $request->get('status') : null;
        $invoiceId = $request->filled('proforma_invoice_id') ? $request->integer('proforma_invoice_id') : null;

        $requisitions = PurchaseRequisition::with(['proformaInvoice.party', 'site', 'requestedBy'])
            ->withCount('items')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($invoiceId, fn ($q) => $q->where('proforma_invoice_id', $invoiceId))
            ->orderByDesc('request_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $invoices = ProformaInvoice::whereHas('requisitions')->orderByDesc('id')->get(['id', 'pi_no'])
            ->map(fn ($pi) => (object) ['id' => $pi->id, 'name' => $pi->pi_no]);

        return view('admin.purchase-requisitions.index', compact('requisitions', 'invoices', 'status', 'invoiceId'));
    }

    /**
     * Picking a PI pre-fills the cart with what it still needs to buy
     * (PiFulfillmentPlanner) — every line stays editable/removable.
     */
    public function create(Request $request, PiFulfillmentPlanner $planner)
    {
        $invoice = $request->filled('proforma_invoice_id')
            ? ProformaInvoice::active()->with('party')->find($request->integer('proforma_invoice_id'))
            : null;

        $items = collect();

        if ($invoice) {
            $items = $planner->purchaseSuggestions($invoice)->map(fn ($row) => [
                'id' => $row->variant ? "variant-{$row->variant->id}" : "product-{$row->product->id}",
                'name' => $row->variant
                    ? "{$row->product->name} — {$row->variant->label} ({$row->variant->sku})"
                    : "{$row->product->name} ({$row->product->sku})",
                'unit' => $row->product->stockUnit?->short_name,
                'quantity' => $row->quantity,
                'unit_cost' => round((float) ($row->variant?->estimated_cost ?? $row->product->estimated_cost), 2),
                'note' => $row->reason === 'raw_material' ? __('Raw material for production') : __('Finished goods for direct delivery'),
            ])->values();
        }

        return view('admin.purchase-requisitions.create', $this->formOptions() + [
            'invoice' => $invoice,
            'items' => $items,
        ]);
    }

    public function store(Request $request)
    {
        [$validated, $rows, $invoice] = $this->validateRequisition($request);

        $requisition = DB::transaction(function () use ($validated, $rows, $invoice) {
            $requisition = PurchaseRequisition::create([
                'requisition_no' => 'PENDING',
                'proforma_invoice_id' => $invoice->id,
                'site_id' => $validated['site_id'],
                'request_date' => $validated['request_date'],
                'required_by' => $validated['required_by'] ?? null,
                'status' => 'pending',
                'note' => $validated['note'] ?? null,
                'requested_by' => Auth::id(),
            ]);
            $requisition->update(['requisition_no' => 'REQ-'.str_pad($requisition->id, 6, '0', STR_PAD_LEFT)]);

            $this->createItems($requisition, $rows);

            $invoice->markInProgress();

            return $requisition;
        });

        return redirect()->route('purchase-requisitions.show', $requisition)
            ->with('success', "Requisition {$requisition->requisition_no} raised under {$invoice->pi_no} — awaiting approval.");
    }

    public function show(PurchaseRequisition $purchaseRequisition)
    {
        $purchaseRequisition->load([
            'proformaInvoice.party', 'site', 'requestedBy', 'approvedBy', 'purchase',
            'items.product.stockUnit', 'items.productVariant.attributeValues',
        ]);

        return view('admin.purchase-requisitions.show', ['requisition' => $purchaseRequisition]);
    }

    public function edit(PurchaseRequisition $purchaseRequisition)
    {
        if (! $purchaseRequisition->isEditable()) {
            return redirect()->route('purchase-requisitions.show', $purchaseRequisition)->with('error', 'Only a pending requisition can be edited.');
        }

        $purchaseRequisition->load(['proformaInvoice.party', 'items.product.stockUnit', 'items.productVariant.attributeValues']);

        $items = $purchaseRequisition->items->map(fn ($item) => $this->itemRow($item, [
            'quantity' => (float) $item->quantity,
            'unit_cost' => (float) $item->estimated_unit_cost,
            'note' => $item->note,
        ]))->values();

        return view('admin.purchase-requisitions.edit', $this->formOptions() + [
            'requisition' => $purchaseRequisition,
            'invoice' => $purchaseRequisition->proformaInvoice,
            'items' => $items,
        ]);
    }

    public function update(Request $request, PurchaseRequisition $purchaseRequisition)
    {
        if (! $purchaseRequisition->isEditable()) {
            return back()->with('error', 'Only a pending requisition can be edited.');
        }

        $request->merge(['proforma_invoice_id' => $purchaseRequisition->proforma_invoice_id]);
        [$validated, $rows] = $this->validateRequisition($request);

        DB::transaction(function () use ($purchaseRequisition, $validated, $rows) {
            $purchaseRequisition->update([
                'site_id' => $validated['site_id'],
                'request_date' => $validated['request_date'],
                'required_by' => $validated['required_by'] ?? null,
                'note' => $validated['note'] ?? null,
            ]);

            $purchaseRequisition->items()->delete();
            $this->createItems($purchaseRequisition, $rows);
        });

        return redirect()->route('purchase-requisitions.show', $purchaseRequisition)
            ->with('success', "Requisition {$purchaseRequisition->requisition_no} updated.");
    }

    public function destroy(PurchaseRequisition $purchaseRequisition)
    {
        if (! $purchaseRequisition->isEditable()) {
            return back()->with('error', 'Only a pending requisition can be deleted.');
        }

        $purchaseRequisition->delete();

        return redirect()->route('purchase-requisitions.index')->with('success', "Requisition {$purchaseRequisition->requisition_no} deleted.");
    }

    public function approve(PurchaseRequisition $purchaseRequisition)
    {
        if ($purchaseRequisition->status !== 'pending') {
            return back()->with('error', 'Only a pending requisition can be approved.');
        }

        $purchaseRequisition->update([
            'status' => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        return back()->with('success', "Requisition {$purchaseRequisition->requisition_no} approved — a Purchase Order can now be raised against it.");
    }

    public function reject(Request $request, PurchaseRequisition $purchaseRequisition)
    {
        if ($purchaseRequisition->status !== 'pending') {
            return back()->with('error', 'Only a pending requisition can be rejected.');
        }

        $validated = $request->validate(['rejection_reason' => ['required', 'string', 'max:255']]);

        $purchaseRequisition->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['rejection_reason'],
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        return back()->with('success', "Requisition {$purchaseRequisition->requisition_no} rejected.");
    }

    protected function formOptions(): array
    {
        return [
            'invoices' => ProformaInvoice::active()->with('party')->orderByDesc('id')->get()
                ->map(fn ($pi) => (object) ['id' => $pi->id, 'name' => "{$pi->pi_no} — {$pi->party->name}"]),
            'sites' => Site::where('status', true)->orderBy('name')->get(),
            'itemOptions' => $this->productOptions(),
        ];
    }

    /**
     * @return array{0: array, 1: array, 2: ProformaInvoice}
     */
    protected function validateRequisition(Request $request): array
    {
        $validated = $request->validate([
            'proforma_invoice_id' => ['required', 'integer', 'exists:proforma_invoices,id'],
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'request_date' => ['required', 'date'],
            'required_by' => ['nullable', 'date', 'after_or_equal:request_date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item' => ['required', 'string', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $invoice = ProformaInvoice::findOrFail($validated['proforma_invoice_id']);

        if (! $invoice->isActive()) {
            throw ValidationException::withMessages(['proforma_invoice_id' => "{$invoice->pi_no} is not open for procurement (status: {$invoice->status_label})."]);
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
                'unit_cost' => isset($row['unit_cost']) && $row['unit_cost'] !== '' ? (float) $row['unit_cost'] : null,
                'note' => $row['note'] ?? null,
            ];
        }

        return [$validated, $rows, $invoice];
    }

    protected function createItems(PurchaseRequisition $requisition, array $rows): void
    {
        foreach ($rows as $row) {
            $requisition->items()->create([
                'product_id' => $row['product']->id,
                'product_variant_id' => $row['variant']?->id,
                'quantity' => $row['quantity'],
                'estimated_unit_cost' => $row['unit_cost'],
                'note' => $row['note'],
            ]);
        }
    }
}
