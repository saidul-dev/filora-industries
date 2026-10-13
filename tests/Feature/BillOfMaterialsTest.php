<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

class BillOfMaterialsTest extends TestCase
{
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
    }

    public function test_product_type_is_saved_and_defaults_to_direct_purchase(): void
    {
        $admin = $this->admin();
        $base = $this->makeProduct('raw_material');

        $payload = [
            'name' => 'Metal Zipper 7"',
            'sku' => 'ZIP-7',
            'category_id' => $base->category_id,
            'stock_unit_id' => $base->stock_unit_id,
            'purchase_unit_conversion' => 1,
            'sale_unit_conversion' => 1,
            'estimated_cost' => 0,
            'selling_price' => 12,
            'reorder_level' => 0,
        ];

        $this->actingAs($admin)->post(route('products.store'), $payload + ['product_type' => 'manufactured'])
            ->assertRedirect(route('products.index'));
        $this->assertSame('manufactured', Product::firstWhere('sku', 'ZIP-7')->product_type);

        $this->actingAs($admin)->post(route('products.store'), ['sku' => 'ZIP-8'] + $payload)
            ->assertRedirect(route('products.index'));
        $this->assertSame('direct_purchase', Product::firstWhere('sku', 'ZIP-8')->product_type);

        $this->actingAs($admin)->post(route('products.store'), ['sku' => 'ZIP-9', 'product_type' => 'bogus'] + $payload)
            ->assertSessionHasErrors('product_type');
    }

    public function test_bom_can_be_defined_for_a_manufactured_product(): void
    {
        $finished = $this->makeProduct('manufactured');
        $tape = $this->makeProduct('raw_material', ['estimated_cost' => 2]);
        $slider = $this->makeProduct('raw_material', ['estimated_cost' => 1.5]);

        $this->actingAs($this->userWithRole('Factory Manager'))
            ->put(route('bom.update', $finished), ['items' => [
                ['material_id' => $tape->id, 'quantity' => 0.25],
                ['material_id' => $slider->id, 'quantity' => 1, 'note' => 'Auto-lock'],
            ]])
            ->assertRedirect(route('bom.edit', $finished));

        $bom = $finished->bomItems()->orderBy('id')->get();
        $this->assertCount(2, $bom);
        $this->assertEquals(0.25, (float) $bom[0]->quantity);
        $this->assertSame('Auto-lock', $bom[1]->note);

        $this->get(route('bom.index'))->assertOk()->assertSee($finished->name);
        $this->get(route('bom.edit', $finished))->assertOk()->assertSee($tape->name);
    }

    public function test_bom_rejects_self_reference_and_non_manufactured_products(): void
    {
        $finished = $this->makeProduct('manufactured');
        $bought = $this->makeProduct('direct_purchase');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('bom.update', $finished), ['items' => [['material_id' => $finished->id, 'quantity' => 1]]])
            ->assertSessionHasErrors('items');

        $this->actingAs($admin)
            ->put(route('bom.update', $bought), ['items' => [['material_id' => $finished->id, 'quantity' => 1]]])
            ->assertRedirect(route('bom.index'));
        $this->assertSame(0, $bought->bomItems()->count());
    }

    public function test_sales_person_cannot_edit_a_bom(): void
    {
        $finished = $this->makeProduct('manufactured');
        $material = $this->makeProduct('raw_material');

        $this->actingAs($this->userWithRole('Sales Person'))
            ->put(route('bom.update', $finished), ['items' => [['material_id' => $material->id, 'quantity' => 1]]])
            ->assertForbidden();
    }
}
