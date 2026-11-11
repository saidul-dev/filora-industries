<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProformaInvoice;
use App\Models\Purchase;
use App\Models\SalesOrder;
use App\Models\Site;
use App\Models\User;
use App\Services\PiFulfillmentPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

/**
 * Planning a confirmed Sales Order line by line and raising Production
 * Orders and Purchase Orders straight from it, before any PI — and a PI
 * issued later taking all of that over.
 */
class SalesOrderSourcingTest extends TestCase
{
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    private User $admin;

    private Site $factory;

    private Party $supplier;

    private Product $zipper;

    private Product $tape;

    private Product $label;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
        $this->admin = $this->admin();
        $this->factory = $this->makeSite('Factory', 'FAC-01');
        $this->supplier = $this->makeSupplier();

        $this->zipper = $this->makeProduct('manufactured');
        $this->tape = $this->makeProduct('raw_material');
        $this->label = $this->makeProduct('direct_purchase');
        $this->zipper->bomItems()->create(['material_id' => $this->tape->id, 'quantity' => 0.5]);
        $this->stockIn($this->tape, $this->factory, 1000, 2, $this->admin);
    }

    /** 1000 zippers to make; 300 + 200 labels (two colors) to buy. */
    private function confirmedOrder(): SalesOrder
    {
        $this->actingAs($this->admin)->post(route('sales-orders.store'), [
            'party_id' => $this->makeCustomer()->id,
            'order_date' => '2026-11-01',
            'items' => [
                ['item' => "product-{$this->zipper->id}", 'quantity' => 1000, 'unit_price' => 1],
                ['item' => "product-{$this->label->id}", 'quantity' => 300, 'unit_price' => 0.1, 'color' => 'Red'],
                ['item' => "product-{$this->label->id}", 'quantity' => 200, 'unit_price' => 0.1, 'color' => 'Blue'],
            ],
        ])->assertSessionHasNoErrors();

        $order = SalesOrder::latest('id')->first();
        $this->actingAs($this->admin)->post(route('sales-orders.confirm', $order));

        return $order->refresh()->load('items');
    }

    private function produce(SalesOrder $order, float $quantity): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('production-orders.store'), [
            'sales_order_item_id' => $order->items[0]->id,
            'site_id' => $this->factory->id,
            'quantity' => $quantity,
        ]);
    }

    private function buy(SalesOrder $order, float $quantity): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('purchases.store'), [
            'sales_order_id' => $order->id,
            'vendor_type' => 'supplier',
            'party_id' => $this->supplier->id,
            'site_id' => $this->factory->id,
            'order_date' => '2026-11-02',
            'items' => [['item' => "product-{$this->label->id}", 'quantity' => $quantity, 'unit_cost' => 0.05]],
        ]);
    }

    public function test_order_lines_default_to_the_product_type_and_can_be_replanned(): void
    {
        $order = $this->confirmedOrder();
        $this->assertSame(['production', 'purchase', 'purchase'], $order->items->map->plannedFulfillment()->all());

        $this->actingAs($this->admin)->post(route('sales-orders.plan', $order), [
            'fulfillment' => [$order->items[0]->id => 'stock', $order->items[1]->id => 'stock'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['stock', 'stock', 'purchase'], $order->items()->orderBy('id')->get()->map->plannedFulfillment()->all());

        // A bought-in product can't be planned for production.
        $this->actingAs($this->admin)->post(route('sales-orders.plan', $order), [
            'fulfillment' => [$order->items[1]->id => 'production'],
        ])->assertSessionHasErrors("fulfillment.{$order->items[1]->id}");

        $this->actingAs($this->admin)->get(route('sales-orders.show', $order))->assertOk()->assertSee('Fulfillment Plan');
    }

    public function test_production_order_is_raised_straight_from_an_order_line(): void
    {
        $order = $this->confirmedOrder();

        // The picker lists Sales Orders alongside PIs; picking one lists its
        // lines planned for production.
        $this->actingAs($this->admin)->get(route('production-orders.create'))
            ->assertOk()->assertSee("so-{$order->id}")->assertSee($order->order_no);
        $this->actingAs($this->admin)->get(route('production-orders.create', ['source' => "so-{$order->id}"]))
            ->assertOk()->assertSee($this->zipper->name)->assertSee($this->tape->name);
        $this->actingAs($this->admin)->get(route('production-orders.create', ['sales_order_item_id' => $order->items[0]->id]))
            ->assertOk()->assertSee($order->order_no)->assertSee($this->tape->name);

        $this->produce($order, 600)->assertSessionHasNoErrors();

        $production = ProductionOrder::sole();
        $this->assertNull($production->proforma_invoice_id);
        $this->assertSame($order->id, $production->sales_order_id);
        $this->assertSame($order->items[0]->id, $production->items()->sole()->sales_order_item_id);
        $this->assertEquals(700, $this->balance($this->tape, $this->factory)); // 600 × 0.5 consumed
        $this->assertEquals(400, $order->items[0]->refresh()->load('productionItems.productionOrder')->quantityToProduce());

        // One live Production Order per line: a second one is refused, and
        // the order (no other line to produce) drops out of the picker.
        $this->produce($order, 400)->assertSessionHasErrors('sales_order_item_id');
        $this->assertSame(1, ProductionOrder::count());
        $this->actingAs($this->admin)->get(route('production-orders.create'))
            ->assertOk()->assertDontSee("so-{$order->id}");
        $this->actingAs($this->admin)->get(route('production-orders.create', ['source' => "so-{$order->id}"]))
            ->assertOk()->assertSee($production->production_no);

        $this->actingAs($this->admin)->get(route('production-orders.show', $production))->assertOk()->assertSee($order->order_no)
            ->assertSee(route('production-orders.print', $production), false);
        $this->actingAs($this->admin)->get(route('production-orders.print', $production))->assertOk()
            ->assertSee($production->production_no)->assertSee($this->tape->name)->assertSee($order->party->name);
        // The order links to its Production Order and its print.
        $this->actingAs($this->admin)->get(route('sales-orders.show', $order))->assertOk()
            ->assertSee(route('production-orders.show', $production), false)
            ->assertSee(route('production-orders.print', $production), false);
        $this->actingAs($this->admin)->get(route('production-orders.index'))->assertOk()->assertSee($order->order_no);
    }

    public function test_several_order_lines_go_into_one_production_order(): void
    {
        $this->actingAs($this->admin)->post(route('sales-orders.store'), [
            'party_id' => $this->makeCustomer()->id,
            'order_date' => '2026-11-01',
            'items' => [
                ['item' => "product-{$this->zipper->id}", 'quantity' => 400, 'color' => 'Red'],
                ['item' => "product-{$this->zipper->id}", 'quantity' => 300, 'color' => 'Blue'],
                ['item' => "product-{$this->label->id}", 'quantity' => 50],
            ],
        ])->assertSessionHasNoErrors();
        $order = SalesOrder::latest('id')->first();
        $this->actingAs($this->admin)->post(route('sales-orders.confirm', $order));
        $order->refresh()->load('items');
        [$red, $blue] = [$order->items[0], $order->items[1]];

        $this->assertSame(2, $order->linesToProduce()->count());
        $this->actingAs($this->admin)->get(route('sales-orders.show', $order))->assertOk()
            ->assertSee(route('production-orders.create', ['source' => "so-{$order->id}"]), false);

        // Asking for more than the summed BOM need on hand is refused as a whole.
        $this->actingAs($this->admin)->post(route('production-orders.store'), [
            'source' => "so-{$order->id}",
            'site_id' => $this->factory->id,
            'items' => [$red->id => 1400, $blue->id => 300],
        ])->assertSessionHasErrors("items.{$red->id}");

        $this->actingAs($this->admin)->post(route('production-orders.store'), [
            'source' => "so-{$order->id}",
            'site_id' => $this->factory->id,
            'items' => [$red->id => 400, $blue->id => 300],
        ])->assertSessionHasNoErrors();

        $production = ProductionOrder::with('items')->sole();
        $this->assertSame($order->id, $production->sales_order_id);
        $this->assertEqualsCanonicalizing([$red->id, $blue->id], $production->items->pluck('sales_order_item_id')->all());
        $this->assertEquals(700, $production->totalQuantity());
        $this->assertEquals(650, $this->balance($this->tape, $this->factory)); // 700 × 0.5 consumed
        $this->assertEquals(700, (float) $production->material_cost);          // 350 tape × 2
        $this->assertSame(0, $order->refresh()->linesToProduce()->count());

        // Neither line can go on a second order.
        $this->actingAs($this->admin)->post(route('production-orders.store'), [
            'source' => "so-{$order->id}",
            'site_id' => $this->factory->id,
            'items' => [$blue->id => 1],
        ])->assertSessionHasErrors("items.{$blue->id}");

        $this->actingAs($this->admin)->get(route('production-orders.show', $production))->assertOk()
            ->assertSee('Red')->assertSee('Blue');
        $this->actingAs($this->admin)->get(route('production-orders.index'))->assertOk()->assertSee('1 more line', false);

        // Completed per line: each line's output carries its own material cost.
        $items = $production->items->keyBy('sales_order_item_id');
        $this->actingAs($this->admin)->post(route('production-orders.complete', $production), [
            'produced' => [$items[$red->id]->id => 400, $items[$blue->id]->id => 250],
        ])->assertSessionHasNoErrors();
        $this->assertSame('completed', $production->refresh()->status);
        $this->assertEquals(650, $this->balance($this->zipper, $this->factory));
        $this->assertEquals(250, (float) $items[$blue->id]->refresh()->produced_quantity);
    }

    public function test_purchase_order_is_raised_straight_from_an_order_without_a_requisition(): void
    {
        $order = $this->confirmedOrder();

        // Each color of the label is its own PO line: Red 300, Blue 200.
        $this->actingAs($this->admin)->get(route('purchases.create', ['from_sales_order' => $order->id]))
            ->assertOk()->assertSee($this->label->name)->assertSee('Red')->assertSee('Blue');

        // An older-style PO line with no color still covers both.

        $this->buy($order, 500)->assertSessionHasNoErrors();

        $purchase = Purchase::sole();
        $this->assertNull($purchase->purchase_requisition_id);
        $this->assertNull($purchase->proforma_invoice_id);
        $this->assertSame($order->id, $purchase->sales_order_id);
        $this->assertSame(0.0, $order->refresh()->purchaseNeeds()->sum('to_buy'));

        $this->actingAs($this->admin)->get(route('purchases.show', $purchase))->assertOk()->assertSee($order->order_no);
        $this->actingAs($this->admin)->get(route('purchases.index'))->assertOk()->assertSee($order->order_no);
        $this->actingAs($this->admin)->get(route('purchases.print', $purchase))->assertOk();
        $this->actingAs($this->admin)->get(route('purchases.edit', $purchase))->assertOk();
        $this->actingAs($this->admin)->get(route('sales-orders.show', $order))->assertOk()->assertSee($purchase->purchase_no)
            ->assertSee(route('purchases.print', $purchase), false);

        // Goods receipt works as for any PO.
        $line = $purchase->items()->first();
        $this->actingAs($this->admin)->post(route('purchases.receive.store', $purchase), [
            'received_date' => '2026-11-03',
            'site_id' => $this->factory->id,
            'items' => [$line->id => ['quantity' => 500]],
        ])->assertSessionHasNoErrors();
        $this->assertEquals(500, $this->balance($this->label, $this->factory));
    }

    public function test_a_line_can_be_split_to_produce_part_and_buy_the_rest(): void
    {
        $order = $this->confirmedOrder();
        $zipperLine = $order->items[0]; // 1000 zippers @ 1.00, planned for production
        $total = (float) $order->total_amount;

        $this->actingAs($this->admin)->get(route('sales-orders.show', $order))->assertOk()
            ->assertSee('Split line');

        $this->actingAs($this->admin)->post(route('sales-orders.split', [$order, $zipperLine]), [
            'split_item_id' => $zipperLine->id,
            'split_quantity' => 400,
            'split_fulfillment' => 'purchase',
        ])->assertSessionHasNoErrors();

        $lines = $order->items()->where('product_id', $this->zipper->id)->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertEquals([600, 400], $lines->map(fn ($l) => (float) $l->quantity)->all());
        $this->assertSame(['production', 'purchase'], $lines->map->plannedFulfillment()->all());
        $this->assertEquals([600, 400], $lines->map(fn ($l) => (float) $l->subtotal)->all());
        $this->assertEquals($total, (float) $order->refresh()->total_amount);

        // 600 produced, 400 bought.
        $this->produce($order, 600)->assertSessionHasNoErrors();
        $this->assertEquals(400, $order->refresh()->purchaseNeeds()->firstWhere('product.id', $this->zipper->id)->to_buy);

        // A line already in production can't be split; nor can the whole line be split off.
        $this->actingAs($this->admin)->post(route('sales-orders.split', [$order, $lines[0]]), [
            'split_quantity' => 100, 'split_fulfillment' => 'stock',
        ])->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('sales-orders.split', [$order, $lines[1]]), [
            'split_quantity' => 400, 'split_fulfillment' => 'stock',
        ])->assertSessionHasErrorsIn('split', 'split_quantity');
        // A bought-in label can't have a part planned for production.
        $this->actingAs($this->admin)->post(route('sales-orders.split', [$order, $order->items()->where('product_id', $this->label->id)->first()]), [
            'split_quantity' => 10, 'split_fulfillment' => 'production',
        ])->assertSessionHasErrorsIn('split', 'split_fulfillment');

        $this->actingAs($this->userWithRole('HR'))->post(route('sales-orders.split', [$order, $lines[1]]), [
            'split_quantity' => 1, 'split_fulfillment' => 'stock',
        ])->assertForbidden();
    }

    public function test_po_lines_keep_the_order_lines_style_and_color(): void
    {
        $order = $this->confirmedOrder();

        $this->actingAs($this->admin)->post(route('purchases.store'), [
            'sales_order_id' => $order->id,
            'vendor_type' => 'supplier',
            'party_id' => $this->supplier->id,
            'site_id' => $this->factory->id,
            'order_date' => '2026-11-02',
            'items' => [
                ['item' => "product-{$this->label->id}", 'color' => 'Red', 'quantity' => 300, 'unit_cost' => 0.05],
                ['item' => "product-{$this->label->id}", 'color' => 'Blue', 'quantity' => 150, 'unit_cost' => 0.05],
            ],
        ])->assertSessionHasNoErrors();

        $purchase = Purchase::with('items')->sole();
        $this->assertSame(['Red', 'Blue'], $purchase->items->sortBy('id')->pluck('color')->values()->all());

        // Red is covered; 50 Blue still to buy.
        $needs = $order->refresh()->purchaseNeeds()->keyBy('color');
        $this->assertEquals(0, $needs['Red']->to_buy);
        $this->assertEquals(50, $needs['Blue']->to_buy);

        // The same product and color twice on one PO is refused.
        $this->actingAs($this->admin)->post(route('purchases.store'), [
            'sales_order_id' => $order->id,
            'vendor_type' => 'supplier',
            'party_id' => $this->supplier->id,
            'site_id' => $this->factory->id,
            'order_date' => '2026-11-02',
            'items' => [
                ['item' => "product-{$this->label->id}", 'color' => 'Blue', 'quantity' => 25, 'unit_cost' => 0.05],
                ['item' => "product-{$this->label->id}", 'color' => 'blue ', 'quantity' => 25, 'unit_cost' => 0.05],
            ],
        ])->assertSessionHasErrors('items.1.item');

        $this->actingAs($this->admin)->get(route('purchases.show', $purchase))->assertOk()->assertSee('Red')->assertSee('Blue');
        $this->actingAs($this->admin)->get(route('purchases.print', $purchase))->assertOk()->assertSee('Blue');
        $this->actingAs($this->admin)->get(route('purchases.edit', $purchase))->assertOk()->assertSee('Red');
    }

    public function test_a_pi_issued_later_takes_over_the_plan_production_and_purchases(): void
    {
        $order = $this->confirmedOrder();
        $this->actingAs($this->admin)->post(route('sales-orders.plan', $order), [
            'fulfillment' => [$order->items[2]->id => 'stock'],
        ]);
        $this->produce($order, 1000)->assertSessionHasNoErrors();
        $this->buy($order, 300)->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('proforma-invoices.store'), $this->piPayload($order->refresh()))->assertSessionHasNoErrors();
        $invoice = ProformaInvoice::with('items')->sole();
        // The order's plan is copied onto the PI.
        $this->assertSame(['production', 'purchase', 'stock'], $invoice->items->sortBy('id')->pluck('fulfillment')->values()->all());

        // Once on a PI, the order is sourced through the PI.
        $this->buy($order, 1)->assertSessionHasErrors('sales_order_id');

        $this->actingAs($this->admin)->post(route('proforma-invoices.issue', $invoice))->assertSessionHasNoErrors();

        $invoice->refresh()->load('items.productionItems.productionOrder');
        $this->assertSame('in_progress', $invoice->status);
        $production = ProductionOrder::sole();
        $this->assertSame($invoice->id, $production->proforma_invoice_id);
        $this->assertSame($invoice->items->firstWhere('sales_order_item_id', $order->items[0]->id)->id, $production->items()->sole()->proforma_invoice_item_id);
        $this->assertSame($invoice->id, Purchase::sole()->proforma_invoice_id);

        // Nothing left to produce, and the 300 labels already bought aren't suggested again.
        $this->assertEquals(0, $invoice->items->firstWhere('fulfillment', 'production')->quantityToProduce());
        $suggestions = app(PiFulfillmentPlanner::class)->purchaseSuggestions($invoice)
            ->mapWithKeys(fn ($row) => [$row->product->id => $row->quantity]);
        $this->assertArrayNotHasKey($this->label->id, $suggestions->all());
        $this->assertArrayNotHasKey($this->tape->id, $suggestions->all());

        $this->actingAs($this->admin)->get(route('proforma-invoices.show', $invoice))->assertOk()
            ->assertSee($production->production_no)->assertSee(Purchase::sole()->purchase_no);
    }
}
