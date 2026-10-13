<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProformaInvoice;
use App\Models\PurchaseRequisition;
use App\Models\Site;
use App\Models\User;
use App\Services\PiFulfillmentPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProformaInvoices;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

class PurchaseRequisitionTest extends TestCase
{
    use BuildsProformaInvoices;
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    private User $admin;

    private Site $factory;

    private Product $zipper;

    private Product $tape;

    private Product $slider;

    private Product $label;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
        $this->admin = $this->admin();
        $this->factory = $this->makeSite('Factory', 'FAC-01');

        $this->zipper = $this->makeProduct('manufactured');
        $this->tape = $this->makeProduct('raw_material', ['estimated_cost' => 2]);
        $this->slider = $this->makeProduct('raw_material', ['estimated_cost' => 1]);
        $this->label = $this->makeProduct('direct_purchase', ['estimated_cost' => 0.5]);

        $this->zipper->bomItems()->create(['material_id' => $this->tape->id, 'quantity' => 0.5]);
        $this->zipper->bomItems()->create(['material_id' => $this->slider->id, 'quantity' => 1]);
    }

    private function pi(): ProformaInvoice
    {
        return $this->issuedPi($this->admin, $this->makeCustomer(), [
            ['product' => $this->zipper, 'quantity' => 1000, 'unit_price' => 12, 'fulfillment' => 'production'],
            ['product' => $this->label, 'quantity' => 400, 'unit_price' => 3, 'fulfillment' => 'purchase'],
        ]);
    }

    public function test_planner_suggests_finished_goods_and_bom_materials_net_of_stock(): void
    {
        $this->stockIn($this->slider, $this->factory, 300, 1, $this->admin);
        $invoice = $this->pi();

        $suggestions = app(PiFulfillmentPlanner::class)->purchaseSuggestions($invoice)
            ->mapWithKeys(fn ($row) => [$row->product->id => $row->quantity]);

        $this->assertEquals(500, $suggestions[$this->tape->id]);      // 1000 × 0.5
        $this->assertEquals(700, $suggestions[$this->slider->id]);    // 1000 × 1 − 300 on hand
        $this->assertEquals(400, $suggestions[$this->label->id]);     // direct purchase line
        $this->assertArrayNotHasKey($this->zipper->id, $suggestions->all());

        $this->actingAs($this->admin)->get(route('purchase-requisitions.create', ['proforma_invoice_id' => $invoice->id]))
            ->assertOk()->assertSee($this->tape->name)->assertSee($this->label->name);
    }

    public function test_requisition_is_raised_under_a_pi_and_moves_it_in_progress(): void
    {
        $invoice = $this->pi();
        $factoryManager = $this->userWithRole('Factory Manager');

        $this->actingAs($factoryManager)->post(route('purchase-requisitions.store'), [
            'proforma_invoice_id' => $invoice->id,
            'site_id' => $this->factory->id,
            'request_date' => now()->toDateString(),
            'items' => [
                ['item' => "product-{$this->tape->id}", 'quantity' => 500, 'unit_cost' => 2, 'note' => 'Tape'],
                ['item' => "product-{$this->slider->id}", 'quantity' => 1000, 'unit_cost' => 1],
            ],
        ])->assertSessionHasNoErrors();

        $requisition = PurchaseRequisition::with('items')->first();
        $this->assertSame('REQ-000001', $requisition->requisition_no);
        $this->assertSame('pending', $requisition->status);
        $this->assertSame($factoryManager->id, $requisition->requested_by);
        $this->assertEquals(2000, $requisition->estimatedTotal());
        $this->assertSame('in_progress', $invoice->refresh()->status);

        // Already-requisitioned quantities drop out of the next suggestion.
        $remaining = app(PiFulfillmentPlanner::class)->purchaseSuggestions($invoice)->pluck('product.id')->all();
        $this->assertSame([$this->label->id], $remaining);

        // Factory Manager can't approve their own request; Admin can.
        $this->actingAs($factoryManager)->post(route('purchase-requisitions.approve', $requisition))->assertForbidden();
        $this->actingAs($this->admin)->post(route('purchase-requisitions.approve', $requisition));
        $this->assertSame('approved', $requisition->refresh()->status);
        $this->assertSame($this->admin->id, $requisition->approved_by);

        // Approved requisitions are no longer editable.
        $this->actingAs($this->admin)->get(route('purchase-requisitions.edit', $requisition))
            ->assertRedirect(route('purchase-requisitions.show', $requisition));
    }

    public function test_requisition_requires_a_live_pi(): void
    {
        $invoice = $this->pi();
        $this->actingAs($this->admin)->post(route('proforma-invoices.cancel', $invoice));

        $this->actingAs($this->admin)->post(route('purchase-requisitions.store'), [
            'proforma_invoice_id' => $invoice->id,
            'site_id' => $this->factory->id,
            'request_date' => now()->toDateString(),
            'items' => [['item' => "product-{$this->tape->id}", 'quantity' => 1]],
        ])->assertSessionHasErrors('proforma_invoice_id');

        $this->assertSame(0, PurchaseRequisition::count());
    }

    public function test_rejection_needs_a_reason(): void
    {
        $invoice = $this->pi();
        $this->actingAs($this->admin)->post(route('purchase-requisitions.store'), [
            'proforma_invoice_id' => $invoice->id,
            'site_id' => $this->factory->id,
            'request_date' => now()->toDateString(),
            'items' => [['item' => "product-{$this->label->id}", 'quantity' => 400]],
        ]);
        $requisition = PurchaseRequisition::first();

        $this->actingAs($this->admin)->post(route('purchase-requisitions.reject', $requisition))
            ->assertSessionHasErrors('rejection_reason');
        $this->actingAs($this->admin)->post(route('purchase-requisitions.reject', $requisition), ['rejection_reason' => 'Use stock from office']);

        $this->assertSame('rejected', $requisition->refresh()->status);
        $this->actingAs($this->admin)->get(route('purchase-requisitions.show', $requisition))
            ->assertOk()->assertSee('Use stock from office');
    }
}
