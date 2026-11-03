<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\PicksProducts;
use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Models\ProformaInvoice;
use App\Models\ProformaInvoiceItem;
use App\Models\SalesOrderItem;
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

        $orders = ProductionOrder::with(['proformaInvoice.party', 'salesOrder.party', 'product.stockUnit', 'productVariant.attributeValues', 'site'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($siteId, fn ($query) => $query->where('site_id', $siteId))
            ->when($q, fn ($query) => $query->where(fn ($query) => $query
                ->where('production_no', 'like', "%{$q}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$q}%"))
                ->orWhereHas('proformaInvoice', fn ($p) => $p->where('pi_no', 'like', "%{$q}%"))
                ->orWhereHas('salesOrder', fn ($s) => $s->where('order_no', 'like', "%{$q}%"))))
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'not_started' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $sites = Site::where('status', true)->orderBy('name')->get();
        $counts = ProductionOrder::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('admin.production-orders.index', compact('orders', 'sites', 'status', 'siteId', 'q', 'counts'));
    }

    /**
     * Pick a PI line — or a line of a confirmed Sales Order with no PI yet
     * — for a Manufactured product, and a production site; the quantity
     * defaults to what the line still needs produced, and the BOM's
     * material requirement is shown against stock at that site.
     */
    public function create(Request $request)
    {
        $relations = ['product.stockUnit', 'product.bomItems.material.stockUnit', 'productVariant.attributeValues', 'productionOrders'];

        if ($request->filled('sales_order_item_id')) {
            $line = SalesOrderItem::with(array_merge(['salesOrder.party'], $relations))->find($request->integer('sales_order_item_id'));

            if ($line && (! $line->salesOrder->canSourceDirectly() || ! $line->product->isManufactured())) {
                $line = null;
            }
        } else {
            $line = $request->filled('proforma_invoice_item_id')
                ? ProformaInvoiceItem::with(array_merge(['proformaInvoice.party'], $relations))->find($request->integer('proforma_invoice_item_id'))
                : null;

            if ($line && (! $line->proformaInvoice->isActive() || ! $line->product->isManufactured())) {
                $line = null;
            }
        }

        $source = $line ? $this->lineSource($line) : null;

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

        return view('admin.production-orders.create', compact('line', 'source', 'lineOptions', 'sites', 'site', 'quantity', 'materials'));
    }

    /**
     * Issue the order: snapshot the BOM, check every material is on hand at
     * the production site, and post one production_consumption movement
     * per material — all or nothing.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'proforma_invoice_item_id' => ['required_without:sales_order_item_id', 'nullable', 'integer', 'exists:proforma_invoice_items,id'],
            'sales_order_item_id' => ['required_without:proforma_invoice_item_id', 'nullable', 'integer', 'exists:sales_order_items,id'],
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $quantity = (float) $validated['quantity'];

        if (! empty($validated['sales_order_item_id'])) {
            $line = SalesOrderItem::with(['salesOrder', 'product.bomItems.material', 'productionOrders'])->findOrFail($validated['sales_order_item_id']);
            $invoice = null;
            $order = $line->salesOrder;
            $field = 'sales_order_item_id';
            $reference = $order->order_no;

            if (! $order->canSourceDirectly()) {
                throw ValidationException::withMessages([$field => "{$order->order_no} can't be produced for directly (status: {$order->status_label})".($order->status === 'pi_generated' ? ' — raise production under its Proforma Invoice.' : '.')]);
            }
        } else {
            $line = ProformaInvoiceItem::with(['proformaInvoice', 'product.bomItems.material', 'productionOrders'])->findOrFail($validated['proforma_invoice_item_id']);
            $invoice = $line->proformaInvoice;
            $order = null;
            $field = 'proforma_invoice_item_id';
            $reference = $invoice->pi_no;

            if (! $invoice->isActive()) {
                throw ValidationException::withMessages([$field => "{$invoice->pi_no} is not open for production (status: {$invoice->status_label})."]);
            }
        }

        if (! $line->product->isManufactured()) {
            throw ValidationException::withMessages([$field => "{$line->product->name} is not a Manufactured product."]);
        }

        if ($line->product->bomItems->isEmpty()) {
            throw ValidationException::withMessages([$field => "{$line->product->name} has no BOM — define its raw materials first."]);
        }

        if ($quantity > $line->quantityToProduce() + 0.00001) {
            throw ValidationException::withMessages(['quantity' => "Only {$line->quantityToProduce()} of {$line->label()} is still to be produced for {$reference}."]);
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

        $production = DB::transaction(function () use ($validated, $line, $invoice, $order, $reference, $quantity, $materials) {
            $production = ProductionOrder::create([
                'production_no' => 'PENDING',
                'proforma_invoice_id' => $invoice?->id,
                'proforma_invoice_item_id' => $invoice ? $line->id : null,
                // A PI line made from an order line keeps the order line too.
                'sales_order_id' => $order?->id ?? $line->salesOrderItem?->sales_order_id,
                'sales_order_item_id' => $order ? $line->id : $line->sales_order_item_id,
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
            $production->update(['production_no' => 'PRD-'.str_pad($production->id, 6, '0', STR_PAD_LEFT)]);

            $materialCost = 0.0;

            foreach ($materials as $row) {
                $production->materials()->create([
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
                    'note' => "Consumed by {$production->production_no} for {$reference}",
                    'moved_at' => now()->toDateString(),
                    'created_by' => Auth::id(),
                    'reference_type' => ProductionOrder::class,
                    'reference_id' => $production->id,
                ]);

                $materialCost += $row['required'] * $row['unit_cost'];
            }

            $production->update(['material_cost' => round($materialCost, 2)]);

            $invoice?->markInProgress();

            return $production;
        });

        return redirect()->route('production-orders.show', $production)
            ->with('success', "Production Order {$production->production_no} issued — raw materials consumed from {$production->site->name} stock.");
    }

    public function show(ProductionOrder $productionOrder)
    {
        $productionOrder->load([
            'proformaInvoice.party', 'salesOrder.party', 'product.stockUnit', 'productVariant.attributeValues', 'site',
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

    /**
     * What the create form shows about the document the line belongs to.
     *
     * @param  ProformaInvoiceItem|SalesOrderItem  $line
     */
    protected function lineSource($line): object
    {
        $document = $line instanceof SalesOrderItem ? $line->salesOrder : $line->proformaInvoice;

        return (object) [
            'field' => $line instanceof SalesOrderItem ? 'sales_order_item_id' : 'proforma_invoice_item_id',
            'label' => $line instanceof SalesOrderItem ? __('Sales Order') : __('Proforma Invoice'),
            'no' => $line instanceof SalesOrderItem ? $document->order_no : $document->pi_no,
            'party' => $document->party,
            'delivery_date' => $document->delivery_date,
        ];
    }
}
