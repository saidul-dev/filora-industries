<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProformaInvoice;
use App\Models\SalesOrder;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

/**
 * Deliveries straight against a confirmed Sales Order, and a PI raised
 * afterwards picking those challans up.
 */
class DirectDeliveryTest extends TestCase
{
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    private User $admin;

    private Site $factory;

    private Party $customer;

    private Product $thread;

    private Product $label;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
        $this->admin = $this->admin();
        $this->factory = $this->makeSite('Factory', 'FAC-01');
        $this->customer = $this->makeCustomer();
        $this->thread = $this->makeProduct('manufactured');
        $this->label = $this->makeProduct('direct_purchase');

        $this->stockIn($this->thread, $this->factory, 1000, 0.1, $this->admin);
        $this->stockIn($this->label, $this->factory, 1000, 0.5, $this->admin);
    }

    /** 100 thread at USD 0.20, 50 labels with no price yet. */
    private function confirmedOrder(): SalesOrder
    {
        $this->actingAs($this->admin)->post(route('sales-orders.store'), [
            'party_id' => $this->customer->id,
            'order_date' => '2026-10-01',
            'items' => [
                ['item' => "product-{$this->thread->id}", 'quantity' => 100, 'unit_price' => 0.2],
                ['item' => "product-{$this->label->id}", 'quantity' => 50],
            ],
        ])->assertSessionHasNoErrors();

        $order = SalesOrder::latest('id')->first();
        $this->actingAs($this->admin)->post(route('sales-orders.confirm', $order));

        return $order->refresh()->load('items');
    }

    private function deliverOrder(SalesOrder $order, array $items, float $usdRate = 100): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('deliveries.store'), [
            'sales_order_id' => $order->id,
            'site_id' => $this->factory->id,
            'delivery_date' => '2026-10-05',
            'usd_rate' => $usdRate,
            'items' => $items,
        ]);
    }

    public function test_goods_can_be_delivered_straight_against_a_confirmed_order(): void
    {
        $order = $this->confirmedOrder();
        [$threadLine, $labelLine] = [$order->items[0], $order->items[1]];

        $this->actingAs($this->admin)
            ->get(route('deliveries.create', ['source' => 'order', 'sales_order_id' => $order->id]))
            ->assertOk()->assertSee($this->thread->name)->assertSee($this->label->name);

        // The unpriced label line needs a price on the challan.
        $this->deliverOrder($order, [$labelLine->id => ['quantity' => 10]])
            ->assertSessionHasErrors("items.{$labelLine->id}.unit_price");

        $this->deliverOrder($order, [
            $threadLine->id => ['quantity' => 60, 'unit_price' => 0.2],
            $labelLine->id => ['quantity' => 50, 'unit_price' => 0.1],
        ])->assertSessionHasNoErrors();

        $delivery = Delivery::sole();
        $this->assertNull($delivery->proforma_invoice_id);
        $this->assertSame($order->id, $delivery->sales_order_id);
        // USD 60 × 0.20 + 50 × 0.10 = 17 → BDT 1,700 at 100.
        $this->assertEquals(17, (float) $delivery->sale_amount_usd);
        $this->assertEquals(1700, $this->customer->receivableBalance());
        $this->assertEquals(940, $this->balance($this->thread, $this->factory));

        $order->refresh()->load('items');
        $this->assertSame('partially_delivered', $order->status);
        $this->assertEquals(60, (float) $order->items[0]->delivered_quantity);
        // The order keeps its own (missing) price.
        $this->assertNull($order->items[1]->unit_price);

        $this->deliverOrder($order, [$threadLine->id => ['quantity' => 40, 'unit_price' => 0.2]])->assertSessionHasNoErrors();
        $this->assertSame('delivered', $order->refresh()->status);

        // Nothing left to deliver.
        $this->deliverOrder($order, [$threadLine->id => ['quantity' => 1, 'unit_price' => 0.2]])->assertSessionHasErrors();
        $this->assertSame(2, Delivery::count());

        $this->actingAs($this->admin)->get(route('deliveries.show', $delivery))->assertOk()->assertSee($order->order_no);
        $this->actingAs($this->admin)->get(route('deliveries.print', $delivery))->assertOk()->assertSee($order->order_no);
        $this->actingAs($this->admin)->get(route('deliveries.index'))->assertOk()->assertSee($order->order_no);
        $this->actingAs($this->admin)->get(route('sales-orders.show', $order))->assertOk()->assertSee($delivery->delivery_no);
    }

    public function test_order_discount_is_spread_over_directly_delivered_revenue(): void
    {
        $order = $this->confirmedOrder();
        // USD 20 priced subtotal, 10% off.
        $order->update(['discount_amount' => 2, 'total_amount' => 18]);

        $this->deliverOrder($order, [$order->items[0]->id => ['quantity' => 100, 'unit_price' => 0.2]], usdRate: 1);

        $this->assertEquals(18, (float) Delivery::sole()->sale_amount_usd);
    }

    public function test_a_pi_raised_after_direct_deliveries_picks_the_challans_up(): void
    {
        $order = $this->confirmedOrder();
        [$threadLine, $labelLine] = [$order->items[0], $order->items[1]];
        $this->deliverOrder($order, [$threadLine->id => ['quantity' => 60, 'unit_price' => 0.2]])->assertSessionHasNoErrors();
        $first = Delivery::sole();

        // The partly delivered order can still go on a PI, in full.
        $this->actingAs($this->admin)->get(route('proforma-invoices.create', ['party_id' => $this->customer->id]))
            ->assertOk()->assertSee($order->order_no);

        $this->actingAs($this->admin)->post(route('proforma-invoices.store'), $this->piPayload($order->refresh(), [
            'prices' => [$labelLine->id => 0.1],
        ]))->assertSessionHasNoErrors();

        $invoice = ProformaInvoice::with('items')->sole();
        $piThread = $invoice->items->firstWhere('sales_order_item_id', $threadLine->id);
        $this->assertEquals(100, (float) $piThread->quantity);
        $this->assertEquals(60, (float) $piThread->delivered_quantity);
        $this->assertSame('pi_generated', $order->refresh()->status);

        // While on the PI the order can't be delivered against directly.
        $this->deliverOrder($order, [$threadLine->id => ['quantity' => 1, 'unit_price' => 0.2]])->assertSessionHasErrors('sales_order_id');

        // A draft edit can't ask for less than went out already.
        $this->actingAs($this->admin)->put(route('proforma-invoices.update', $invoice), [
            'pi_date' => '2026-10-06',
            'items' => [
                ['item' => "product-{$this->thread->id}", 'sales_order_item' => $threadLine->id, 'quantity' => 50, 'unit_price' => 0.2, 'fulfillment' => 'stock'],
                ['item' => "product-{$this->label->id}", 'sales_order_item' => $labelLine->id, 'quantity' => 50, 'unit_price' => 0.1, 'fulfillment' => 'stock'],
            ],
        ])->assertSessionHasErrors('items.0.quantity');

        $this->actingAs($this->admin)->post(route('proforma-invoices.issue', $invoice))->assertSessionHasNoErrors();

        $invoice->refresh()->load('items');
        $this->assertSame('partially_delivered', $invoice->status);
        $this->assertSame($invoice->id, $first->refresh()->proforma_invoice_id);
        $this->assertSame($piThread->id, $first->items()->first()->proforma_invoice_item_id);
        // The earlier challan now counts as billed on the PI.
        $this->assertEquals(1200, $invoice->billedAmount());

        // The rest goes out against the PI, and the order line keeps up.
        $piThread = $invoice->items->firstWhere('sales_order_item_id', $threadLine->id);
        $piLabel = $invoice->items->firstWhere('sales_order_item_id', $labelLine->id);
        $this->actingAs($this->admin)->post(route('deliveries.store'), [
            'proforma_invoice_id' => $invoice->id,
            'site_id' => $this->factory->id,
            'delivery_date' => '2026-10-07',
            'usd_rate' => 100,
            'items' => [$piThread->id => ['quantity' => 40], $piLabel->id => ['quantity' => 50]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('delivered', $invoice->refresh()->status);
        $this->assertEquals(100, (float) $threadLine->refresh()->delivered_quantity);
        $this->assertEquals(50, (float) $labelLine->refresh()->delivered_quantity);

        // A PI carrying challans can't be cancelled.
        $this->actingAs($this->admin)->post(route('proforma-invoices.cancel', $invoice));
        $this->assertSame('delivered', $invoice->refresh()->status);
    }

    public function test_cancelling_a_draft_pi_puts_a_partly_delivered_order_back(): void
    {
        $order = $this->confirmedOrder();
        $this->deliverOrder($order, [$order->items[0]->id => ['quantity' => 10, 'unit_price' => 0.2]]);
        $this->actingAs($this->admin)->post(route('proforma-invoices.store'), $this->piPayload($order->refresh(), [
            'prices' => [$order->items[1]->id => 0.1],
        ]))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('proforma-invoices.cancel', ProformaInvoice::sole()));

        $this->assertSame('partially_delivered', $order->refresh()->status);
        $this->assertNull(Delivery::sole()->proforma_invoice_id);
    }
}
