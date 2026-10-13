<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\LedgerAccount;
use App\Models\ProductionOrder;
use App\Models\ProformaInvoice;
use App\Models\Purchase;
use App\Models\PurchaseRequisition;
use App\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

/**
 * Appendix A of the Package A spec, end to end, each step by the role that
 * owns it: Sales Order → Confirm → PI → LC → Requisition → Purchase Order →
 * Goods Receipt → Production (BOM consumption) → Finished goods → Delivery
 * → Collection → Accounts.
 */
class OrderToDeliveryFlowTest extends TestCase
{
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    public function test_full_order_to_delivery_cycle(): void
    {
        Storage::fake('local');
        $this->seedBasics();

        $admin = $this->admin();
        $salesPerson = $this->userWithRole('Sales Person');
        $factoryManager = $this->userWithRole('Factory Manager');
        $accountant = $this->userWithRole('Accountant');

        $factory = $this->makeSite('Factory', 'FAC-01');
        $office = $this->makeSite('Head Office', 'HO-01');
        $buyer = $this->makeCustomer('Sample Apparels Ltd.');
        $supplier = $this->makeSupplier('Tape & Trims Supply Co.');

        $tape = $this->makeProduct('raw_material', ['name' => 'Nylon Zipper Tape']);
        $slider = $this->makeProduct('raw_material', ['name' => 'Zipper Slider']);
        $zipper = $this->makeProduct('manufactured', ['name' => 'Nylon Zipper 9 inch']);
        $polyBag = $this->makeProduct('direct_purchase', ['name' => 'Poly Bag 10x12']);
        $zipper->bomItems()->create(['material_id' => $tape->id, 'quantity' => 0.25]);
        $zipper->bomItems()->create(['material_id' => $slider->id, 'quantity' => 1]);
        $this->stockIn($slider, $factory, 2000, 1.5, $admin); // sliders already in stock; tape is not

        // 1. Sales Person enters the order (Pending, editable).
        $this->actingAs($salesPerson)->post(route('sales-orders.store'), [
            'party_id' => $buyer->id,
            'order_date' => now()->toDateString(),
            'buyer_reference' => 'BUYER-PO-778',
            'items' => [
                ['item' => "product-{$zipper->id}", 'quantity' => 1000, 'unit_price' => 6],
                ['item' => "product-{$polyBag->id}", 'quantity' => 1000, 'unit_price' => 2.5],
            ],
        ])->assertSessionHasNoErrors();
        $order = SalesOrder::first();
        $this->assertSame('pending', $order->status);

        // 2. Admin confirms and generates the PI, then issues it.
        $this->actingAs($admin)->post(route('sales-orders.confirm', $order));
        $this->actingAs($admin)->post(route('proforma-invoices.store', $order));
        $pi = ProformaInvoice::first();
        $this->assertSame(['production', 'purchase'], $pi->items()->orderBy('id')->pluck('fulfillment')->all());
        $this->actingAs($admin)->post(route('proforma-invoices.issue', $pi));

        // 3. LC attached → LC Issued.
        $this->actingAs($admin)->post(route('proforma-invoices.lc', $pi), [
            'lc_no' => 'LC-0042', 'lc_expiry_date' => now()->addMonths(3)->toDateString(),
            'lc_file' => UploadedFile::fake()->create('lc.pdf', 50, 'application/pdf'),
        ]);
        $this->assertSame('lc_issued', $pi->refresh()->status);

        // 4. Factory Manager raises a requisition for what's missing
        //    (tape for production, poly bags to buy finished); Admin approves.
        $this->actingAs($factoryManager)->post(route('purchase-requisitions.store'), [
            'proforma_invoice_id' => $pi->id,
            'site_id' => $factory->id,
            'request_date' => now()->toDateString(),
            'items' => [
                ['item' => "product-{$tape->id}", 'quantity' => 250, 'unit_cost' => 4],
                ['item' => "product-{$polyBag->id}", 'quantity' => 1000, 'unit_cost' => 1.2],
            ],
        ])->assertSessionHasNoErrors();
        $requisition = PurchaseRequisition::first();
        $this->assertSame('in_progress', $pi->refresh()->status);
        $this->actingAs($admin)->post(route('purchase-requisitions.approve', $requisition));

        // 5. Accounts places the Purchase Order with the supplier.
        $this->actingAs($accountant)->post(route('purchases.store'), [
            'purchase_requisition_id' => $requisition->id,
            'vendor_type' => 'supplier',
            'party_id' => $supplier->id,
            'site_id' => $factory->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['item' => "product-{$tape->id}", 'quantity' => 250, 'unit_cost' => 4],
                ['item' => "product-{$polyBag->id}", 'quantity' => 1000, 'unit_cost' => 1.2],
            ],
        ])->assertSessionHasNoErrors();
        $purchase = Purchase::first();

