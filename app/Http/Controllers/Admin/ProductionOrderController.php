<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\PicksProducts;
use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProformaInvoice;
use App\Models\ProformaInvoiceItem;
use App\Models\SalesOrder;
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
 * Production Orders for one or several manufactured lines of a Sales Order
 * or Proforma Invoice (docs/order-to-delivery.md):
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

        $orders = ProductionOrder::with(['proformaInvoice.party', 'salesOrder.party', 'items.product.stockUnit', 'items.productVariant.attributeValues', 'site'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($siteId, fn ($query) => $query->where('site_id', $siteId))
            ->when($q, fn ($query) => $query->where(fn ($query) => $query
                ->where('production_no', 'like', "%{$q}%")
                ->orWhereHas('items.product', fn ($p) => $p->where('name', 'like', "%{$q}%"))
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
     * Pick a confirmed Sales Order (not on a PI yet) or an active PI, then
     * tick one or several of its manufactured lines — they go into a single
     * Production Order. Lines already on a live Production Order are shown
     * but can't be picked again. The BOM requirement of the ticked lines is
     * totalled per material and checked against stock at the site.
     */
    public function create(Request $request)
    {
        [$document, $preselect] = $this->resolveDocument($request);

        $sites = Site::where('status', true)->orderBy('name')->get();
        $site = $request->filled('site_id')
            ? $sites->firstWhere('id', $request->integer('site_id'))
            : ($sites->firstWhere('type', 'Factory') ?? $sites->first());

        $lines = $document ? $this->documentLines($document) : collect();

        $rows = $lines->map(function ($line) use ($preselect) {
            $live = $line->liveProductionOrder();
            $toProduce = $line->quantityToProduce();
            $available = ! $live && $toProduce > 0 && $line->product->bomItems->isNotEmpty();

            return [
                'id' => $line->id,
                'label' => $line->label(),
                'details' => $line->lineDetails(),
                'unit' => $line->product->stockUnit->short_name ?? '',
                'ordered' => (float) $line->quantity,
                'to_produce' => $toProduce,
                'available' => $available,
                'checked' => $available && ($preselect === null || in_array($line->id, $preselect, true)),
                'qty' => $toProduce,
                'no_bom' => $line->product->bomItems->isEmpty(),
                'bom_url' => route('bom.edit', $line->product),
                'live_no' => $live?->production_no,
                'live_url' => $live ? route('production-orders.show', $live) : null,
                'bom' => $line->product->bomItems->map(fn ($bom) => ['material' => $bom->material_id, 'per_unit' => (float) $bom->quantity])->values(),
            ];
        })->values();

        $materials = $lines->flatMap(fn ($line) => $line->product->bomItems)->unique('material_id')
            ->mapWithKeys(fn ($bom) => [$bom->material_id => [
                'name' => $bom->material->name,
                'unit' => $bom->material->stockUnit->short_name ?? '',
                'available' => $site ? $this->stockBalance($bom->material_id, null, $site->id) : 0,
            ]]);

        $sourceOptions = $this->sourceOptions();
        $selectedSource = $document ? ($document instanceof SalesOrder ? 'so-' : 'pi-').$document->id : null;

        return view('admin.production-orders.create', compact(
            'document', 'rows', 'materials', 'sourceOptions', 'selectedSource', 'sites', 'site',
        ));
    }

    /**
     * The document picked on the form ("so-7" / "pi-3"), or the one a single
     * line links to (the "Produce" links on orders and PIs) with that line
     * preselected.
     *
     * @return array{0: SalesOrder|ProformaInvoice|null, 1: int[]|null}
     */
    protected function resolveDocument(Request $request): array
    {
        $document = null;
        $preselect = null;

        if (preg_match('/^(pi|so)-(\d+)$/', (string) $request->input('source'), $m)) {
            $document = $m[1] === 'so' ? SalesOrder::find($m[2]) : ProformaInvoice::find($m[2]);
        } elseif ($request->filled('sales_order_item_id')) {
            $line = SalesOrderItem::find($request->integer('sales_order_item_id'));
            [$document, $preselect] = [$line?->salesOrder, $line ? [$line->id] : null];
        } elseif ($request->filled('proforma_invoice_item_id')) {
            $line = ProformaInvoiceItem::find($request->integer('proforma_invoice_item_id'));
            [$document, $preselect] = [$line?->proformaInvoice, $line ? [$line->id] : null];
        }

        if ($document instanceof SalesOrder && ! $document->canSourceDirectly()) {
            $document = null;
        }
        if ($document instanceof ProformaInvoice && ! $document->isActive()) {
            $document = null;
        }

        return [$document, $document ? $preselect : null];
    }

    /**
     * Manufactured lines of the document planned for production — an
     * order's plan, or the PI line's fulfillment.
     */
    protected function documentLines(SalesOrder|ProformaInvoice $document)
    {
        $document->load([
            'items.product.stockUnit', 'items.product.bomItems.material.stockUnit',
            'items.productVariant.attributeValues', 'items.productionItems.productionOrder',
        ]);

        return $document->items->sortBy('id')
            ->filter(fn ($line) => $line->product->isManufactured())
            ->filter(fn ($line) => $document instanceof SalesOrder
                ? $line->plannedFulfillment() === 'production'
                : $line->fulfillment === 'production')
            ->filter(fn ($line) => $line->remaining() > 0 || $line->liveProductionOrder())
            ->values();
    }

    /**
     * Sales Orders and PIs that still have at least one manufactured line
     * not on a live Production Order (a PI line also counts the one raised
     * from its order line).
     */
    protected function sourceOptions()
    {
        $live = ProductionOrderItem::whereHas('productionOrder', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->get(['proforma_invoice_item_id', 'sales_order_item_id']);
        $takenPi = $live->pluck('proforma_invoice_item_id')->filter()->flip();
        $takenSo = $live->pluck('sales_order_item_id')->filter()->flip();

        $invoices = ProformaInvoice::with(['party', 'items' => fn ($q) => $q->where('fulfillment', 'production')->with('product')])
            ->whereIn('status', ProformaInvoice::ACTIVE_STATUSES)
            ->orderByDesc('id')->get()
            ->filter(fn ($pi) => $pi->items->contains(fn ($item) => $item->product->isManufactured()
                && $item->remaining() > 0
                && ! $takenPi->has($item->id)
                && ! ($item->sales_order_item_id && $takenSo->has($item->sales_order_item_id))))
            ->map(fn ($pi) => (object) ['id' => "pi-{$pi->id}", 'name' => "{$pi->pi_no} · {$pi->party->name}"]);

        $orders = SalesOrder::with(['party', 'items.product'])
            ->whereIn('status', ['confirmed', 'partially_delivered'])
            ->orderByDesc('id')->get()
            ->filter(fn ($order) => $order->items->contains(fn ($item) => $item->product->isManufactured()
                && $item->plannedFulfillment() === 'production'
                && $item->remaining() > 0
                && ! $takenSo->has($item->id)))
            ->map(fn ($order) => (object) ['id' => "so-{$order->id}", 'name' => "{$order->order_no} · {$order->party->name}"]);

        return $orders->concat($invoices)->values();
    }

    /**
     * Issue the order for the ticked lines: snapshot each line's BOM, check
     * the summed material need is on hand at the site, and post the
     * production_consumption movements — all or nothing.
     *
     * A single line can still be sent the old way (sales_order_item_id or
     * proforma_invoice_item_id + quantity).
     */
    public function store(Request $request)
    {
        $legacyField = collect(['sales_order_item_id', 'proforma_invoice_item_id'])->first(fn ($f) => $request->filled($f));

        if ($legacyField && ! $request->filled('source')) {
            $line = $legacyField === 'sales_order_item_id'
                ? SalesOrderItem::find($request->integer($legacyField))
                : ProformaInvoiceItem::find($request->integer($legacyField));

            if (! $line) {
                throw ValidationException::withMessages([$legacyField => 'That line no longer exists.']);
            }

            $request->merge([
                'source' => $line instanceof SalesOrderItem ? "so-{$line->sales_order_id}" : "pi-{$line->proforma_invoice_id}",
                'items' => [$line->id => $request->input('quantity')],
            ]);
        } else {
            $legacyField = null;
        }

        // Where errors land: per line on the form, the old field names otherwise.
        $lineKey = fn ($id) => $legacyField ?: "items.{$id}";
        $qtyKey = fn ($id) => $legacyField ? 'quantity' : "items.{$id}";
        $totalKey = $legacyField ? 'quantity' : 'items';

        $validated = $request->validate([
            'source' => ['required', 'string', 'regex:/^(pi|so)-\d+$/'],
            'items' => ['required', 'array'],
            'items.*' => ['nullable', 'numeric', 'min:0'],
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'items.required' => 'Tick at least one line to produce.',
        ]);

        [$kind, $documentId] = explode('-', $validated['source']);
        $document = $kind === 'so' ? SalesOrder::findOrFail($documentId) : ProformaInvoice::findOrFail($documentId);
        $invoice = $document instanceof ProformaInvoice ? $document : null;
        $reference = $invoice ? $invoice->pi_no : $document->order_no;

        if (! $invoice && ! $document->canSourceDirectly()) {
            throw ValidationException::withMessages([$legacyField ?: 'source' => "{$document->order_no} can't be produced for directly (status: {$document->status_label})".($document->status === 'pi_generated' ? ' — raise production under its Proforma Invoice.' : '.')]);
        }
        if ($invoice && ! $invoice->isActive()) {
            throw ValidationException::withMessages([$legacyField ?: 'source' => "{$invoice->pi_no} is not open for production (status: {$invoice->status_label})."]);
        }

        $document->load(['items.product.bomItems.material', 'items.productionItems.productionOrder']);
        $quantities = collect($validated['items'])->map(fn ($q) => (float) $q)->filter(fn ($q) => $q > 0);

        if ($quantities->isEmpty()) {
            throw ValidationException::withMessages([$totalKey => 'Enter a quantity for at least one line.']);
        }

        $lines = $quantities->map(function ($quantity, $lineId) use ($document, $reference, $lineKey, $qtyKey) {
            $line = $document->items->firstWhere('id', (int) $lineId);

            if (! $line) {
                throw ValidationException::withMessages([$lineKey($lineId) => "That line is not on {$reference}."]);
            }
            if (! $line->product->isManufactured()) {
                throw ValidationException::withMessages([$lineKey($lineId) => "{$line->product->name} is not a Manufactured product."]);
            }
            if ($line->product->bomItems->isEmpty()) {
                throw ValidationException::withMessages([$lineKey($lineId) => "{$line->product->name} has no BOM — define its raw materials first."]);
            }
            if ($existing = $line->liveProductionOrder()) {
                throw ValidationException::withMessages([$lineKey($lineId) => "{$line->label()} on {$reference} already has Production Order {$existing->production_no} — cancel it first to raise a new one."]);
            }
            if ($quantity > $line->quantityToProduce() + 0.00001) {
                throw ValidationException::withMessages([$qtyKey($lineId) => "Only {$line->quantityToProduce()} of {$line->label()} is still to be produced for {$reference}."]);
            }

            return ['line' => $line, 'quantity' => $quantity];
        })->values();

        // The ticked lines' materials, summed, must be on hand at the site.
        $siteId = (int) $validated['site_id'];
        $lines->flatMap(fn ($row) => $row['line']->product->bomItems->map(fn ($bom) => [
            'bom' => $bom,
            'required' => round($row['quantity'] * (float) $bom->quantity, 4),
        ]))->groupBy(fn ($need) => $need['bom']->material_id)->each(function ($needs, $materialId) use ($siteId, $totalKey) {
            $required = round($needs->sum('required'), 4);
            $available = $this->stockBalance((int) $materialId, null, $siteId);

            if ($required > $available + 0.00001) {
                throw ValidationException::withMessages([
                    $totalKey => "Not enough {$needs->first()['bom']->material->name} at this site: {$required} needed, {$available} on hand.",
                ]);
            }
        });

        $production = DB::transaction(function () use ($validated, $lines, $document, $invoice, $reference, $siteId) {
            // Under a PI the header keeps the Sales Order only when all the
            // lines come from the same one.
            if ($invoice) {
                $orderIds = $lines->map(fn ($row) => $row['line']->salesOrderItem?->sales_order_id)->filter()->unique();
                $salesOrderId = $orderIds->count() === 1 ? $orderIds->first() : null;
            } else {
                $salesOrderId = $document->id;
            }

            $production = ProductionOrder::create([
                'production_no' => 'PENDING',
                'proforma_invoice_id' => $invoice?->id,
                'sales_order_id' => $salesOrderId,
                'site_id' => $siteId,
                'status' => 'not_started',
                'start_date' => $validated['start_date'] ?? null,
                'due_date' => $validated['due_date'] ?? null,
                'note' => $validated['note'] ?? null,
                'created_by' => Auth::id(),
            ]);
            $production->update(['production_no' => 'PRD-'.str_pad($production->id, 6, '0', STR_PAD_LEFT)]);

            $totalCost = 0.0;

            foreach ($lines as $row) {
                $line = $row['line'];
                $item = $production->items()->create([
                    'proforma_invoice_item_id' => $invoice ? $line->id : null,
                    // A PI line made from an order line keeps the order line too.
                    'sales_order_item_id' => $invoice ? $line->sales_order_item_id : $line->id,
                    'product_id' => $line->product_id,
                    'product_variant_id' => $line->product_variant_id,
                    'quantity' => $row['quantity'],
                ]);

                $itemCost = 0.0;

                foreach ($line->product->bomItems as $bom) {
                    $required = round($row['quantity'] * (float) $bom->quantity, 4);
                    $unitCost = (float) $bom->material->estimated_cost;

                    $production->materials()->create([
                        'production_order_item_id' => $item->id,
                        'material_id' => $bom->material_id,
                        'quantity_per_unit' => $bom->quantity,
                        'quantity' => $required,
                        'unit_cost' => $unitCost,
                    ]);

                    StockMovement::create([
                        'product_id' => $bom->material_id,
                        'site_id' => $siteId,
                        'type' => 'production_consumption',
                        'quantity' => $required,
                        'unit_cost' => $unitCost,
                        'note' => "Consumed by {$production->production_no} for {$reference}",
                        'moved_at' => now()->toDateString(),
                        'created_by' => Auth::id(),
                        'reference_type' => ProductionOrder::class,
                        'reference_id' => $production->id,
                    ]);

                    $itemCost += $required * $unitCost;
                }

                $item->update(['material_cost' => round($itemCost, 2)]);
                $totalCost += $itemCost;
            }

            $production->update(['material_cost' => round($totalCost, 2)]);

            $invoice?->markInProgress();

            return $production;
        });

        return redirect()->route('production-orders.show', $production)
            ->with('success', "Production Order {$production->production_no} issued — raw materials consumed from {$production->site->name} stock.");
    }

    public function show(ProductionOrder $productionOrder)
    {
        $productionOrder->load([
            'proformaInvoice.party', 'salesOrder.party', 'site', 'creator', 'completedBy',
            'items.product.stockUnit', 'items.productVariant.attributeValues', 'items.salesOrderItem', 'items.proformaInvoiceItem',
            'materials.material.stockUnit', 'materials.item.product', 'materials.item.productVariant.attributeValues',
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
     * Receive the output of every line into finished goods stock at the
     * production site. A line's unit cost is its consumed materials ÷ the
     * units it actually produced, so a short run carries its full cost.
     */
    public function complete(Request $request, ProductionOrder $productionOrder)
    {
        if (! $productionOrder->isOpen()) {
            return back()->with('error', 'This production order is already closed.');
        }

        $productionOrder->load('items.product');

        // A single-line order can still be completed with one produced_quantity.
        if ($request->has('produced_quantity') && $productionOrder->items->count() === 1) {
            $request->validate([
                'produced_quantity' => ['required', 'numeric', 'min:0.0001', 'max:'.(float) $productionOrder->items->first()->quantity],
            ]);
            $request->merge(['produced' => [$productionOrder->items->first()->id => $request->input('produced_quantity')]]);
        }

        $rules = ['produced' => ['required', 'array']];
        foreach ($productionOrder->items as $item) {
            $rules["produced.{$item->id}"] = ['required', 'numeric', 'min:0', 'max:'.(float) $item->quantity];
        }
        $validated = $request->validate($rules);

        $produced = $productionOrder->items->mapWithKeys(fn ($item) => [$item->id => (float) $validated['produced'][$item->id]]);

        if ($produced->sum() <= 0) {
            throw ValidationException::withMessages(['produced' => 'Enter what was produced for at least one line.']);
        }

        DB::transaction(function () use ($productionOrder, $produced) {
            foreach ($productionOrder->items as $item) {
                $quantity = $produced[$item->id];

                if ($quantity > 0) {
                    StockMovement::create([
                        'product_id' => $item->product_id,
                        'product_variant_id' => $item->product_variant_id,
                        'site_id' => $productionOrder->site_id,
                        'type' => 'production',
                        'quantity' => $quantity,
                        'unit_cost' => round((float) $item->material_cost / $quantity, 4),
                        'note' => "Output of {$productionOrder->production_no}",
                        'moved_at' => now()->toDateString(),
                        'created_by' => Auth::id(),
                        'reference_type' => ProductionOrder::class,
                        'reference_id' => $productionOrder->id,
                    ]);
                }

                $item->update(['produced_quantity' => $quantity]);
            }

            $productionOrder->update([
                'status' => 'completed',
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
