<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProformaInvoice;
use App\Models\Site;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\PiFulfillmentPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProformaInvoices;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

class ProductionOrderTest extends TestCase
{
    use BuildsProformaInvoices;
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    private User $admin;

    private User $factoryManager;

    private Site $factory;

    private Product $zipper;

    private Product $tape;

    private Product $slider;

    private ProformaInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
        $this->admin = $this->admin();
        $this->factoryManager = $this->userWithRole('Factory Manager');
        $this->factory = $this->makeSite('Factory', 'FAC-01');

        $this->zipper = $this->makeProduct('manufactured');
        $this->tape = $this->makeProduct('raw_material');
        $this->slider = $this->makeProduct('raw_material');
        $this->zipper->bomItems()->create(['material_id' => $this->tape->id, 'quantity' => 0.5]);
        $this->zipper->bomItems()->create(['material_id' => $this->slider->id, 'quantity' => 1]);

        // Average costs: tape 2.00, slider 1.00 → 2.00 material cost per zipper.
        $this->stockIn($this->tape, $this->factory, 1000, 2, $this->admin);
        $this->stockIn($this->slider, $this->factory, 1000, 1, $this->admin);

        $this->invoice = $this->issuedPi($this->admin, $this->makeCustomer(), [
            ['product' => $this->zipper, 'quantity' => 1000, 'unit_price' => 12, 'fulfillment' => 'production'],
        ]);
    }

    private function issue(float $quantity): ProductionOrder
    {
        $this->actingAs($this->factoryManager)->post(route('production-orders.store'), [
            'proforma_invoice_item_id' => $this->invoice->items->first()->id,
            'site_id' => $this->factory->id,
            'quantity' => $quantity,
        ])->assertSessionHasNoErrors();

        return ProductionOrder::latest('id')->first();
    }

    public function test_issuing_consumes_bom_materials_and_moves_pi_in_progress(): void
    {
        $this->actingAs($this->factoryManager)
            ->get(route('production-orders.create', ['proforma_invoice_item_id' => $this->invoice->items->first()->id]))
            ->assertOk()->assertSee($this->tape->name);

        $order = $this->issue(600);

        $this->assertSame('PRD-000001', $order->production_no);
        $this->assertSame('not_started', $order->status);
        $this->assertEquals(1200, (float) $order->material_cost);            // 600 × (0.5 × 2 + 1 × 1)
        $this->assertEquals(700, $this->balance($this->tape, $this->factory));   // 1000 − 300
        $this->assertEquals(400, $this->balance($this->slider, $this->factory)); // 1000 − 600
        $this->assertEquals(0, $this->balance($this->zipper, $this->factory));
        $this->assertSame('in_progress', $this->invoice->refresh()->status);

        // Only the 400 not yet covered can still be ordered or requisitioned.
        $line = $this->invoice->items()->with('productionOrders')->first();
        $this->assertEquals(400, $line->quantityToProduce());
        $suggested = app(PiFulfillmentPlanner::class)->purchaseSuggestions($this->invoice->fresh())
            ->mapWithKeys(fn ($row) => [$row->product->id => $row->quantity]);
        $this->assertArrayNotHasKey($this->tape->id, $suggested->all()); // 200 needed, 700 on hand

        $this->actingAs($this->factoryManager)->post(route('production-orders.store'), [
            'proforma_invoice_item_id' => $line->id,
            'site_id' => $this->factory->id,
            'quantity' => 500,
        ])->assertSessionHasErrors('quantity');
    }

    public function test_issue_is_blocked_when_raw_material_is_short_at_the_site(): void
    {
        $office = $this->makeSite('Head Office', 'HO-01');

        $this->actingAs($this->factoryManager)->post(route('production-orders.store'), [
            'proforma_invoice_item_id' => $this->invoice->items->first()->id,
            'site_id' => $office->id,
            'quantity' => 10,
        ])->assertSessionHasErrors('quantity');

        $this->assertSame(0, ProductionOrder::count());
        $this->assertSame(0, StockMovement::where('type', 'production_consumption')->count());
    }

    public function test_status_moves_through_start_and_complete_into_finished_stock(): void
    {
        $order = $this->issue(600);

        $this->actingAs($this->factoryManager)->post(route('production-orders.start', $order));
        $this->assertSame('in_progress', $order->refresh()->status);
        $this->assertNotNull($order->started_at);

        // A short run: 580 good units carry the full material cost.
        $this->actingAs($this->factoryManager)->post(route('production-orders.complete', $order), ['produced_quantity' => 700])
            ->assertSessionHasErrors('produced_quantity');
        $this->actingAs($this->factoryManager)->post(route('production-orders.complete', $order), ['produced_quantity' => 580]);

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertEquals(580, (float) $order->produced_quantity);
        $this->assertEquals(580, $this->balance($this->zipper, $this->factory));
        $this->assertEqualsWithDelta(1200 / 580, (float) $this->zipper->refresh()->estimated_cost, 0.0001);

        $this->actingAs($this->admin)->get(route('production-orders.show', $order))->assertOk()->assertSee('PRD-000001');
        $this->actingAs($this->admin)->get(route('proforma-invoices.show', $this->invoice))->assertOk()->assertSee('PRD-000001');
    }

    public function test_cancelling_returns_materials_without_skewing_their_cost(): void
    {
        $order = $this->issue(600);

        $this->actingAs($this->userWithRole('Sales Person'))->post(route('production-orders.cancel', $order))->assertForbidden();
        $this->actingAs($this->factoryManager)->post(route('production-orders.cancel', $order));

        $this->assertSame('cancelled', $order->refresh()->status);
        $this->assertEquals(1000, $this->balance($this->tape, $this->factory));
        $this->assertEquals(1000, $this->balance($this->slider, $this->factory));
        $this->assertEquals(2, (float) $this->tape->refresh()->estimated_cost);

        $line = $this->invoice->items()->with('productionOrders')->first();
        $this->assertEquals(1000, $line->quantityToProduce());

        $this->actingAs($this->factoryManager)->post(route('production-orders.complete', $order), ['produced_quantity' => 1])
            ->assertSessionHas('error');
    }
}