        // 6. Goods receipt: tape at the Factory, poly bags at the Office.
        $tapeLine = $purchase->items->firstWhere('product_id', $tape->id);
        $bagLine = $purchase->items->firstWhere('product_id', $polyBag->id);
        $this->actingAs($factoryManager)->post(route('purchases.receive.store', $purchase), [
            'site_id' => $factory->id, 'received_date' => now()->toDateString(),
            'items' => [$tapeLine->id => ['quantity' => 250]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($factoryManager)->post(route('purchases.receive.store', $purchase), [
            'site_id' => $office->id, 'received_date' => now()->toDateString(),
            'items' => [$bagLine->id => ['quantity' => 1000]],
        ])->assertSessionHasNoErrors();
        $this->assertSame('received', $purchase->refresh()->status);
        $this->assertEquals(2200, $supplier->payableBalance()); // 250×4 + 1000×1.2

        // 7. Production under the PI line consumes the BOM, then completes.
        $zipperLine = $pi->items()->where('product_id', $zipper->id)->first();
        $this->actingAs($factoryManager)->post(route('production-orders.store'), [
            'proforma_invoice_item_id' => $zipperLine->id, 'site_id' => $factory->id, 'quantity' => 1000,
        ])->assertSessionHasNoErrors();
        $production = ProductionOrder::first();
        $this->assertEquals(0, $this->balance($tape, $factory));
        $this->assertEquals(1000, $this->balance($slider, $factory));
        $this->actingAs($factoryManager)->post(route('production-orders.start', $production));
        $this->actingAs($factoryManager)->post(route('production-orders.complete', $production), ['produced_quantity' => 1000]);
        $this->assertEquals(1000, $this->balance($zipper, $factory));
        $this->assertEquals(2.5, (float) $zipper->refresh()->estimated_cost); // 0.25×4 + 1×1.5

        // 8. Deliver: zippers from the Factory, poly bags from the Office.
        $this->actingAs($admin)->post(route('proforma-invoices.ready', $pi));
        $bagPiLine = $pi->items()->where('product_id', $polyBag->id)->first();
        $this->actingAs($admin)->post(route('deliveries.store'), [
            'proforma_invoice_id' => $pi->id, 'site_id' => $factory->id, 'delivery_date' => now()->toDateString(),
            'items' => [$zipperLine->id => ['quantity' => 1000]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('deliveries.store'), [
            'proforma_invoice_id' => $pi->id, 'site_id' => $office->id, 'delivery_date' => now()->toDateString(),
            'items' => [$bagPiLine->id => ['quantity' => 1000]],
        ])->assertSessionHasNoErrors();
        $this->assertSame('delivered', $pi->refresh()->status);
        $this->assertSame(2, Delivery::count());
        $this->assertEquals(8500, $buyer->receivableBalance()); // 6,000 + 2,500

        // 9. Accounts collects the LC proceeds against the PI.
        $this->actingAs($accountant)->post(route('collections.store'), [
            'party_id' => $buyer->id,
            'proforma_invoice_id' => $pi->id,
            'ledger_account_id' => LedgerAccount::where('code', 'cash_in_hand')->value('id'),
            'amount' => 8500,
            'collection_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertEquals(0, $buyer->receivableBalance());
        $this->assertEquals(0, $pi->dueAmount());

        // 10. Accounts: cost and income are both on the books.
        $this->assertEquals(8500, LedgerAccount::where('code', 'sales_revenue')->first()->balance());
        $this->assertEquals(3700, LedgerAccount::where('code', 'cost_of_goods_sold')->first()->balance()); // 1000×2.5 + 1000×1.2

        $this->actingAs($admin)->post(route('proforma-invoices.close', $pi));
        $this->assertSame('closed', $pi->refresh()->status);

        $this->actingAs($admin)->get(route('proforma-invoices.show', $pi))->assertOk()
            ->assertSee($purchase->purchase_no)->assertSee($production->production_no)->assertSee('DC-000002');
    }
}
