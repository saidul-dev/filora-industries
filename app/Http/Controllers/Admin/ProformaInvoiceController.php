<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\PicksProducts;
use App\Http\Controllers\Controller;
use App\Models\BomItem;
use App\Models\CompanySetting;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Party;
use App\Models\ProductionOrder;
use App\Models\ProformaInvoice;
use App\Models\ProformaInvoiceItem;
use App\Models\Purchase;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
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
 * Proforma Invoices — generated from one or more confirmed Sales Orders
 * of a customer, and the
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
            new Middleware('permission:sales.approve', only: ['create', 'store', 'issue', 'attachLc', 'markReady', 'close', 'cancel']),
        ];
    }

    public function index(Request $request)
    {
        $status = array_key_exists($request->get('status'), ProformaInvoice::STATUSES) ? $request->get('status') : null;
        $partyId = $request->filled('party_id') ? $request->integer('party_id') : null;
        $q = $request->get('q');

        $invoices = ProformaInvoice::with(['party', 'salesOrders', 'salesPerson'])
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
     * Pick a customer, tick one or more of their confirmed Sales Orders and
     * set (or override) each line's price — the PI is generated from all
     * the ticked orders together.
     */
    public function create(Request $request)
    {
        $user = Auth::user();
        $partyId = $request->filled('party_id') ? $request->integer('party_id') : null;

        // Confirmed orders not on a live PI — including ones already
        // (partly) delivered directly, whose challans the PI picks up.
        $confirmed = SalesOrder::whereIn('status', SalesOrder::OPEN_STATUSES)->visibleTo($user);

        $customers = Party::whereIn('id', (clone $confirmed)->select('party_id'))->orderBy('name')->get(['id', 'name']);

        $orders = $partyId
            ? (clone $confirmed)->where('party_id', $partyId)
                ->with(['items.product.stockUnit', 'items.productVariant.attributeValues'])
                ->orderBy('order_date')->orderBy('id')->get()
            : collect();

        $orderData = $orders->map(fn (SalesOrder $order) => [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'reference' => collect([$order->buyer_reference, $order->buyer_name])->filter()->implode(' / '),
            'order_date' => $order->order_date->format('d M, Y'),
            'delivery_date' => $order->delivery_date?->toDateString(),
            'discount' => (float) $order->discount_amount,
            'terms' => $order->delivery_terms,
            'lines' => $order->items->map(fn ($item) => $this->itemRow($item, [
                'line_id' => $item->id,
                'style' => $item->style,
                'color' => $item->color,
                'quantity' => (float) $item->quantity,
                'delivered' => (float) $item->delivered_quantity,
                'unit_price' => $item->unit_price !== null ? (float) $item->unit_price : '',
            ]))->values(),
        ])->values();

        return view('admin.proforma-invoices.create', [
            'customers' => $customers,
            'partyId' => $partyId,
            'orders' => $orderData,
            'selected' => collect(old('sales_order_ids', $request->input('orders', [])))->map(fn ($id) => (int) $id)->values(),
        ]);
    }

    /**
     * Generate one draft PI from one or more confirmed Sales Orders of the
     * same customer. Every order line is copied across in full — anything
     * already delivered straight against the order shows as delivered, and
     * its challans move onto the PI when it is issued — at the price given
     * here (falling back to the order's own price), with a planned
     * fulfillment path — from existing stock when enough is on hand across
     * all Sites, otherwise production for a Manufactured product and a
     * direct purchase for anything else.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'party_id' => ['required', 'integer'],
            'sales_order_ids' => ['required', 'array', 'min:1'],
            'sales_order_ids.*' => ['integer', 'distinct'],
            'prices' => ['nullable', 'array'],
            'prices.*' => ['nullable', 'numeric', 'min:0'],
            'pi_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:pi_date'],
            'delivery_date' => ['nullable', 'date'],
            'terms' => ['nullable', 'string', 'max:3000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'discount' => ['nullable', 'numeric', 'min:0'],
        ], [
            'sales_order_ids.required' => 'Tick at least one Sales Order.',
        ]);

        $orders = SalesOrder::whereIn('id', $validated['sales_order_ids'])
            ->where('party_id', $validated['party_id'])
            ->whereIn('status', SalesOrder::OPEN_STATUSES)
            ->visibleTo(Auth::user())
            ->with('items.product')
            ->orderBy('id')
            ->get();

        if ($orders->count() !== count($validated['sales_order_ids'])) {
            throw ValidationException::withMessages([
                'sales_order_ids' => 'Only confirmed Sales Orders of the selected customer can go on one Proforma Invoice.',
            ]);
        }

        // A price typed here wins; a blank one falls back to the order's.
        $prices = $validated['prices'] ?? [];
        $lines = [];
        $missing = [];

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $price = isset($prices[$item->id]) ? (float) $prices[$item->id] : $item->unit_price;

                if ($price === null) {
                    $missing["prices.{$item->id}"] = "Set a unit price for {$item->product->name} on {$order->order_no}.";

                    continue;
                }

                $lines[] = [$item, (float) $price];
            }
        }

        if ($missing) {
            throw ValidationException::withMessages($missing);
        }

        $subtotal = round(array_sum(array_map(fn ($line) => round((float) $line[0]->quantity * $line[1], 2), $lines)), 2);
        $discount = round((float) ($validated['discount'] ?? 0), 2);

        if ($discount > $subtotal) {
            throw ValidationException::withMessages(['discount' => 'Discount cannot exceed the subtotal.']);
        }

        $invoice = DB::transaction(function () use ($orders, $lines, $validated, $subtotal, $discount) {
            $invoice = ProformaInvoice::create([
                'pi_no' => 'PENDING',
                'party_id' => $validated['party_id'],
                'sales_person_id' => $orders->first()->sales_person_id,
                'pi_date' => $validated['pi_date'],
                'valid_until' => $validated['valid_until'] ?? null,
                'delivery_date' => $validated['delivery_date'] ?? null,
                'status' => 'draft',
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'total_amount' => $subtotal - $discount,
                'terms' => $validated['terms'] ?? null,
                'note' => $validated['note'] ?? null,
                'created_by' => Auth::id(),
            ]);
            $invoice->update(['pi_no' => 'PI-'.str_pad($invoice->id, 6, '0', STR_PAD_LEFT)]);
            $invoice->salesOrders()->attach($orders->pluck('id'));

            foreach ($lines as [$item, $price]) {
                $onHand = $this->stockBalance($item->product_id, $item->product_variant_id);

                $invoice->items()->create([
                    'sales_order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'style' => $item->style,
                    'color' => $item->color,
                    'length_per_unit' => $item->length_per_unit,
                    'remarks' => $item->remarks,
                    'quantity' => $item->quantity,
                    'unit_price' => $price,
                    'subtotal' => round((float) $item->quantity * $price, 2),
                    // The plan made on the order wins; otherwise stock if it
                    // covers what's left, else the product type's default.
                    'fulfillment' => $item->fulfillment ?? ($item->remaining() <= 0 || $onHand >= $item->remaining()
                        ? 'stock'
                        : ProformaInvoiceItem::defaultFulfillmentFor($item->product)),
                    'delivered_quantity' => $item->delivered_quantity,
                ]);
            }

            SalesOrder::whereIn('id', $orders->pluck('id'))->update(['status' => 'pi_generated']);

            return $invoice;
        });

        return redirect()->route('proforma-invoices.show', $invoice)
            ->with('success', "Proforma Invoice {$invoice->pi_no} generated from {$orders->pluck('order_no')->implode(', ')} as a draft — review the final items, then issue it.");
    }

    public function show(ProformaInvoice $proformaInvoice)
    {
        $this->authorizeVisible($proformaInvoice);

        $proformaInvoice->load([
            'party', 'salesOrders', 'salesPerson', 'creator',
            'items.product.stockUnit', 'items.product.bomItems.material.stockUnit', 'items.productVariant.attributeValues',
            'requisitions' => fn ($q) => $q->with('site')->withCount('items')->latest('id'),
            'purchases' => fn ($q) => $q->with(['party', 'site'])->latest('id'),
            'productionOrders' => fn ($q) => $q->with(['product.stockUnit', 'productVariant.attributeValues', 'site'])->latest('id'),
            'items.productionOrders',
            'deliveries' => fn ($q) => $q->with('site')->withCount('items')->latest('id'),
            'collections' => fn ($q) => $q->with('account')->latest('collection_date'),
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
            'sales_order_item' => $item->sales_order_item_id,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'fulfillment' => $item->fulfillment,
            'type' => $item->product->product_type,
            'style' => $item->style ?? '',
            'color' => $item->color ?? '',
            'length_per_unit' => $item->length_per_unit !== null ? (float) $item->length_per_unit : '',
            'remarks' => $item->remarks ?? '',
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
     * Each line keeps the order line it came from, and with it whatever was
     * already delivered straight against that order line.
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
            // Same product may repeat across style/color lines — no "distinct".
            'items.*.item' => ['required', 'string'],
            'items.*.sales_order_item' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.fulfillment' => ['required', Rule::in(array_keys(ProformaInvoiceItem::FULFILLMENTS))],
            'items.*.style' => ['nullable', 'string', 'max:100'],
            'items.*.color' => ['nullable', 'string', 'max:100'],
            'items.*.length_per_unit' => ['nullable', 'numeric', 'min:0'],
            'items.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $orderItems = SalesOrderItem::whereIn('sales_order_id', $proformaInvoice->salesOrders()->select('sales_orders.id'))
            ->get()->keyBy('id');

        $rows = [];
        foreach ($validated['items'] as $i => $row) {
            [$product, $variant] = $this->resolveItem($row['item']);

            if (! $product) {
                throw ValidationException::withMessages(["items.{$i}.item" => 'Pick a valid product or variant.']);
            }

            $orderItem = isset($row['sales_order_item']) ? $orderItems->get((int) $row['sales_order_item']) : null;

            if ($orderItem && (float) $row['quantity'] < (float) $orderItem->delivered_quantity) {
                throw ValidationException::withMessages(["items.{$i}.quantity" => "{$product->name}: {$orderItem->delivered_quantity} was already delivered on this order line — the PI can't ask for less."]);
            }

            if ($row['fulfillment'] === 'production' && ! $product->isManufactured()) {
                throw ValidationException::withMessages(["items.{$i}.fulfillment" => "{$product->name} is not a Manufactured product — it can't be fulfilled by factory production."]);
            }

            $rows[] = [
                'order_item' => $orderItem,
                'product' => $product,
                'variant' => $variant,
                'quantity' => (float) $row['quantity'],
                'unit_price' => (float) $row['unit_price'],
                'subtotal' => round((float) $row['quantity'] * (float) $row['unit_price'], 2),
                'fulfillment' => $row['fulfillment'],
                'style' => $row['style'] ?? null,
                'color' => $row['color'] ?? null,
                'length_per_unit' => isset($row['length_per_unit']) ? (float) $row['length_per_unit'] : null,
                'remarks' => $row['remarks'] ?? null,
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
                    'sales_order_item_id' => $row['order_item']?->id,
                    'delivered_quantity' => $row['order_item']->delivered_quantity ?? 0,
                    'product_id' => $row['product']->id,
                    'product_variant_id' => $row['variant']?->id,
                    'style' => $row['style'],
                    'color' => $row['color'],
                    'length_per_unit' => $row['length_per_unit'],
                    'remarks' => $row['remarks'],
                    'quantity' => $row['quantity'],
                    'unit_price' => $row['unit_price'],
                    'subtotal' => $row['subtotal'],
                    'fulfillment' => $row['fulfillment'],
                ]);
            }
        });

        return redirect()->route('proforma-invoices.show', $proformaInvoice)->with('success', "Proforma Invoice {$proformaInvoice->pi_no} updated.");
    }

    /**
     * Locks the PI. Challans, Production Orders and Purchase Orders already
     * raised straight from its Sales Orders move onto it — so its billed /
     * collected / due figures and its sourcing plan cover them — and its
     * status reflects what is already under way or delivered.
     */
    public function issue(ProformaInvoice $proformaInvoice)
    {
        if (! $proformaInvoice->isEditable()) {
            return back()->with('error', 'Only a draft Proforma Invoice can be issued.');
        }

        $proformaInvoice->load(['items', 'salesOrders.items']);
        $piLines = $proformaInvoice->items->whereNotNull('sales_order_item_id')->keyBy('sales_order_item_id');

        foreach ($proformaInvoice->salesOrders as $order) {
            foreach ($order->items as $orderItem) {
                if ((float) $orderItem->delivered_quantity > 0 && ! $piLines->has($orderItem->id)) {
                    return back()->with('error', "{$order->order_no} has a delivered line ({$orderItem->label()}) that is no longer on this PI — add it back before issuing.");
                }
            }
        }

        DB::transaction(function () use ($proformaInvoice, $piLines) {
            foreach ($piLines as $orderItemId => $line) {
                DeliveryItem::where('sales_order_item_id', $orderItemId)->whereNull('proforma_invoice_item_id')
                    ->update(['proforma_invoice_item_id' => $line->id]);
            }

            $orderIds = $proformaInvoice->salesOrders->pluck('id');

            Delivery::whereIn('sales_order_id', $orderIds)->whereNull('proforma_invoice_id')
                ->update(['proforma_invoice_id' => $proformaInvoice->id]);

            $adoptedProduction = 0;
            foreach ($piLines as $orderItemId => $line) {
                $adoptedProduction += ProductionOrder::where('sales_order_item_id', $orderItemId)->whereNull('proforma_invoice_id')
                    ->update(['proforma_invoice_id' => $proformaInvoice->id, 'proforma_invoice_item_id' => $line->id]);
            }

            $adoptedPurchases = Purchase::whereIn('sales_order_id', $orderIds)->whereNull('proforma_invoice_id')
                ->update(['proforma_invoice_id' => $proformaInvoice->id]);

            $proformaInvoice->update(['status' => 'issued', 'issued_at' => now()]);

            if ($adoptedProduction || $adoptedPurchases) {
                $proformaInvoice->markInProgress();
            }

            if ($proformaInvoice->items->contains(fn ($item) => (float) $item->delivered_quantity > 0)) {
                $proformaInvoice->recalculateDeliveryStatus();
            }
        });

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
     * sourced, produced or delivered the PI has to run its course (that
     * includes challans it picked up from its orders on issue). The Sales
     * Orders go back to where their own deliveries leave them, so a
     * corrected PI can be generated.
     */
    public function cancel(ProformaInvoice $proformaInvoice)
    {
        if (! in_array($proformaInvoice->status, ['draft', 'issued', 'lc_issued'], true) || $proformaInvoice->deliveries()->exists()) {
            return back()->with('error', 'This Proforma Invoice already has procurement, production or delivery against it and cannot be cancelled.');
        }

        DB::transaction(function () use ($proformaInvoice) {
            $proformaInvoice->update(['status' => 'cancelled']);

            $proformaInvoice->salesOrders->each(fn (SalesOrder $order) => $order->recalculateStatus());
        });

        return back()->with('success', "{$proformaInvoice->pi_no} cancelled.");
    }

    public function print(ProformaInvoice $proformaInvoice)
    {
        $this->authorizeVisible($proformaInvoice);

        $proformaInvoice->load(['party', 'salesOrders', 'salesPerson', 'items.product.stockUnit', 'items.productVariant.attributeValues']);
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
