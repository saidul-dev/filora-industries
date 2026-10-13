<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bill of Materials setup — which raw materials/components, and how much
 * of each, are consumed to produce one stock unit of a Manufactured
 * product. Production Orders copy these lines at the moment they're
 * issued (see ProductionOrderController), so editing a BOM never rewrites
 * the history of orders already in progress.
 */
class BomController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:production.view', only: ['index', 'edit']),
            new Middleware('permission:production.edit', only: ['update']),
        ];
    }

    public function index(Request $request)
    {
        $q = $request->get('q');

        $products = Product::with(['category', 'stockUnit', 'bomItems.material'])
            ->where('product_type', 'manufactured')
            ->when($q, fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$q}%")
                ->orWhere('sku', 'like', "%{$q}%")))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.bom.index', compact('products', 'q'));
    }

    public function edit(Product $product)
    {
        if (! $product->isManufactured()) {
            return redirect()->route('bom.index')->with('error', "\"{$product->name}\" is not a Manufactured product — only manufactured products have a BOM.");
        }

        $product->load(['stockUnit', 'bomItems.material.stockUnit']);

        $materialOptions = Product::with('stockUnit')
            ->where('status', true)
            ->where('has_variants', false)
            ->where('id', '!=', $product->id)
            ->orderByRaw("CASE WHEN product_type = 'raw_material' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get()
            ->map(fn (Product $material) => [
                'id' => $material->id,
                'name' => "{$material->name} ({$material->sku})",
                'unit' => $material->stockUnit?->short_name,
                'cost' => (float) $material->estimated_cost,
                'type' => $material->product_type,
            ])
            ->values();

        $items = $product->bomItems->map(fn ($item) => [
            'id' => $item->material_id,
            'name' => "{$item->material->name} ({$item->material->sku})",
            'unit' => $item->material->stockUnit?->short_name,
            'cost' => (float) $item->material->estimated_cost,
            'quantity' => (float) $item->quantity,
            'note' => $item->note,
        ])->values();

        return view('admin.bom.edit', compact('product', 'materialOptions', 'items'));
    }

    /**
     * Replaces the BOM wholesale — safe because nothing references
     * bom_items rows directly (Production Orders keep their own copy).
     */
    public function update(Request $request, Product $product)
    {
        if (! $product->isManufactured()) {
            return redirect()->route('bom.index')->with('error', 'Only a Manufactured product can have a BOM.');
        }

        $validated = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.material_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $rows = collect($validated['items'] ?? []);

        if ($rows->contains(fn ($row) => (int) $row['material_id'] === $product->id)) {
            throw ValidationException::withMessages(['items' => 'A product cannot be a raw material of itself.']);
        }

        $variantMaterials = Product::whereIn('id', $rows->pluck('material_id'))->where('has_variants', true)->pluck('name');
        if ($variantMaterials->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'Variable products cannot be BOM materials: '.$variantMaterials->implode(', ').'.']);
        }

        DB::transaction(function () use ($product, $rows) {
            $product->bomItems()->delete();

            foreach ($rows as $row) {
                $product->bomItems()->create([
                    'material_id' => $row['material_id'],
                    'quantity' => $row['quantity'],
                    'note' => $row['note'] ?? null,
                ]);
            }
        });

        return redirect()->route('bom.edit', $product)
            ->with('success', "BOM for \"{$product->name}\" saved — ".$rows->count().' material(s).');
    }
}
