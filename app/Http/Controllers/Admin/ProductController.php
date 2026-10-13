<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductVariant;
use App\Models\PurchaseReceipt;
use App\Models\Site;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProductController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:inventory.view', only: ['index', 'barcode', 'history']),
            new Middleware('permission:inventory.create', only: ['create', 'store']),
            new Middleware('permission:inventory.edit', only: ['edit', 'update', 'toggleStatus']),
            new Middleware('permission:inventory.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $type = array_key_exists($request->get('type'), Product::TYPES) ? $request->get('type') : null;

        $products = Product::with(['category', 'brand', 'stockUnit'])
            ->withCount('bomItems')
            ->when($type, fn ($q) => $q->where('product_type', $type))
            ->when($request->filled('q'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->q}%")
                    ->orWhere('sku', 'like', "%{$request->q}%")
                    ->orWhere('barcode', 'like', "%{$request->q}%");
            }))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.products.index', compact('products', 'type'));
    }

    public function create()
    {
        return view('admin.products.create', $this->formOptions());
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);
        $variants = $validated['has_variants'] ? $this->validateVariants($request, null) : [];

        if ($request->hasFile('image')) {
            $validated['image_path'] = $request->file('image')->store('products', 'public');
        }

        $product = DB::transaction(function () use ($validated, $variants) {
            $product = Product::create(collect($validated)->except(['image', 'gallery_images', 'remove_gallery_ids'])->all());
            if ($product->has_variants) {
                $this->syncVariants($product, $variants);
            }
            $this->syncGalleryImages($product, $validated);

            return $product;
        });

        return redirect()->route('products.index')->with('success', "Product \"{$product->name}\" created.");
    }

    public function edit(Product $product)
    {
        $product->load('variants.attributeValues', 'images');

        return view('admin.products.edit', array_merge([
            'product' => $product,
            'hasCostHistory' => $product->stockMovements()->exists(),
        ], $this->formOptions()));
    }

    public function update(Request $request, Product $product)
    {
        $validated = $this->validated($request, $product);
        $variants = $validated['has_variants'] ? $this->validateVariants($request, $product) : [];

        // Estimated cost is only a manual starting reference before this
        // product has any stock movement history — once it does, cost is
        // auto-maintained by StockMovement::recalculateAverageCost() and
        // this form's (readonly) field must not overwrite it.
        if ($product->stockMovements()->exists()) {
            unset($validated['estimated_cost']);
        }

        if ($request->boolean('remove_image') && $product->image_path) {
            Storage::disk('public')->delete($product->image_path);
            $validated['image_path'] = null;
        }

        if ($request->hasFile('image')) {
            if ($product->image_path) {
                Storage::disk('public')->delete($product->image_path);
            }
            $validated['image_path'] = $request->file('image')->store('products', 'public');
        }

        DB::transaction(function () use ($request, $product, $validated, $variants) {
            $product->update(collect($validated)->except(['image', 'remove_image', 'gallery_images', 'remove_gallery_ids'])->all());

            if ($product->has_variants) {
                $this->syncVariants($product, $variants);
            } else {
                // Feature turned off — drop any existing variants and their links.
                $product->variants()->each(function (ProductVariant $variant) {
                    $variant->attributeValues()->detach();
                    $variant->delete();
                });
            }

            $this->syncGalleryImages($product, $validated);
        });

        return redirect()->route('products.index')->with('success', "Product \"{$product->name}\" updated.");
    }

    public function toggleStatus(Product $product)
    {
        $product->update(['status' => ! $product->status]);

        return back()->with('success', "Product \"{$product->name}\" is now ".($product->status ? 'active' : 'inactive').'.');
    }

    public function barcode()
    {
        $products = Product::orderBy('name')->get()->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->name,
            'value' => $product->barcode ?: $product->sku,
            'price' => number_format($product->selling_price, 2),
        ]);

        return view('admin.products.barcode', [
            'products' => $products,
            'companyName' => CompanySetting::current()->name,
        ]);
    }

    /**
     * Stock ledger for one product: every StockMovement row across its
     * variants, newest first — the audit trail current-balance-only
     * reports (Stock Report) can't answer ("why did this drop?", "what did
     * we pay this batch at?").
     */
    public function history(Request $request, Product $product)
    {
        $product->load(['stockUnit', 'variants.attributeValues']);
        $sites = Site::where('status', true)->orderBy('name')->get(['id', 'name']);
        $siteId = $request->filled('site_id') ? $request->integer('site_id') : null;

        $movements = StockMovement::with(['productVariant.attributeValues', 'site', 'createdBy'])
            ->where('product_id', $product->id)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->orderByDesc('moved_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $this->attachReferenceLabels($movements->getCollection());

        $currentStock = $this->currentStockBreakdown($product, $siteId);
        $totalStock = $currentStock->sum('balance');
        $totalCostValuation = $currentStock->sum('cost_valuation');
        $totalSaleValuation = $currentStock->sum('sale_valuation');

        return view('admin.products.history', compact(
            'product', 'movements', 'sites', 'siteId',
            'currentStock', 'totalStock', 'totalCostValuation', 'totalSaleValuation'
        ));
    }

    /**
     * On-hand balance per Site (and per Variant, for variable products),
     * so the ledger page answers "how much is there right now?" alongside
     * "how did it get there?" — not just aggregate movement rows. Rows
     * that have netted to zero are dropped; a site/variant combo with no
     * stock left isn't "current stock." Valuations use each variant's own
     * cost/selling price (falling back to the parent product's), same as
     * the Stock Report's group-row valuation.
     */
    protected function currentStockBreakdown(Product $product, ?int $siteId)
    {
        $rows = StockMovement::where('product_id', $product->id)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->selectRaw("site_id, product_variant_id, SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) as balance")
            ->groupBy('site_id', 'product_variant_id')
            ->having('balance', '!=', 0)
            ->get();

        $siteNames = Site::whereIn('id', $rows->pluck('site_id')->unique())->pluck('name', 'id');
        $variants = $product->variants->keyBy('id');

        return $rows->map(function ($row) use ($siteNames, $variants, $product) {
            $variant = $row->product_variant_id ? $variants[$row->product_variant_id] : null;
            $balance = (float) $row->balance;
            $costPrice = (float) ($variant?->estimated_cost ?? $product->estimated_cost);
            $salePrice = (float) ($variant?->selling_price ?? $product->selling_price);

            return (object) [
                'site' => $siteNames[$row->site_id] ?? '—',
                'variant' => $variant?->label,
                'balance' => $balance,
                'cost_price' => $costPrice,
                'sale_price' => $salePrice,
                'cost_valuation' => $balance * $costPrice,
                'sale_valuation' => $balance * $salePrice,
            ];
        })->sortBy(['site', 'variant'])->values();
    }

    /**
     * Human label + a link to the source document for each movement's
     * polymorphic reference (null for adjustment/initial-stock rows, which
     * carry no document — just a reason/note).
     */
    protected function attachReferenceLabels($movements): void
    {
        $refs = [
            StockTransfer::class => fn ($r) => ['label' => $r->transfer_no, 'url' => route('stock.transfers.show', $r)],
            PurchaseReceipt::class => fn ($r) => ['label' => $r->receipt_no, 'url' => route('purchases.show', $r->purchase_id)],
            ProductionOrder::class => fn ($r) => ['label' => $r->production_no, 'url' => route('production-orders.show', $r)],
        ];

        $movements->loadMorph('reference', array_map(fn () => [], $refs));

        $movements->each(function (StockMovement $movement) use ($refs) {
            $movement->reference_label = null;
            $movement->reference_url = null;

            if ($movement->reference && isset($refs[$movement->reference_type])) {
                $resolved = $refs[$movement->reference_type]($movement->reference);
                $movement->reference_label = $resolved['label'];
                $movement->reference_url = $resolved['url'];
            }
        });
    }

    public function destroy(Product $product)
    {
        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        foreach ($product->images as $image) {
            Storage::disk('public')->delete($image->image_path);
        }

        $product->delete();

        return redirect()->route('products.index')->with('success', "Product \"{$product->name}\" deleted.");
    }

    protected function formOptions(): array
    {
        return [
            'categories' => Category::orderBy('name')->get(),
            'brands' => Brand::orderBy('name')->get(),
            'units' => Unit::orderBy('name')->get(),
            'attributes' => Attribute::with('values')->orderBy('name')->get(),
        ];
    }

    protected function validated(Request $request, ?Product $product = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product?->id)],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->ignore($product?->id)],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'product_type' => ['nullable', Rule::in(array_keys(Product::TYPES))],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'stock_unit_id' => ['required', 'integer', 'exists:units,id'],
            'purchase_unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'purchase_unit_conversion' => ['required', 'numeric', 'min:0.0001'],
            'sale_unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'sale_unit_conversion' => ['required', 'numeric', 'min:0.0001'],
            // Trix's HTML output carries markup overhead beyond the visible
            // text, so this needs far more headroom than a plain textarea.
            // Only ever posted when the form's e-commerce section is shown.
            'description' => ['nullable', 'string', 'max:20000'],
            // Shown on the product page regardless of ecommerce_enabled —
            // see the short_description migration for why it's separate.
            'short_description' => ['nullable', 'string', 'max:500'],
            'estimated_cost' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'reorder_level' => ['required', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'max:2048'],
            'remove_image' => ['nullable', 'boolean'],
            'gallery_images' => ['nullable', 'array'],
            'gallery_images.*' => ['image', 'max:2048'],
            'remove_gallery_ids' => ['nullable', 'array'],
            'remove_gallery_ids.*' => ['integer', 'exists:product_images,id'],
            'has_variants' => ['nullable', 'boolean'],
            'track_batch' => ['nullable', 'boolean'],
            'track_expiry' => ['nullable', 'boolean'],
            'track_serial' => ['nullable', 'boolean'],
            'warranty_period' => ['nullable', 'integer', 'min:1'],
            'warranty_unit' => ['nullable', 'required_with:warranty_period', 'in:days,months,years'],
            'guarantee_period' => ['nullable', 'integer', 'min:1'],
            'guarantee_unit' => ['nullable', 'required_with:guarantee_period', 'in:days,months,years'],
        ]);

        // Older clients/imports that never send a type keep the product's
        // current one (or the column default for a new product).
        $validated['product_type'] ??= $product?->product_type ?? 'direct_purchase';

        // Normalize booleans (unchecked checkboxes are absent from the request).
        foreach (['has_variants', 'track_batch', 'track_expiry', 'track_serial'] as $flag) {
            $validated[$flag] = $request->boolean($flag);
        }

        // Expiry lives on a batch — enabling it implies batch tracking.
        if ($validated['track_expiry']) {
            $validated['track_batch'] = true;
        }

        // A duration with no period is meaningless — null the unit.
        if (empty($validated['warranty_period'])) {
            $validated['warranty_period'] = null;
            $validated['warranty_unit'] = null;
        }
        if (empty($validated['guarantee_period'])) {
            $validated['guarantee_period'] = null;
            $validated['guarantee_unit'] = null;
        }

        return $validated;
    }

    /**
     * Validate the nested variants payload. Only called when has_variants is on.
     * Uniqueness for sku/barcode is enforced against product_variants, ignoring
     * rows belonging to this product (so its own variants don't self-collide).
     */
    protected function validateVariants(Request $request, ?Product $product): array
    {
        $ignoreIds = $product ? $product->variants()->pluck('id')->all() : [];

        $validated = $request->validate([
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.sku' => ['required', 'string', 'max:100', 'distinct',
                function ($attr, $value, $fail) use ($ignoreIds) {
                    $exists = ProductVariant::where('sku', $value)->whereNotIn('id', $ignoreIds)->exists();
                    if ($exists) {
                        $fail('This SKU is already in use.');
                    }
                }],
            'variants.*.barcode' => ['nullable', 'string', 'max:100', 'distinct',
                function ($attr, $value, $fail) use ($ignoreIds) {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $exists = ProductVariant::where('barcode', $value)->whereNotIn('id', $ignoreIds)->exists();
                    if ($exists) {
                        $fail('This barcode is already in use.');
                    }
                }],
            'variants.*.selling_price' => ['required', 'numeric', 'min:0'],
            'variants.*.estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'variants.*.status' => ['nullable', 'boolean'],
            'variants.*.values' => ['required', 'array', 'min:1'],
            'variants.*.values.*' => ['required', 'integer', 'exists:attribute_values,id'],
        ]);

        return $validated['variants'];
    }

    /**
     * Create/update/delete variants for a product and re-sync their
     * attribute-value pivot rows. $variants is the validated variant array.
     */
    protected function syncVariants(Product $product, array $variants): void
    {
        $keepIds = [];

        foreach ($variants as $row) {
            $attributes = [
                'sku' => $row['sku'],
                'barcode' => $row['barcode'] ?? null,
                'selling_price' => $row['selling_price'],
                'estimated_cost' => $row['estimated_cost'] ?? null,
                'status' => (bool) ($row['status'] ?? true),
            ];

            if (! empty($row['id'])) {
                $variant = $product->variants()->findOrFail($row['id']);

                // Same rule as the parent Product: once a variant has stock
                // movement history, its cost is auto-maintained and this
                // (readonly) form field must not overwrite it.
                if ($variant->stockMovements()->exists()) {
                    unset($attributes['estimated_cost']);
                }

                $variant->update($attributes);
            } else {
                $variant = $product->variants()->create($attributes);
            }

            $keepIds[] = $variant->id;

            // Rebuild the attribute-value links: values is [attribute_id => attribute_value_id].
            $variant->attributeValues()->detach();
            foreach ($row['values'] as $attributeId => $valueId) {
                $variant->attributeValues()->attach($valueId, ['attribute_id' => (int) $attributeId]);
            }
        }

        // Remove variants no longer present.
        $product->variants()->whereNotIn('id', $keepIds)->each(function (ProductVariant $variant) {
            $variant->attributeValues()->detach();
            $variant->delete();
        });
    }

    /**
     * Delete gallery photos the form marked for removal, then append any
     * newly uploaded ones after the current highest sort_order. Only ever
     * populated when the form's e-commerce section is shown.
     */
    protected function syncGalleryImages(Product $product, array $validated): void
    {
        foreach ($validated['remove_gallery_ids'] ?? [] as $imageId) {
            $image = $product->images()->find($imageId);

            if ($image) {
                Storage::disk('public')->delete($image->image_path);
                $image->delete();
            }
        }

        if (! empty($validated['gallery_images'])) {
            $nextOrder = (int) $product->images()->max('sort_order') + 1;

            foreach ($validated['gallery_images'] as $file) {
                $product->images()->create([
                    'image_path' => $file->store('products', 'public'),
                    'sort_order' => $nextOrder++,
                ]);
            }
        }
    }
}
