<?php

namespace Tests\Feature;

use App\Models\ImportAlias;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

class ImportAliasTest extends TestCase
{
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
    }

    public function test_normalize_treats_limited_punctuation_and_case_alike(): void
    {
        $this->assertSame(
            ImportAlias::normalize('Mosharaf Apparels Studio Limited'),
            ImportAlias::normalize('MOSHARAF  apparels studio ltd.')
        );
        $this->assertSame(
            ImportAlias::normalize('100%  Spun Polyester, 50/2'),
            ImportAlias::normalize('100% spun polyester 50/2')
        );
        $this->assertNotSame(
            ImportAlias::normalize('100% Spun Polyester, 50/2'),
            ImportAlias::normalize('100% Filament Polyester, 150D')
        );
    }

    public function test_remember_repoints_an_existing_alias(): void
    {
        $first = $this->makeCustomer('First Garments Ltd.');
        $second = $this->makeCustomer('Second Garments Ltd.');

        ImportAlias::remember($first, 'Mosharaf Apparels');
        ImportAlias::remember($second, 'MOSHARAF APPARELS.');

        $this->assertSame(1, ImportAlias::count());
        $this->assertTrue(ImportAlias::first()->aliasable->is($second));
        $this->assertCount(1, $second->aliases);
    }

    public function test_item_price_keeps_four_decimals(): void
    {
        $order = SalesOrder::create([
            'order_no' => 'SO-TEST',
            'party_id' => $this->makeCustomer()->id,
            'order_date' => '2026-09-22',
            'buyer_name' => 'BHPC & HENRY COTTON',
            'delivery_start_date' => '2026-09-30',
            'created_by' => $this->admin()->id,
        ]);

        $item = $order->items()->create([
            'product_id' => $this->makeProduct('manufactured')->id,
            'quantity' => 65,
            'unit_price' => 0.2186,
            'subtotal' => 14.21,
            'style' => 'TS472302',
            'color' => '0160',
            'length_per_unit' => 2000,
            'remarks' => 'Urgent',
        ]);

        $item = SalesOrderItem::find($item->id);
        $this->assertSame('0.2186', $item->unit_price);
        $this->assertSame('0160', $item->color);
        $this->assertSame('2026-09-30', $order->fresh()->delivery_start_date->toDateString());
    }
}
