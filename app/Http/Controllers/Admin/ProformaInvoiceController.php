<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\PicksProducts;
use App\Http\Controllers\Controller;
use App\Models\BomItem;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Models\ProformaInvoiceItem;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Proforma Invoices — generated from a confirmed Sales Order and the
 * controlling document for procurement, production, LC, delivery and
 * collection (docs/order-to-delivery.md).
 */
class ProformaInvoiceController extends Controller implements HasMiddleware
{
    use PicksProducts;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:sales.view', only: ['index', 'show', 'print', 'lcFile']),
            new Middleware('permission:sales.edit', only: ['edit', 'update']),
            new Middleware('permission:sales.approve', only: ['store', 'issue', 'attachLc', 'markReady', 'close', 'cancel']),
        ];
    }

    public function index(Request $request)
    {
        $status = array_key_exists($request->get('status'), ProformaInvoice::STATUSES) ? $request->get('status') : null;
        $partyId = $request->filled('party_id') ? $request->integer('party_id') : null;
        $q = $request->get('q');

        $invoices = ProformaInvoice::with(['party', 'salesOrder', 'salesPerson'])
            ->visibleTo(Auth::user())
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($partyId, fn ($query) => $query->where('party_id', $partyId))
            ->when($q, fn ($query) => $query->where(fn ($query) => $query
                ->where('pi_no', 'like', "%{$q}%")
                ->orWhere('lc_no', 'like', "%{$q}%")))
            ->orderByDesc('pi_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $customers = Party::where('is_customer', true)->orderBy('name')->get(['id', 'name']);

        return view('admin.proforma-invoices.index', compact('invoices', 'customers', 'status', 'partyId', 'q'));
    }

    /**
     * Generate a draft PI from a confirmed Sales Order: every order line is
     * copied across with a planned fulfillment path — from existing stock
     * when enough is on hand across all Sites, otherwise production for a
     * Manufactured product and a direct purchase for anything else.
     */
    public function store(SalesOrder $salesOrder)
    {
        if ($salesOrder->status !== 'confirmed') {
            return back()->with('error', 'Only a confirmed Sales Order can be turned into a Proforma Invoice.');
        }

        $salesOrder->load('items.product');

        $invoice = DB::transaction(function () use ($salesOrder) {
            $invoice = ProformaInvoice::create([
                'pi_no' => 'PENDING',
                'sales_order_id' => $salesOrder->id,
                'party_id' => $salesOrder->party_id,
                'sales_person_id' => $salesOrder->sales_person_id,
                'pi_date' => now()->toDateString(),
                'delivery_date' => $salesOrder->delivery_date,
                'status' => 'draft',
                'subtotal_amount' => $salesOrder->subtotal_amount,
                'discount_amount' => $salesOrder->discount_amount,
                'total_amount' => $salesOrder->total_amount,
                'terms' => $salesOrder->delivery_terms,
                'created_by' => Auth::id(),
            ]);
            $invoice->update(['pi_no' => 'PI-'.str_pad($invoice->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($salesOrder->items as $item) {
                $onHand = $this->stockBalance($item->product_id, $item->product_variant_id);

                $invoice->items()->create([
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'subtotal' => $item->subtotal,
                    'fulfillment' => $onHand >= (float) $item->quantity
                        ? 'stock'
                        : ProformaInvoiceItem::defaultFulfillmentFor($item->product),
                ]);
            }

            $salesOrder->update(['status' => 'pi_generated']);

            return $invoice;
        });

        return redirect()->route('proforma-invoices.show', $invoice)
            ->with('success', "Proforma Invoice {$invoice->pi_no} generated from {$salesOrder->order_no} as a draft — review the final items, then issue it.");
    }

    public function show(ProformaInvoice $proformaInvoice)
    {
        $this->authorizeVisible($proformaInvoice);

        $proformaInvoice->load([
            'party', 'salesOrder', 'salesPerson', 'creator',
            'items.product.stockUnit', 'items.product.bomItems.material.stockUnit', 'items.productVariant.attributeValues',
        ]);

        $onHand = $proformaInvoice->items->mapWithKeys(fn ($item) => [
            $item->id => $this->stockBalance($item->product_id, $item->product_variant_id),
        ]);

        $materials = $this->materialRequirements($proformaInvoice);

        return view('admin.proforma-invoices.show', [
            'invoice' => $proformaInvoice,
            'onHand' => $onHand,
            'materials' => $materials,
        ]);
    }

    public function edit(ProformaInvoice $proformaInvoice)
    {
        $this->authorizeVisible($proformaInvoice);

        if (! $proformaInvoice->isEditable()) {
            return redirect()->route('proforma-invoices.show', $proformaInvoice)->with('error', 'Only a draft Proforma Invoice can be edited.');
        }

        $proformaInvoice->load(['party', 'items.product.stockUnit', 'items.productVariant.attributeValues']);

        $items = $proformaInvoice->items->map(fn ($item) => $this->itemRow($item, [
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'fulfillment' => $item->fulfillment,
            'type' => $item->product->product_type,
        ]))->values();

        return view('admin.proforma-invoices.edit', [
            'invoice' => $proformaInvoice,
            'items' => $items,
            'itemOptions' => $this->productOptions(['manufactured', 'direct_purchase']),
        ]);
    }

    /**
     * Draft only — so no requisition, production order or delivery can be
     * referencing these lines yet, and replacing them wholesale is safe.
     */
    public function update(Request $request, ProformaInvoice $proformaInvoice)
    {
        $this->authorizeVisible($proformaInvoice);

        if (! $proformaInvoice->isEditable()) {
            return back()->with('error', 'Only a draft Proforma Invoice can be edited.');
        }

        $validated = $request->validate([
            'pi_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:pi_date'],
            'delivery_date' => ['nullable', 'date'],
            'terms' => ['nullable', 'string', 'max:3000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item' => ['required', 'string', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.fulfillment' => ['required', Rule::in(array_keys(ProformaInvoiceItem::FULFILLMENTS))],
        ]);

        $rows = [];
        foreach ($validated['items'] as $i => $row) {
            [$product, $variant] = $this->resolveItem($row['item']);

            if (! $product) {
                throw ValidationException::withMessages(["items.{$i}.item" => 'Pick a valid product or variant.']);
            }

            if ($row['fulfillment'] === 'production' && ! $product->isManufactured()) {
                throw ValidationException::withMessages(["items.{$i}.fulfillment" => "{$product->name} is not a Manufactured product — it can't be fulfilled by factory production."]);
            }

            $rows[] = [
                'product' => $product,
                'variant' => $variant,
                'quantity' => (float) $row['quantity'],
                'unit_price' => (float) $row['unit_price'],
                'subtotal' => round((float) $row['quantity'] * (float) $row['unit_price'], 2),
                'fulfillment' => $row['fulfillment'],
            ];
        }

        $subtotal = round(array_sum(array_column($rows, 'subtotal')), 2);
        $discount = round((float) ($validated['discount'] ?? 0), 2);

        if ($discount > $subtotal) {
            throw ValidationException::withMessages(['discount' => 'Discount cannot exceed the subtotal.']);
        }

        DB::transaction(function () use ($proformaInvoice, $validated, $rows, $subtotal, $discount) {
            $proformaInvoice->update([
                'pi_date' => $validated['pi_date'],
                'valid_until' => $validated['valid_until'] ?? null,
                'delivery_date' => $validated['delivery_date'] ?? null,
                'terms' => $validated['terms'] ?? null,
                'note' => $validated['note'] ?? null,
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'total_amount' => $subtotal - $discount,
            ]);

            $proformaInvoice->items()->delete();

            foreach ($rows as $row) {
                $proformaInvoice->items()->create([
                    'product_id' => $row['product']->id,
                    'product_variant_id' => $row['variant']?->id,
                    'quantity' => $row['quantity'],
                    'unit_price' => $row['unit_price'],
                    'subtotal' => $row['subtotal'],
                    'fulfillment' => $row['fulfillment'],
                ]);
            }
        });

        return redirect()->route('proforma-invoices.show', $proformaInvoice)->with('success', "Proforma Invoice {$proformaInvoice->pi_no} updated.");
    }

    public function issue(ProformaInvoice $proformaInvoice)
    {
        if (! $proformaInvoice->isEditable()) {
            return back()->with('error', 'Only a draft Proforma Invoice can be issued.');
        }

        $proformaInvoice->update(['status' => 'issued', 'issued_at' => now()]);

        return back()->with('success', "Proforma Invoice {$proformaInvoice->pi_no} issued to {$proformaInvoice->party->name}.");
    }

    /**
     * Attach (or replace) the Letter of Credit. An Issued PI moves to "LC
     * Issued"; a PI already further along keeps its status and just
     * carries the LC details.
     */
    public function attachLc(Request $request, ProformaInvoice $proformaInvoice)
    {
        if (in_array($proformaInvoice->status, ['draft', 'closed', 'cancelled'], true)) {
            return back()->with('error', 'An LC can only be attached to an issued, open Proforma Invoice.');
        }

        $validated = $request->validate([
            'lc_no' => ['required', 'string', 'max:100'],
            'lc_bank' => ['nullable', 'string', 'max:150'],
            'lc_date' => ['nullable', 'date'],
            'lc_expiry_date' => array_filter(['required', 'date', $request->filled('lc_date') ? 'after_or_equal:lc_date' : null]),
            'lc_amount' => ['nullable', 'numeric', 'min:0'],
            'lc_file' => [$proformaInvoice->lc_file_path ? 'nullable' : 'required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $data = collect($validated)->except('lc_file')->all() + ['lc_attached_at' => now()];

        if ($request->hasFile('lc_file')) {
            if ($proformaInvoice->lc_file_path) {
                Storage::disk('local')->delete($proformaInvoice->lc_file_path);
            }

            $data['lc_file_path'] = $request->file('lc_file')->store('lc-documents', 'local');
            $data['lc_file_name'] = $request->file('lc_file')->getClientOriginalName();
        }

        if ($proformaInvoice->status === 'issued') {
            $data['status'] = 'lc_issued';
        }

        $proformaInvoice->update($data);

        return back()->with('success', "LC {$validated['lc_no']} attached to {$proformaInvoice->pi_no}.");
    }

    public function lcFile(ProformaInvoice $proformaInvoice)
    {
        $this->authorizeVisible($proformaInvoice);

        abort_unless($proformaInvoice->lc_file_path && Storage::disk('local')->exists($proformaInvoice->lc_file_path), 404);

        return Storage::disk('local')->response($proformaInvoice->lc_file_path, $proformaInvoice->lc_file_name);
    }

    public function markReady(ProformaInvoice $proformaInvoice)
    {
        if (! in_array($proformaInvoice->status, ['issued', 'lc_issued', 'in_progress'], true)) {
            return back()->with('error', 'This Proforma Invoice cannot be marked Ready for Delivery from its current status.');
        }

        $proformaInvoice->update(['status' => 'ready']);

        return back()->with('success', "{$proformaInvoice->pi_no} is Ready for Delivery.");
    }

    public function close(ProformaInvoice $proformaInvoice)
    {
        if ($proformaInvoice->status !== 'delivered') {
            return back()->with('error', 'Only a fully delivered Proforma Invoice can be closed.');
        }

        $proformaInvoice->update(['status' => 'closed', 'closed_at' => now()]);

        return back()->with('success', "{$proformaInvoice->pi_no} closed.");
    }

    /**
     * Only before anything has been done against it — once goods are being
     * sourced, produced or delivered the PI has to run its course. The
     * Sales Order goes back to Confirmed so a corrected PI can be generated.
     */
    public function cancel(ProformaInvoice $proformaInvoice)
    {
        if (! in_array($proformaInvoice->status, ['draft', 'issued', 'lc_issued'], true)) {
            return back()->with('error', 'This Proforma Invoice already has procurement, production or delivery against it and cannot be cancelled.');
        }

        DB::transaction(function () use ($proformaInvoice) {
            $proformaInvoice->update(['status' => 'cancelled']);

            if ($proformaInvoice->salesOrder?->status === 'pi_generated') {
                $proformaInvoice->salesOrder->update(['status' => 'confirmed']);
            }
        });

        return back()->with('success', "{$proformaInvoice->pi_no} cancelled.");
    }

    public function print(ProformaInvoice $proformaInvoice)
    {
        $this->authorizeVisible($proformaInvoice);

        $proformaInvoice->load(['party', 'salesOrder', 'salesPerson', 'items.product.stockUnit', 'items.productVariant.attributeValues']);
        $company = CompanySetting::current();

        return view('admin.proforma-invoices.print', ['invoice' => $proformaInvoice, 'company' => $company]);
    }

    protected function authorizeVisible(ProformaInvoice $invoice): void
    {
        abort_unless($invoice->isVisibleTo(Auth::user()), 403);
    }

    /**
     * Raw materials the PI's production lines need, per their BOMs:
     * required = Σ (line quantity × BOM quantity per unit), against what's
     * on hand across all Sites.
     *
     * @return Collection<int, object>
     */
    protected function materialRequirements(ProformaInvoice $invoice): Collection
    {
        $required = [];

        foreach ($invoice->items->where('fulfillment', 'production') as $line) {
            foreach ($line->product->bomItems as $bom) {
                /** @var BomItem $bom */
                $required[$bom->material_id] ??= ['material' => $bom->material, 'quantity' => 0.0];
                $required[$bom->material_id]['quantity'] += (float) $line->quantity * (float) $bom->quantity;
            }
        }

        if (! $required) {
            return collect();
        }

        $onHand = StockMovement::whereIn('product_id', array_keys($required))
            ->whereNull('product_variant_id')
            ->selectRaw("product_id, SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) as balance")
            ->groupBy('product_id')
            ->pluck('balance', 'product_id');

        return collect($required)->map(fn ($row, $materialId) => (object) [
            'material' => $row['material'],
            'required' => round($row['quantity'], 4),
            'on_hand' => (float) ($onHand[$materialId] ?? 0),
            'shortage' => max(round($row['quantity'] - (float) ($onHand[$materialId] ?? 0), 4), 0),
        ])->sortBy(fn ($row) => $row->material->name)->values();
    }
}
