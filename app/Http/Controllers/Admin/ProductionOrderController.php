<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\PicksProducts;
use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Models\ProformaInvoice;
use App\Models\ProformaInvoiceItem;
use App\Models\Site;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Production Orders under a Proforma Invoice line (docs/order-to-delivery.md):
 * issuing one consumes the product's BOM raw materials from stock at the
 * production site; completing it receives the finished goods into stock
 * there, costed at exactly what was consumed. No ledger posting is needed
 * — value just moves from raw material to finished goods inside the same
 * Inventory account.
 */
class ProductionOrderController extends Controller implements HasMiddleware
{
    use PicksProducts;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:production.view', only: ['index', 'show']),
            new Middleware('permission:production.create', only: ['create', 'store']),
            new Middleware('permission:production.edit', only: ['start']),
            new Middleware('permission:production.approve', only: ['complete']),
            new Middleware('permission:production.delete', only: ['cancel']),
        ];
    }

    public function index(Request $request)
    {
        $status = array_key_exists($request->get('status'), ProductionOrder::STATUSES) ? $request->get('status') : null;
        $siteId = $request->filled('site_id') ? $request->integer('site_id') : null;
        $q = $request->get('q');

        $orders = ProductionOrder::with(['proformaInvoice.party', 'product.stockUnit', 'productVariant.attributeValues', 'site'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($siteId, fn ($query) => $query->where('site_id', $siteId))
            ->when($q, fn ($query) => $query->where(fn ($query) => $query
                ->where('production_no', 'like', "%{$q}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$q}%"))
                ->orWhereHas('proformaInvoice', fn ($p) => $p->where('pi_no', 'like', "%{$q}%"))))
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'not_started' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $sites = Site::where('status', true)->orderBy('name')->get();
        $counts = ProductionOrder::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('admin.production-orders.index', compact('orders', 'sites', 'status', 'siteId', 'q', 'counts'));
    }

    /**
     * Pick a PI line (a Manufactured product) and a production site; the
     * quantity defaults to what the line still needs produced, and the
     * BOM's material requirement is shown against stock at that site.
     */
    public function create(Request $request)
    {
        $line = $request->filled('proforma_invoice_item_id')
            ? ProformaInvoiceItem::with(['proformaInvoice.party', 'product.stockUnit', 'product.bomItems.material.stockUnit', 'productVariant.attributeValues', 'productionOrders'])
                ->find($request->integer('proforma_invoice_item_id'))
            : null;

        if ($line && (! $line->proformaInvoice->isActive() || ! $line->product->isManufactured())) {
            $line = null;
        }

        $sites = Site::where('status', true)->orderBy('name')->get();
        $site = $request->filled('site_id')
            ? $sites->firstWhere('id', $request->integer('site_id'))
            : ($sites->firstWhere('type', 'Factory') ?? $sites->first());

        $quantity = $line ? (float) ($request->input('quantity') ?: $line->quantityToProduce()) : 0;

        $materials = $line && $site
            ? $line->product->bomItems->map(fn ($bom) => (object) [
                'material' => $bom->material,
                'per_unit' => (float) $bom->quantity,
                'required' => round($quantity * (float) $bom->quantity, 4),
                'available' => $this->stockBalance($bom->material_id, null, $site->id),
            ])
            : collect();

        $lineOptions = ProformaInvoiceItem::with(['proformaInvoice', 'product', 'productVariant.attributeValues'])
            ->whereHas('proformaInvoice', fn ($q) => $q->whereIn('status', ProformaInvoice::ACTIVE_STATUSES))
            ->whereHas('product', fn ($q) => $q->where('product_type', 'manufactured'))
            ->orderByDesc('proforma_invoice_id')
            ->get()
            ->map(fn ($item) => (object) ['id' => $item->id, 'name' => "{$item->proformaInvoice->pi_no} — {$item->label()}"]);

        return view('admin.production-orders.create', compact('line', 'lineOptions', 'sites', 'site', 'quantity', 'materials'));
    }

    /**
     * Issue the order: snapshot the BOM, check every material is on hand at
     * the production site, and post one production_consumption movement
     * per material — all or nothing.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'proforma_invoice_item_id' => ['required', 'integer', 'exists:proforma_invoice_items,id'],
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $line = ProformaInvoiceItem::with(['proformaInvoice', 'product.bomItems.material', 'productionOrders'])->findOrFail($validated['proforma_invoice_item_id']);
        $invoice = $line->proformaInvoice;
        $quantity = (float) $validated['quantity'];

        if (! $invoice->isActive()) {
            throw ValidationException::withMessages(['proforma_invoice_item_id' => "{$invoice->pi_no} is not open for production (status: {$invoice->status_label})."]);
        }

        if (! $line->product->isManufactured()) {
            throw ValidationException::withMessages(['proforma_invoice_item_id' => "{$line->product->name} is not a Manufactured product."]);
        }

        if ($line->product->bomItems->isEmpty()) {
            throw ValidationException::withMessages(['proforma_invoice_item_id' => "{$line->product->name} has no BOM — define its raw materials first."]);
        }

        if ($quantity > $line->quantityToProduce() + 0.00001) {
            throw ValidationException::withMessages(['quantity' => "Only {$line->quantityToProduce()} of {$line->label()} is still to be produced for {$invoice->pi_no}."]);
        }

        $materials = $line->product->bomItems->map(function ($bom) use ($quantity, $validated) {
            $required = round($quantity * (float) $bom->quantity, 4);
            $available = $this->stockBalance($bom->material_id, null, (int) $validated['site_id']);

            if ($required > $available + 0.00001) {
                throw ValidationException::withMessages([
                    'quantity' => "Not enough {$bom->material->name} at this site: {$required} needed, {$available} on hand.",
                ]);
            }

            return ['bom' => $bom, 'required' => $required, 'unit_cost' => (float) $bom->material->estimated_cost];
        });

        $order = DB::transaction(function () use ($validated, $line, $invoice, $quantity, $materials) {
            $order = ProductionOrder::create([
                'production_no' => 'PENDING',
                'proforma_invoice_id' => $invoice->id,
                'proforma_invoice_item_id' => $line->id,
                'product_id' => $line->product_id,
                'product_variant_id' => $line->product_variant_id,
                'site_id' => $validated['site_id'],
                'quantity' => $quantity,
                'status' => 'not_started',
                'start_date' => $validated['start_date'] ?? null,
                'due_date' => $validated['due_date'] ?? null,
                'note' => $validated['note'] ?? null,
                'created_by' => Auth::id(),
            ]);
            $order->update(['production_no' => 'PRD-'.str_pad($order->id, 6, '0', STR_PAD_LEFT)]);

            $materialCost = 0.0;

            foreach ($materials as $row) {
                $order->materials()->create([
                    'material_id' => $row['bom']->material_id,
                    'quantity_per_unit' => $row['bom']->quantity,
                    'quantity' => $row['required'],
                    'unit_cost' => $row['unit_cost'],
                ]);

                StockMovement::create([
                    'product_id' => $row['bom']->material_id,
                    'site_id' => $validated['site_id'],
                    'type' => 'production_consumption',
                    'quantity' => $row['required'],
                    'unit_cost' => $row['unit_cost'],
                    'note' => "Consumed by {$order->production_no} for {$invoice->pi_no}",
                    'moved_at' => now()->toDateString(),
                    'created_by' => Auth::id(),
                    'reference_type' => ProductionOrder::class,
                    'reference_id' => $order->id,
                ]);

                $materialCost += $row['required'] * $row['unit_cost'];
            }

            $order->update(['material_cost' => round($materialCost, 2)]);

            $invoice->markInProgress();

            return $order;
        });

        return redirect()->route('production-orders.show', $order)
            ->with('success', "Production Order {$order->production_no} issued — raw materials consumed from {$order->site->name} stock.");
    }

    public function show(ProductionOrder $productionOrder)
    {
        $productionOrder->load([
            'proformaInvoice.party', 'product.stockUnit', 'productVariant.attributeValues', 'site',
            'materials.material.stockUnit', 'creator', 'completedBy',
        ]);

        return view('admin.production-orders.show', ['order' => $productionOrder]);
    }

    public function start(ProductionOrder $productionOrder)
    {
        if ($productionOrder->status !== 'not_started') {
            return back()->with('error', 'Only a production order that has not started can be started.');
        }

        $productionOrder->update(['status' => 'in_progress', 'started_at' => now()]);

        return back()->with('success', "{$productionOrder->production_no} is now In Progress.");
    }

    /**
     * Receive the output into finished goods stock at the production site.
     * Its unit cost is the materials consumed ÷ units actually produced, so
     * a short run carries its full material cost.
     */
    public function complete(Request $request, ProductionOrder $productionOrder)
    {
        if (! $productionOrder->isOpen()) {
            return back()->with('error', 'This production order is already closed.');
        }

        $validated = $request->validate([
            'produced_quantity' => ['required', 'numeric', 'min:0.0001', 'max:'.(float) $productionOrder->quantity],
        ]);

        $produced = (float) $validated['produced_quantity'];
        $unitCost = round((float) $productionOrder->material_cost / $produced, 4);

        DB::transaction(function () use ($productionOrder, $produced, $unitCost) {
            StockMovement::create([
                'product_id' => $productionOrder->product_id,
                'product_variant_id' => $productionOrder->product_variant_id,
                'site_id' => $productionOrder->site_id,
                'type' => 'production',
                'quantity' => $produced,
                'unit_cost' => $unitCost,
                'note' => "Output of {$productionOrder->production_no}",
                'moved_at' => now()->toDateString(),
                'created_by' => Auth::id(),
                'reference_type' => ProductionOrder::class,
                'reference_id' => $productionOrder->id,
            ]);

            $productionOrder->update([
                'status' => 'completed',
                'produced_quantity' => $produced,
                'started_at' => $productionOrder->started_at ?? now(),
                'completed_at' => now(),
                'completed_by' => Auth::id(),
            ]);
        });

        return back()->with('success', "{$productionOrder->production_no} completed — finished goods received into {$productionOrder->site->name} stock.");
    }

    /**
     * Cancel before completion: the consumed materials go back into stock
     * at the production site (production_return). Returned without a unit
     * cost so the materials' average cost isn't skewed by a reversal.
     */
    public function cancel(ProductionOrder $productionOrder)
    {
        if (! $productionOrder->isOpen()) {
            return back()->with('error', 'Only an open production order can be cancelled.');
        }

        DB::transaction(function () use ($productionOrder) {
            foreach ($productionOrder->materials as $material) {
                StockMovement::create([
                    'product_id' => $material->material_id,
                    'site_id' => $productionOrder->site_id,
                    'type' => 'production_return',
                    'quantity' => $material->quantity,
                    'note' => "Cancelled: materials returned from {$productionOrder->production_no}",
                    'moved_at' => now()->toDateString(),
                    'created_by' => Auth::id(),
                    'reference_type' => ProductionOrder::class,
                    'reference_id' => $productionOrder->id,
                ]);
            }

            $productionOrder->update(['status' => 'cancelled']);
        });

        return back()->with('success', "{$productionOrder->production_no} cancelled — raw materials returned to stock.");
    }
}
