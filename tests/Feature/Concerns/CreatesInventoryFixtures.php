<?php

namespace Tests\Feature\Concerns;

use App\Models\Category;
use App\Models\Party;
use App\Models\Product;
use App\Models\Site;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

/**
 * Shared master data for the order-to-delivery feature tests — products of
 * each type, Factory/Office sites, a customer and a supplier, and opening
 * stock posted straight into the stock_movements ledger.
 */
trait CreatesInventoryFixtures
{
    protected function seedBasics(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(LedgerAccountSeeder::class);
    }

    protected function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function admin(): User
    {
        return $this->userWithRole('Admin');
    }

    protected function makeProduct(string $type, array $overrides = []): Product
    {
        static $sequence = 0;
        $sequence++;

        $category = Category::firstOrCreate(['name' => 'Garments Accessories']);
        $unit = Unit::firstOrCreate(['short_name' => 'Pcs'], ['name' => 'Piece']);

        return Product::create(array_merge([
            'name' => ucfirst(str_replace('_', ' ', $type))." Item {$sequence}",
            'sku' => strtoupper(substr($type, 0, 3))."-{$sequence}",
            'category_id' => $category->id,
            'product_type' => $type,
            'stock_unit_id' => $unit->id,
            'purchase_unit_conversion' => 1,
            'sale_unit_conversion' => 1,
            'estimated_cost' => 0,
            'selling_price' => 0,
            'reorder_level' => 0,
            'status' => true,
        ], $overrides));
    }

    protected function makeSite(string $type = 'Factory', ?string $code = null): Site
    {
        return Site::create([
            'name' => $type,
            'code' => $code ?? strtoupper(substr($type, 0, 3)).'-'.Site::count() + 1,
            'type' => $type,
            'status' => true,
        ]);
    }

    protected function makeCustomer(string $name = 'Buyer Garments Ltd.'): Party
    {
        return Party::create(['name' => $name, 'phone' => '01700'.str_pad((string) (Party::count() + 1), 6, '0', STR_PAD_LEFT), 'is_customer' => true, 'status' => true]);
    }

    protected function makeSupplier(string $name = 'Raw Material Supplier'): Party
    {
        return Party::create(['name' => $name, 'phone' => '01800'.str_pad((string) (Party::count() + 1), 6, '0', STR_PAD_LEFT), 'is_supplier' => true, 'status' => true]);
    }

    protected function stockIn(Product $product, Site $site, float $quantity, float $unitCost, ?User $by = null): void
    {
        StockMovement::create([
            'product_id' => $product->id,
            'site_id' => $site->id,
            'type' => 'initial_stock',
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'moved_at' => now()->toDateString(),
            'created_by' => ($by ?? User::first() ?? User::factory()->create())->id,
        ]);
    }

    protected function balance(Product $product, Site $site): float
    {
        return StockMovement::balanceFor($product->id, null, $site->id);
    }
}
