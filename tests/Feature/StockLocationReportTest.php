<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

class StockLocationReportTest extends TestCase
{
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    public function test_stock_is_split_by_site_and_consolidated_with_value(): void
    {
        $this->seedBasics();
        $accountant = $this->userWithRole('Accountant');
        $factory = $this->makeSite('Factory', 'FAC-01');
        $office = $this->makeSite('Head Office', 'HO-01');

        $tape = $this->makeProduct('raw_material', ['name' => 'Nylon Zipper Tape']);
        $zipper = $this->makeProduct('manufactured', ['name' => 'Metal Zipper 7 inch']);
        $empty = $this->makeProduct('direct_purchase', ['name' => 'Woven Label Never Stocked']);

        $this->stockIn($tape, $factory, 300, 2, $accountant);
        $this->stockIn($zipper, $factory, 100, 6, $accountant);
        $this->stockIn($zipper, $office, 50, 6, $accountant);

        $response = $this->actingAs($accountant)->get(route('stock.locations'))->assertOk();

        $rows = $response->viewData('rows')->getCollection()->keyBy('name');
        $this->assertEquals(100, $rows['Metal Zipper 7 inch']->sites[$factory->id]);
        $this->assertEquals(50, $rows['Metal Zipper 7 inch']->sites[$office->id]);
        $this->assertEquals(150, $rows['Metal Zipper 7 inch']->total);
        $this->assertEquals(900, $rows['Metal Zipper 7 inch']->value);
        $this->assertArrayNotHasKey('Woven Label Never Stocked', $rows->all());

        $this->assertEquals(1200, $response->viewData('siteValues')[$factory->id]); // 300×2 + 100×6
        $this->assertEquals(300, $response->viewData('siteValues')[$office->id]);
        $this->assertEquals(1500, $response->viewData('totalValue'));

        $rawOnly = $this->actingAs($accountant)->get(route('stock.locations', ['type' => 'raw_material']))
            ->viewData('rows')->getCollection()->pluck('name')->all();
        $this->assertSame(['Nylon Zipper Tape'], $rawOnly);
    }
}
