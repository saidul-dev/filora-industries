<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\Product;
use App\Models\ProformaInvoice;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

class MultiOrderProformaInvoiceTest extends TestCase
{
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    private User $admin;

    private Party $customer;

    private Product $thread;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
        $this->admin = $this->admin();
        $this->customer = $this->makeCustomer();
        $this->thread = $this->makeProduct('manufactured');
    }

    /**
     * @param  array<int, array{quantity: float, unit_price?: float|null}>  $lines
     */
    private function confirmedOrder(array $lines, array $header = [], ?Party $customer = null): SalesOrder
    {
        $this->actingAs($this->admin)->post(route('sales-orders.store'), array_merge([
            'party_id' => ($customer ?? $this->customer)->id,
            'order_date' => '2026-10-01',
            'items' => array_map(fn ($line) => [
                'item' => "product-{$this->thread->id}",
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'] ?? null,
            ], $lines),
        ], $header))->assertSessionHasNoErrors();

        $order = SalesOrder::latest('id')->first();
        $this->actingAs($this->admin)->post(route('sales-orders.confirm', $order));

        return $order->refresh()->load('items');
    }

    public function test_sales_order_can_be_saved_without_prices(): void
    {
        $order = $this->confirmedOrder([['quantity' => 100], ['quantity' => 50, 'unit_price' => 0.25]]);

        $this->assertNull($order->items[0]->unit_price);
        $this->assertSame('0.2500', $order->items[1]->unit_price);
        $this->assertEquals(12.5, (float) $order->total_amount);
        $this->assertTrue($order->hasUnpricedLines());

        $this->actingAs($this->admin)->get(route('sales-orders.show', $order))->assertOk()->assertSee('No price yet');
    }

    public function test_one_pi_is_generated_from_several_orders_with_prices_set_on_the_pi(): void
    {
        $first = $this->confirmedOrder([['quantity' => 100], ['quantity' => 40, 'unit_price' => 0.2]], ['delivery_date' => '2026-11-10', 'discount' => 1]);
        $second = $this->confirmedOrder([['quantity' => 60, 'unit_price' => 0.3]], ['delivery_date' => '2026-11-20', 'buyer_reference' => 'PO-77']);

        $this->actingAs($this->admin)->get(route('proforma-invoices.create', ['party_id' => $this->customer->id, 'orders' => [$first->id]]))
            ->assertOk()->assertSee($first->order_no)->assertSee($second->order_no);

        $this->actingAs($this->admin)->post(route('proforma-invoices.store'), $this->piPayload([$first, $second], [
            'prices' => [
                $first->items[0]->id => 0.25,   // was blank on the order
                $second->items[0]->id => 0.35,  // overrides the order's 0.30
            ],
            'discount' => 2,
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $invoice = ProformaInvoice::with(['items', 'salesOrders'])->sole();
        $this->assertSame([$first->id, $second->id], $invoice->salesOrders->pluck('id')->all());
        $this->assertSame(['0.2500', '0.2000', '0.3500'], $invoice->items->sortBy('id')->pluck('unit_price')->values()->all());
        // 100 × 0.25 + 40 × 0.20 + 60 × 0.35 = 54, less 2 discount.
        $this->assertEquals(54, (float) $invoice->subtotal_amount);
        $this->assertEquals(52, (float) $invoice->total_amount);
        $this->assertSame('2026-11-20', $invoice->delivery_date->toDateString());

        $this->assertSame('pi_generated', $first->refresh()->status);
        $this->assertSame('pi_generated', $second->refresh()->status);
        // The orders keep the prices they were entered with.
        $this->assertNull($first->items()->orderBy('id')->first()->unit_price);
        $this->assertSame('0.3000', $second->items()->first()->unit_price);

        $this->actingAs($this->admin)->get(route('proforma-invoices.show', $invoice))->assertOk()
            ->assertSee($first->order_no)->assertSee($second->order_no);
        $this->actingAs($this->admin)->get(route('proforma-invoices.print', $invoice))->assertOk()->assertSee('PO-77');
        $this->actingAs($this->admin)->get(route('proforma-invoices.index'))->assertOk()
            ->assertSee("{$first->order_no}, {$second->order_no}");
        $this->actingAs($this->admin)->get(route('sales-orders.show', $first))->assertOk()->assertSee($invoice->pi_no);
    }

    public function test_every_line_needs_a_price_before_the_pi_is_created(): void
    {
        $order = $this->confirmedOrder([['quantity' => 100], ['quantity' => 40, 'unit_price' => 0.2]]);

        $this->actingAs($this->admin)->post(route('proforma-invoices.store'), $this->piPayload($order))
            ->assertSessionHasErrors("prices.{$order->items[0]->id}");

        $this->assertSame(0, ProformaInvoice::count());
        $this->assertSame('confirmed', $order->refresh()->status);
    }

    public function test_orders_of_different_customers_cannot_share_a_pi(): void
    {
        $mine = $this->confirmedOrder([['quantity' => 10, 'unit_price' => 1]]);
        $theirs = $this->confirmedOrder([['quantity' => 10, 'unit_price' => 1]], [], $this->makeCustomer('Other Buyer'));

        $this->actingAs($this->admin)->post(route('proforma-invoices.store'), $this->piPayload([$mine, $theirs]))
            ->assertSessionHasErrors('sales_order_ids');

        $this->assertSame(0, ProformaInvoice::count());
    }

    public function test_cancelling_a_multi_order_pi_returns_every_order_to_confirmed(): void
    {
        $first = $this->confirmedOrder([['quantity' => 10, 'unit_price' => 1]]);
        $second = $this->confirmedOrder([['quantity' => 20, 'unit_price' => 1]]);
        $this->actingAs($this->admin)->post(route('proforma-invoices.store'), $this->piPayload([$first, $second]));

        $this->actingAs($this->admin)->post(route('proforma-invoices.cancel', ProformaInvoice::sole()));

        $this->assertSame('confirmed', $first->refresh()->status);
        $this->assertSame('confirmed', $second->refresh()->status);
    }

    public function test_pi_prices_can_still_be_updated_while_draft(): void
    {
        $order = $this->confirmedOrder([['quantity' => 100, 'unit_price' => 0.2]]);
        $this->actingAs($this->admin)->post(route('proforma-invoices.store'), $this->piPayload($order));
        $invoice = ProformaInvoice::sole();

        $this->actingAs($this->admin)->put(route('proforma-invoices.update', $invoice), [
            'pi_date' => '2026-10-05',
            'items' => [['item' => "product-{$this->thread->id}", 'quantity' => 100, 'unit_price' => 0.22, 'fulfillment' => 'production']],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(22, (float) $invoice->refresh()->total_amount);
    }
}
