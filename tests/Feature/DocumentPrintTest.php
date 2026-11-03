<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\ProformaInvoice;
use App\Models\Purchase;
use App\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

/**
 * Every sales and purchasing document can be printed from its own page and
 * straight from its list.
 */
class DocumentPrintTest extends TestCase
{
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    public function test_sales_order_pi_delivery_and_purchase_print_from_their_pages_and_lists(): void
    {
        $this->seedBasics();
        $admin = $this->admin();
        $factory = $this->makeSite('Factory', 'FAC-01');
        $label = $this->makeProduct('direct_purchase', ['name' => 'Woven Label']);
        $tag = $this->makeProduct('direct_purchase', ['name' => 'Hang Tag']);
        $this->stockIn($label, $factory, 100, 0.05, $admin);

        $this->actingAs($admin)->post(route('sales-orders.store'), [
            'party_id' => $this->makeCustomer()->id,
            'order_date' => '2026-11-01',
            'buyer_reference' => 'PO-4411',
            'items' => [
                ['item' => "product-{$label->id}", 'quantity' => 100, 'unit_price' => 0.1, 'style' => 'ST-01'],
                ['item' => "product-{$tag->id}", 'quantity' => 50],
            ],
        ])->assertSessionHasNoErrors();
        $order = SalesOrder::sole();
        $this->actingAs($admin)->post(route('sales-orders.confirm', $order));

        // Sales Order: an unpriced line prints as "—" and the note explains the total.
        $this->actingAs($admin)->get(route('sales-orders.print', $order))->assertOk()
            ->assertSee($order->order_no)->assertSee('PO-4411')->assertSee('ST-01')->assertSee('Woven Label')
            ->assertSee('not priced yet');
        $this->actingAs($admin)->get(route('sales-orders.show', $order))->assertOk()->assertSee(route('sales-orders.print', $order));
        $this->actingAs($admin)->get(route('sales-orders.index'))->assertOk()->assertSee(route('sales-orders.print', $order));

        // Delivery Challan and Purchase Order raised straight from the order.
        $this->actingAs($admin)->post(route('deliveries.store'), [
            'sales_order_id' => $order->id,
            'site_id' => $factory->id,
            'delivery_date' => '2026-11-02',
            'usd_rate' => 120,
            'items' => [$order->items()->orderBy('id')->first()->id => ['quantity' => 100]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->get(route('deliveries.index'))->assertOk()->assertSee(route('deliveries.print', Delivery::sole()));

        $this->actingAs($admin)->post(route('purchases.store'), [
            'sales_order_id' => $order->id,
            'vendor_type' => 'supplier',
            'party_id' => $this->makeSupplier()->id,
            'site_id' => $factory->id,
            'order_date' => '2026-11-02',
            'items' => [['item' => "product-{$tag->id}", 'quantity' => 50, 'unit_cost' => 0.02]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->get(route('purchases.index'))->assertOk()->assertSee(route('purchases.print', Purchase::sole()));

        // Proforma Invoice.
        $this->actingAs($admin)->post(route('proforma-invoices.store'), $this->piPayload($order->refresh(), [
            'prices' => [$order->items()->orderBy('id')->get()[1]->id => 0.2],
        ]))->assertSessionHasNoErrors();
        $invoice = ProformaInvoice::sole();
        $this->actingAs($admin)->get(route('proforma-invoices.index'))->assertOk()->assertSee(route('proforma-invoices.print', $invoice));
        $this->actingAs($admin)->get(route('proforma-invoices.print', $invoice))->assertOk()->assertSee($invoice->pi_no);
    }

    public function test_sales_order_print_respects_sales_person_visibility(): void
    {
        $this->seedBasics();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('sales-orders.store'), [
            'party_id' => $this->makeCustomer()->id,
            'order_date' => '2026-11-01',
            'items' => [['item' => "product-{$this->makeProduct('direct_purchase')->id}", 'quantity' => 1, 'unit_price' => 1]],
        ]);
        $order = SalesOrder::sole();

        $this->actingAs($admin)->get(route('sales-orders.print', $order))->assertOk()->assertSee('In Words');
        $this->actingAs($this->userWithRole('Sales Person'))->get(route('sales-orders.print', $order))->assertForbidden();
    }
}
