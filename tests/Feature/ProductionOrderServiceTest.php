<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderService;
use App\Models\ProformaInvoice;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProformaInvoices;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

/**
 * Third-party work (dyeing, printing…) recorded on a Production Order:
 * posted to the supplier's payable and carried into the finished goods'
 * cost on completion.
 */
class ProductionOrderServiceTest extends TestCase
{
    use BuildsProformaInvoices;
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    private User $admin;

    private Site $factory;

    private Party $dyer;

    private Product $zipper;

    private ProformaInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
        $this->admin = $this->admin();
        $this->factory = $this->makeSite('Factory', 'FAC-01');
        $this->dyer = $this->makeSupplier('Rainbow Dyeing');

        $this->zipper = $this->makeProduct('manufactured');
        $tape = $this->makeProduct('raw_material');
        $this->zipper->bomItems()->create(['material_id' => $tape->id, 'quantity' => 1]);
        $this->stockIn($tape, $this->factory, 1000, 2, $this->admin);

        $this->invoice = $this->issuedPi($this->admin, $this->makeCustomer(), [
            ['product' => $this->zipper, 'quantity' => 600, 'unit_price' => 12, 'fulfillment' => 'production'],
            ['product' => $this->zipper, 'quantity' => 400, 'unit_price' => 12, 'fulfillment' => 'production'],
        ]);
    }

    private function production(): ProductionOrder
    {
        [$a, $b] = $this->invoice->items->sortBy('id')->values();

        $this->actingAs($this->admin)->post(route('production-orders.store'), [
            'source' => "pi-{$this->invoice->id}",
            'site_id' => $this->factory->id,
            'items' => [$a->id => 600, $b->id => 400],
        ])->assertSessionHasNoErrors();

        return ProductionOrder::with('items')->sole();
    }

    private function addService(ProductionOrder $order, array $data)
    {
        return $this->actingAs($this->admin)->post(route('production-orders.services.store', $order), array_merge([
            'party_id' => $this->dyer->id,
            'service' => 'Dyeing',
            'service_date' => '2026-11-12',
        ], $data));
    }

    public function test_third_party_work_is_posted_to_the_supplier_and_costed_into_the_output(): void
    {
        $order = $this->production();
        [$first, $second] = $order->items->sortBy('id')->values();

        // Whole-order dyeing, split by quantity (600 : 400) …
        $this->addService($order, ['quantity' => 1000, 'rate' => 0.5, 'amount' => 500, 'reference_no' => 'RD-77'])
            ->assertSessionHasNoErrors();
        // … and printing on the second line only.
        $this->addService($order, ['service' => 'Printing', 'production_order_item_id' => $second->id, 'amount' => 100])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertEquals(600, (float) $order->service_cost);
        $this->assertEquals(300, (float) $first->refresh()->service_cost);
        $this->assertEquals(300, (float) $second->refresh()->service_cost); // 200 + 100
        $this->assertEquals(600, $this->dyer->payableBalance());

        $this->actingAs($this->admin)->get(route('production-orders.show', $order))->assertOk()
            ->assertSee('Third-Party Work')->assertSee('Rainbow Dyeing')->assertSee('RD-77')->assertSee('Printing');

        // Cancelling is blocked while work is posted to a supplier.
        $this->actingAs($this->admin)->post(route('production-orders.cancel', $order))->assertSessionHas('error');
        $this->assertSame('not_started', $order->refresh()->status);

        // Output carries materials (2 / unit) plus its share of the third-party work.
        $this->actingAs($this->admin)->post(route('production-orders.complete', $order), [
            'produced' => [$first->id => 600, $second->id => 400],
        ])->assertSessionHasNoErrors();
        // 600 @ (1200 + 300) / 600 = 2.50 and 400 @ (800 + 300) / 400 = 2.75 → average 2.60.
        $this->assertEqualsWithDelta(2.6, (float) $this->zipper->refresh()->estimated_cost, 0.0001);

        // A closed order takes no more entries.
        $this->addService($order, ['amount' => 50])->assertSessionHas('error');
        $this->assertSame(2, ProductionOrderService::count());
    }

    public function test_removing_an_entry_reverses_it_from_the_supplier(): void
    {
        $order = $this->production();
        $this->addService($order, ['amount' => 250]);
        $service = ProductionOrderService::sole();

        $this->actingAs($this->admin)->delete(route('production-orders.services.destroy', [$order, $service]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, ProductionOrderService::count());
        $this->assertEquals(0, $this->dyer->payableBalance());
        $this->assertEquals(0, (float) $order->refresh()->service_cost);
        $this->actingAs($this->admin)->post(route('production-orders.cancel', $order));
        $this->assertSame('cancelled', $order->refresh()->status);
    }

    public function test_entry_needs_a_supplier_and_an_amount(): void
    {
        $order = $this->production();
        $customer = $this->makeCustomer('Not A Supplier');

        $this->addService($order, ['party_id' => $customer->id, 'amount' => 10])->assertSessionHasErrorsIn('service', 'party_id');
        $this->addService($order, ['amount' => 0])->assertSessionHasErrorsIn('service', 'amount');
        $this->actingAs($this->userWithRole('Sales Person'))
            ->post(route('production-orders.services.store', $order), ['amount' => 10])->assertForbidden();
        $this->assertSame(0, ProductionOrderService::count());
    }
}
