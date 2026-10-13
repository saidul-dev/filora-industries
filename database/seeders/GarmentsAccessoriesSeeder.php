<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Party;
use App\Models\Product;
use App\Models\Site;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo catalogue for a garments accessories manufacturer (see
 * docs/order-to-delivery.md): raw materials, manufactured products with a
 * BOM, direct-purchase finished goods, a couple of buyers/suppliers, and
 * opening stock at the Factory/Office — enough to walk a Sales Order all
 * the way to delivery on a fresh install. Idempotent (keyed by SKU/phone).
 */
class GarmentsAccessoriesSeeder extends Seeder
{
    public function run(): void
    {
        $units = collect([
            ['name' => 'Piece', 'short_name' => 'Pcs'],
            ['name' => 'Meter', 'short_name' => 'm'],
            ['name' => 'Kilogram', 'short_name' => 'Kg'],
            ['name' => 'Roll', 'short_name' => 'Roll'],
            ['name' => 'Carton', 'short_name' => 'Ctn'],
        ])->mapWithKeys(fn ($unit) => [$unit['short_name'] => Unit::firstOrCreate(['short_name' => $unit['short_name']], $unit)]);

        $categories = collect(['Zippers', 'Buttons', 'Labels & Tags', 'Packaging Accessories', 'Raw Materials'])
            ->mapWithKeys(fn ($name) => [$name => Category::firstOrCreate(['name' => $name])]);

        $product = function (string $sku, string $name, string $type, string $category, string $unit, float $cost, float $price) use ($units, $categories) {
            return Product::firstOrCreate(['sku' => $sku], [
                'name' => $name,
                'product_type' => $type,
                'category_id' => $categories[$category]->id,
                'stock_unit_id' => $units[$unit]->id,
                'purchase_unit_conversion' => 1,
                'sale_unit_conversion' => 1,
                'estimated_cost' => $cost,
                'selling_price' => $price,
                'reorder_level' => 0,
                'status' => true,
            ]);
        };

        // Raw materials / components (consumed by BOMs).
        $tape = $product('FIL-RM-TAPE', 'Nylon Zipper Tape', 'raw_material', 'Raw Materials', 'm', 4, 0);
        $chain = $product('FIL-RM-CHAIN', 'Brass Teeth Chain', 'raw_material', 'Raw Materials', 'm', 18, 0);
        $slider = $product('FIL-RM-SLIDER', 'Auto-lock Zipper Slider', 'raw_material', 'Raw Materials', 'Pcs', 1.5, 0);
        $resin = $product('FIL-RM-RESIN', 'Polyester Button Resin', 'raw_material', 'Raw Materials', 'Kg', 220, 0);
        $thread = $product('FIL-RM-THREAD', 'Polyester Weaving Yarn', 'raw_material', 'Raw Materials', 'Roll', 60, 0);
        $satin = $product('FIL-RM-SATIN', 'Satin Label Ribbon', 'raw_material', 'Raw Materials', 'm', 2, 0);

        // Manufactured at the factory, each with its BOM per one unit.
        $boms = [
            [$product('FIL-ZIP-MTL7', 'Metal Zipper 7"', 'manufactured', 'Zippers', 'Pcs', 0, 12), [[$tape, 0.2], [$chain, 0.18], [$slider, 1]]],
            [$product('FIL-ZIP-NYL9', 'Nylon Coil Zipper 9"', 'manufactured', 'Zippers', 'Pcs', 0, 6), [[$tape, 0.25], [$slider, 1]]],
            [$product('FIL-BTN-18L', 'Polyester 4-Hole Button 18L', 'manufactured', 'Buttons', 'Pcs', 0, 0.8), [[$resin, 0.002]]],
            [$product('FIL-LBL-WVN', 'Woven Main Label', 'manufactured', 'Labels & Tags', 'Pcs', 0, 1.5), [[$thread, 0.005], [$satin, 0.06]]],
        ];

        foreach ($boms as [$finished, $materials]) {
            foreach ($materials as [$material, $quantity]) {
                $finished->bomItems()->firstOrCreate(['material_id' => $material->id], ['quantity' => $quantity]);
            }
        }

        // Bought ready-made and delivered as-is.
        $hangTag = $product('FIL-DP-HANGTAG', 'Printed Hang Tag', 'direct_purchase', 'Labels & Tags', 'Pcs', 0.9, 2);
        $polyBag = $product('FIL-DP-POLYBAG', 'Poly Bag 10x12', 'direct_purchase', 'Packaging Accessories', 'Pcs', 1.2, 2.5);
        $carton = $product('FIL-DP-CARTON', 'Export Carton 5-ply', 'direct_purchase', 'Packaging Accessories', 'Ctn', 85, 140);

        Party::firstOrCreate(['phone' => '01710000101'], ['name' => 'Sample Apparels Ltd.', 'is_customer' => true, 'is_company' => true, 'status' => true]);
        Party::firstOrCreate(['phone' => '01710000102'], ['name' => 'Demo Knitwear Ltd.', 'is_customer' => true, 'is_company' => true, 'status' => true]);
        Party::firstOrCreate(['phone' => '01810000201'], ['name' => 'Tape & Trims Supply Co.', 'is_supplier' => true, 'is_company' => true, 'status' => true]);
        Party::firstOrCreate(['phone' => '01810000202'], ['name' => 'Packaging Solutions BD', 'is_supplier' => true, 'is_company' => true, 'status' => true]);

        $factory = Site::where('code', 'FAC-01')->first();
        $office = Site::where('code', 'HO-01')->first();
        $user = User::where('email', 'admin@businesserp.test')->first() ?? User::first();

        if (! $factory || ! $office || ! $user) {
            return;
        }

        // Opening stock — raw materials at the factory, finished samples and
        // packaging at the office. Skipped once a product has any movement
        // at that site, same rule as the Initial Stock screen.
        $opening = [
            [$tape, $factory, 2000], [$chain, $factory, 600], [$slider, $factory, 3000],
            [$resin, $factory, 40], [$thread, $factory, 30], [$satin, $factory, 1500],
            [$polyBag, $office, 1000], [$hangTag, $office, 500], [$carton, $office, 40],
        ];

        foreach ($opening as [$item, $site, $quantity]) {
            if (StockMovement::where('product_id', $item->id)->where('site_id', $site->id)->exists()) {
                continue;
            }

            $movement = new StockMovement([
                'product_id' => $item->id,
                'site_id' => $site->id,
                'type' => 'initial_stock',
                'quantity' => $quantity,
                'unit_cost' => $item->estimated_cost,
                'moved_at' => now()->toDateString(),
                'created_by' => $user->id,
            ]);
            // DatabaseSeeder runs WithoutModelEvents, so StockMovement's
            // creating hook won't derive this — set it from the same map.
            $movement->direction = StockMovement::TYPES['initial_stock'];
            $movement->save();
        }
    }
}
