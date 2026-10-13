@php $editing = isset($product); @endphp

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4"
     x-data="productForm({
        preview: @js($product->image_url ?? null),
        removeImage: false,
        hasVariants: {{ old('has_variants', $product->has_variants ?? false) ? 'true' : 'false' }},
        trackBatch: {{ old('track_batch', $product->track_batch ?? false) ? 'true' : 'false' }},
        trackExpiry: {{ old('track_expiry', $product->track_expiry ?? false) ? 'true' : 'false' }},
        trackSerial: {{ old('track_serial', $product->track_serial ?? false) ? 'true' : 'false' }},
        hasWarranty: {{ old('warranty_period', $product->warranty_period ?? null) ? 'true' : 'false' }},
        hasGuarantee: {{ old('guarantee_period', $product->guarantee_period ?? null) ? 'true' : 'false' }},
     })">
    <!-- Image -->
    <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <div class="mb-4">
            <h3 class="font-bold text-brand-900">{{ __('Product Image') }}</h3>
            <p class="text-xs text-slate-400">{{ __('PNG or JPG, up to 2MB') }}</p>
        </div>

        <div class="flex flex-col items-center gap-4">
            <div class="grid h-28 w-28 place-items-center overflow-hidden rounded-2xl bg-slate-100 ring-1 ring-slate-200">
                <template x-if="preview">
                    <img :src="preview" alt="Product preview" class="h-full w-full object-cover">
                </template>
                <template x-if="!preview">
                    <svg class="h-10 w-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/></svg>
                </template>
            </div>

            <label class="cursor-pointer rounded-lg bg-brand-800/5 px-4 py-2 text-xs font-semibold text-brand-800 hover:bg-brand-800/10">
                {{ __('Choose Image') }}
                <input type="file" name="image" accept="image/*" class="hidden"
                       @change="removeImage = false; const f = $event.target.files[0]; if (f) preview = URL.createObjectURL(f)">
            </label>

            @if ($editing && $product->image_url)
            <label class="flex items-center gap-2 text-xs text-slate-500">
                <input type="checkbox" name="remove_image" value="1" x-model="removeImage"
                       @change="if (removeImage) preview = null; else preview = @js($product->image_url)"
                       class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                {{ __('Remove current image') }}
            </label>
            @endif
        </div>
        <x-input-error class="mt-3" :messages="$errors->get('image')" />
    </div>

    <!-- Basic details -->
    <div class="lg:col-span-2 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-5">
        <div>
            <h3 class="font-bold text-brand-900">{{ __('Basic Details') }}</h3>
            <p class="text-xs text-slate-400">{{ __('Name and identifiers') }}</p>
        </div>

        <div>
            <x-input-label for="name" :value="__('Product Name')" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                          :value="old('name', $product->name ?? '')" required autofocus />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <x-input-label for="sku" :value="__('SKU')" />
                <x-text-input id="sku" name="sku" type="text" class="mt-1 block w-full"
                              :value="old('sku', $product->sku ?? '')" required placeholder="{{ __('e.g. TSHIRT-BLK-M') }}" />
                <x-input-error class="mt-2" :messages="$errors->get('sku')" />
            </div>
            <div>
                <x-input-label for="barcode" :value="__('Barcode (optional)')" />
                <x-text-input id="barcode" name="barcode" type="text" class="mt-1 block w-full"
                              :value="old('barcode', $product->barcode ?? '')" />
                <x-input-error class="mt-2" :messages="$errors->get('barcode')" />
            </div>
        </div>

        <div>
            <x-input-label for="short_description" :value="__('Short Description (optional)')" />
            <p class="mb-1.5 text-[11px] text-slate-400">{{ __('A brief summary — always shown near the product name, with or without the online store. Each line is kept on its own line.') }}</p>
            <textarea id="short_description" name="short_description" rows="4" maxlength="500"
                      class="mt-1 block w-full rounded-lg border-slate-300 focus:border-accent-500 focus:ring-accent-500">{{ old('short_description', $product->short_description ?? '') }}</textarea>
            <x-input-error class="mt-2" :messages="$errors->get('short_description')" />
        </div>
    </div>

    @if ($ecommerceEnabled ?? false)
    <!-- Product Gallery -->
    <div class="lg:col-span-3 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200"
         x-data="productGallery({
            existing: @js(($product->images ?? collect())->map(fn ($img) => ['id' => $img->id, 'url' => $img->url])),
         })">
        <div class="mb-4">
            <h3 class="font-bold text-brand-900">{{ __('Product Gallery') }}</h3>
            <p class="text-xs text-slate-400">{{ __('Extra photos shown on the online store\'s product page, in addition to the main Product Image above. PNG or JPG, up to 2MB each.') }}</p>
        </div>

        <div class="flex flex-wrap gap-3">
            <template x-for="img in existing" :key="'existing-'+img.id">
                <div class="relative h-24 w-24 shrink-0 overflow-hidden rounded-xl ring-1 ring-slate-200">
                    <img :src="img.url" alt="" class="h-full w-full object-cover">
                    <button type="button" @click="removeExisting(img.id)"
                            class="absolute right-1 top-1 grid h-5 w-5 place-items-center rounded-full bg-white/90 text-rose-600 shadow hover:bg-white">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </template>

            <template x-for="id in removeIds" :key="'remove-'+id">
                <input type="hidden" name="remove_gallery_ids[]" :value="id">
            </template>

            <template x-for="(item, i) in newFiles" :key="'new-'+i">
                <div class="relative h-24 w-24 shrink-0 overflow-hidden rounded-xl ring-1 ring-slate-200">
                    <img :src="item.url" alt="" class="h-full w-full object-cover">
                    <button type="button" @click="removeNew(i)"
                            class="absolute right-1 top-1 grid h-5 w-5 place-items-center rounded-full bg-white/90 text-rose-600 shadow hover:bg-white">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </template>

            <label class="grid h-24 w-24 shrink-0 cursor-pointer place-items-center rounded-xl border border-dashed border-slate-300 text-slate-400 hover:bg-slate-50">
                <span class="text-center text-[11px] font-semibold leading-tight">{{ __('Add Photos') }}</span>
                <input type="file" name="gallery_images[]" x-ref="galleryInput" accept="image/*" multiple class="hidden" @change="addFiles($event.target.files)">
            </label>
        </div>
        <x-input-error class="mt-3" :messages="$errors->get('gallery_images')" />
        <x-input-error class="mt-1" :messages="$errors->get('gallery_images.*')" />
    </div>
    @endif

    <!-- Classification -->
    <div class="lg:col-span-3 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <div class="mb-4">
            <h3 class="font-bold text-brand-900">{{ __('Classification') }}</h3>
            <p class="text-xs text-slate-400">{{ __('Product type, category and brand') }}</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <x-input-label for="product_type" :value="__('Product Type')" />
                <select id="product_type" name="product_type" required
                        class="mt-1 block w-full rounded-lg border-slate-300 focus:border-accent-500 focus:ring-accent-500">
                    @foreach (\App\Models\Product::TYPES as $typeKey => $typeLabel)
                        <option value="{{ $typeKey }}" @selected(old('product_type', $product->product_type ?? 'direct_purchase') === $typeKey)>{{ __($typeLabel) }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-[11px] text-slate-400">{{ __('Manufactured products are produced at the factory against a BOM; direct-purchase products are bought ready-made; raw materials are consumed by a BOM.') }}</p>
                <x-input-error class="mt-2" :messages="$errors->get('product_type')" />
            </div>
            <div>
                <x-input-label for="category_id" :value="__('Category')" />
                <select id="category_id" name="category_id" required
                        class="mt-1 block w-full rounded-lg border-slate-300 focus:border-accent-500 focus:ring-accent-500">
                    <option value="">{{ __('Select category') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(old('category_id', $product->category_id ?? '') == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
                <x-input-error class="mt-2" :messages="$errors->get('category_id')" />
                @if ($categories->isEmpty())
                    <p class="mt-1 text-xs text-amber-600">{{ __('No categories yet —') }} <a href="{{ route('categories.create') }}" class="underline">{{ __('add one first') }}</a>.</p>
                @endif
            </div>

            <div>
                <x-input-label for="brand_id" :value="__('Brand (optional)')" />
                <select id="brand_id" name="brand_id"
                        class="mt-1 block w-full rounded-lg border-slate-300 focus:border-accent-500 focus:ring-accent-500">
                    <option value="">{{ __('No brand') }}</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}" @selected(old('brand_id', $product->brand_id ?? '') == $brand->id)>{{ $brand->name }}</option>
                    @endforeach
                </select>
                <x-input-error class="mt-2" :messages="$errors->get('brand_id')" />
            </div>
        </div>
    </div>

    <!-- Units -->
    <div class="lg:col-span-3 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <div class="mb-4">
            <h3 class="font-bold text-brand-900">{{ __('Units') }}</h3>
            <p class="text-xs text-slate-400">{{ __('Stock is always tracked in the Stock Unit. Purchase and Sale units are optional — leave blank to use the Stock Unit directly.') }}</p>
        </div>

        @if ($units->isEmpty())
            <p class="text-sm text-amber-600">{{ __('No units yet —') }} <a href="{{ route('units.create') }}" class="underline font-semibold">{{ __('add one first') }}</a>.</p>
        @else
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <x-input-label for="stock_unit_id" :value="__('Stock Unit')" />
                <select id="stock_unit_id" name="stock_unit_id" required
                        class="mt-1 block w-full rounded-lg border-slate-300 focus:border-accent-500 focus:ring-accent-500">
                    <option value="">{{ __('Select unit') }}</option>
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}" @selected(old('stock_unit_id', $product->stock_unit_id ?? '') == $unit->id)>{{ $unit->name }} ({{ $unit->short_name }})</option>
                    @endforeach
                </select>
                <x-input-error class="mt-2" :messages="$errors->get('stock_unit_id')" />
            </div>

            <div>
                <x-input-label for="purchase_unit_id" :value="__('Purchase Unit (optional)')" />
                <select id="purchase_unit_id" name="purchase_unit_id"
                        class="mt-1 block w-full rounded-lg border-slate-300 focus:border-accent-500 focus:ring-accent-500">
                    <option value="">{{ __('Same as Stock Unit') }}</option>
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}" @selected(old('purchase_unit_id', $product->purchase_unit_id ?? '') == $unit->id)>{{ $unit->name }} ({{ $unit->short_name }})</option>
                    @endforeach
                </select>
                <x-input-error class="mt-2" :messages="$errors->get('purchase_unit_id')" />

                <div class="mt-2">
                    <x-input-label for="purchase_unit_conversion" :value="__('1 Purchase Unit =')" class="text-xs" />
                    <div class="mt-1 flex items-center gap-2">
                        <x-text-input id="purchase_unit_conversion" name="purchase_unit_conversion" type="number" step="0.0001" min="0.0001" class="block w-full"
                                      :value="old('purchase_unit_conversion', $product->purchase_unit_conversion ?? '1')" required />
                        <span class="text-xs text-slate-400 shrink-0">{{ __('Stock Unit(s)') }}</span>
                    </div>
                    <p class="mt-1 text-[11px] text-slate-400">{{ __('e.g. 1 Box = 12 Pcs') }}</p>
                    <x-input-error class="mt-1" :messages="$errors->get('purchase_unit_conversion')" />
                </div>
            </div>

            <div>
                <x-input-label for="sale_unit_id" :value="__('Sale Unit (optional)')" />
                <select id="sale_unit_id" name="sale_unit_id"
                        class="mt-1 block w-full rounded-lg border-slate-300 focus:border-accent-500 focus:ring-accent-500">
                    <option value="">{{ __('Same as Stock Unit') }}</option>
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}" @selected(old('sale_unit_id', $product->sale_unit_id ?? '') == $unit->id)>{{ $unit->name }} ({{ $unit->short_name }})</option>
                    @endforeach
                </select>
                <x-input-error class="mt-2" :messages="$errors->get('sale_unit_id')" />

                <div class="mt-2">
                    <x-input-label for="sale_unit_conversion" :value="__('1 Sale Unit =')" class="text-xs" />
                    <div class="mt-1 flex items-center gap-2">
                        <x-text-input id="sale_unit_conversion" name="sale_unit_conversion" type="number" step="0.0001" min="0.0001" class="block w-full"
                                      :value="old('sale_unit_conversion', $product->sale_unit_conversion ?? '1')" required />
                        <span class="text-xs text-slate-400 shrink-0">{{ __('Stock Unit(s)') }}</span>
                    </div>
                    <p class="mt-1 text-[11px] text-slate-400">{{ __('e.g. 1 Dozen = 12 Pcs') }}</p>
                    <x-input-error class="mt-1" :messages="$errors->get('sale_unit_conversion')" />
                </div>
            </div>
        </div>
        @endif
    </div>

    <!-- Pricing -->
    <div class="lg:col-span-3 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <div class="mb-4">
            <h3 class="font-bold text-brand-900">{{ __('Pricing & Stock Alert') }}</h3>
            <p class="text-xs text-slate-400">{{ __('Estimated cost is a starting reference — actual cost will come from Purchase/Production once those are tracked') }}</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <x-input-label for="estimated_cost" :value="__('Estimated Cost')" />
                <x-text-input id="estimated_cost" name="estimated_cost" type="number" step="0.01" min="0"
                              :readonly="$hasCostHistory ?? false"
                              class="mt-1 block w-full {{ ($hasCostHistory ?? false) ? 'bg-slate-50 text-slate-500 cursor-not-allowed' : '' }}"
                              :value="old('estimated_cost', $product->estimated_cost ?? '0')" required />
                @if ($hasCostHistory ?? false)
                    <p class="mt-1 text-[11px] text-amber-600">{{ __('Auto-calculated from stock movement history — correct it via Stock Adjustment.') }}</p>
                @else
                    <p class="mt-1 text-[11px] text-slate-400">{{ __('Reference only, per Stock Unit — not the source of truth once purchases exist') }}</p>
                @endif
                <x-input-error class="mt-2" :messages="$errors->get('estimated_cost')" />
            </div>

            <div>
                <x-input-label for="selling_price" :value="__('Selling Price')" />
                <x-text-input id="selling_price" name="selling_price" type="number" step="0.01" min="0" class="mt-1 block w-full"
                              :value="old('selling_price', $product->selling_price ?? '0')" required />
                <p class="mt-1 text-[11px] text-slate-400">{{ __('Per Sale Unit') }}</p>
                <x-input-error class="mt-2" :messages="$errors->get('selling_price')" />
            </div>

            <div>
                <x-input-label for="reorder_level" :value="__('Reorder Level')" />
                <x-text-input id="reorder_level" name="reorder_level" type="number" min="0" class="mt-1 block w-full"
                              :value="old('reorder_level', $product->reorder_level ?? '0')" required />
                <p class="mt-1 text-[11px] text-slate-400">{{ __('Low-stock alert threshold, in Stock Units (used once stock tracking is added)') }}</p>
                <x-input-error class="mt-2" :messages="$errors->get('reorder_level')" />
            </div>

        </div>
    </div>

    <!-- Product Type & Features -->
    <div class="lg:col-span-3 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <div class="mb-4">
            <h3 class="font-bold text-brand-900">{{ __('Product Type & Features') }}</h3>
            <p class="text-xs text-slate-400">{{ __('Enable only what this product needs. All off by default.') }}</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <!-- Variants toggle -->
            <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                <input type="checkbox" name="has_variants" value="1" x-model="hasVariants"
                       class="mt-0.5 rounded border-slate-300 text-accent-600 focus:ring-accent-500">
                <span>
                    <span class="block text-sm font-semibold text-brand-900">{{ __('Has variants') }}</span>
                    <span class="block text-xs text-slate-400">{{ __('Different Color/Size combinations, each with its own price') }}</span>
                </span>
            </label>

            <!-- Serial / IMEI -->
            <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                <input type="checkbox" name="track_serial" value="1" x-model="trackSerial"
                       class="mt-0.5 rounded border-slate-300 text-accent-600 focus:ring-accent-500">
                <span>
                    <span class="block text-sm font-semibold text-brand-900">{{ __('Track serial / IMEI') }}</span>
                    <span class="block text-xs text-slate-400">{{ __('Unique number per unit — captured during purchase (coming soon)') }}</span>
                </span>
            </label>

            <!-- Batch -->
            <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                <input type="checkbox" name="track_batch" value="1" x-model="trackBatch" :disabled="trackExpiry"
                       class="mt-0.5 rounded border-slate-300 text-accent-600 focus:ring-accent-500 disabled:opacity-60">
                <span>
                    <span class="block text-sm font-semibold text-brand-900">{{ __('Track batches') }}</span>
                    <span class="block text-xs text-slate-400" x-text="trackExpiry ? @js(__('Required by expiry tracking')) : @js(__('Grouped by batch during purchase (coming soon)'))"></span>
                </span>
            </label>

            <!-- Expiry -->
            <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                <input type="checkbox" name="track_expiry" value="1" x-model="trackExpiry"
                       @change="if (trackExpiry) trackBatch = true"
                       class="mt-0.5 rounded border-slate-300 text-accent-600 focus:ring-accent-500">
                <span>
                    <span class="block text-sm font-semibold text-brand-900">{{ __('Track expiry date') }}</span>
                    <span class="block text-xs text-slate-400">{{ __('Perishable goods — enables batch tracking') }}</span>
                </span>
            </label>

            <!-- Warranty -->
            <div class="rounded-lg border border-slate-200 p-3">
                <label class="flex items-start gap-3">
                    <input type="checkbox" x-model="hasWarranty"
                           @change="if (!hasWarranty) { $refs.warrantyPeriod.value = ''; }"
                           class="mt-0.5 rounded border-slate-300 text-accent-600 focus:ring-accent-500">
                    <span>
                        <span class="block text-sm font-semibold text-brand-900">{{ __('Has warranty') }}</span>
                        <span class="block text-xs text-slate-400">{{ __('Repair coverage duration') }}</span>
                    </span>
                </label>
                <div class="mt-3 flex items-center gap-2" x-show="hasWarranty" x-cloak>
                    <x-text-input x-ref="warrantyPeriod" name="warranty_period" type="number" min="1" class="block w-24"
                                  :value="old('warranty_period', $product->warranty_period ?? '')" placeholder="12" />
                    <select name="warranty_unit" class="rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                        @foreach (['days' => __('Days'), 'months' => __('Months'), 'years' => __('Years')] as $val => $lbl)
                            <option value="{{ $val }}" @selected(old('warranty_unit', $product->warranty_unit ?? 'months') === $val)>{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
                <x-input-error class="mt-1" :messages="$errors->get('warranty_period')" />
                <x-input-error class="mt-1" :messages="$errors->get('warranty_unit')" />
            </div>

            <!-- Guarantee -->
            <div class="rounded-lg border border-slate-200 p-3">
                <label class="flex items-start gap-3">
                    <input type="checkbox" x-model="hasGuarantee"
                           @change="if (!hasGuarantee) { $refs.guaranteePeriod.value = ''; }"
                           class="mt-0.5 rounded border-slate-300 text-accent-600 focus:ring-accent-500">
                    <span>
                        <span class="block text-sm font-semibold text-brand-900">{{ __('Has guarantee') }}</span>
                        <span class="block text-xs text-slate-400">{{ __('Replacement coverage duration') }}</span>
                    </span>
                </label>
                <div class="mt-3 flex items-center gap-2" x-show="hasGuarantee" x-cloak>
                    <x-text-input x-ref="guaranteePeriod" name="guarantee_period" type="number" min="1" class="block w-24"
                                  :value="old('guarantee_period', $product->guarantee_period ?? '')" placeholder="6" />
                    <select name="guarantee_unit" class="rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                        @foreach (['days' => __('Days'), 'months' => __('Months'), 'years' => __('Years')] as $val => $lbl)
                            <option value="{{ $val }}" @selected(old('guarantee_unit', $product->guarantee_unit ?? 'months') === $val)>{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
                <x-input-error class="mt-1" :messages="$errors->get('guarantee_period')" />
                <x-input-error class="mt-1" :messages="$errors->get('guarantee_unit')" />
            </div>
        </div>

        @include('admin.products._variants')
    </div>

    @if ($ecommerceEnabled ?? false)
    <!-- Long Description -->
    <div class="lg:col-span-3 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <div class="mb-4">
            <h3 class="font-bold text-brand-900">{{ __('Long Description') }}</h3>
            <p class="text-xs text-slate-400">{{ __('Shown in the Description section of the product\'s online store page — formatting (bold, lists, headings) is preserved') }}</p>
        </div>

        <input id="description" type="hidden" name="description" value="{{ old('description', $product->description ?? '') }}">
        <trix-editor input="description" class="block w-full"></trix-editor>
        <x-input-error class="mt-2" :messages="$errors->get('description')" />
    </div>
    @endif
</div>

@if (($product ?? null) && $product->has_variants)
@php
    $selectedValues = $product->variants
        ->flatMap->attributeValues
        ->groupBy(fn ($av) => $av->pivot->attribute_id)
        ->map(fn ($group) => $group->mapWithKeys(fn ($av) => [$av->id => $av->value]));

    $existingVariants = $product->variants->map(fn ($v) => [
        'key' => $v->attributeValues->map(fn ($av) => $av->pivot->attribute_id.':'.$av->id)->sort()->implode('|'),
        'id' => $v->id,
        'label' => $v->label,
        'values' => $v->attributeValues->mapWithKeys(fn ($av) => [$av->pivot->attribute_id => $av->id]),
        'sku' => $v->sku,
        'barcode' => $v->barcode,
        'selling_price' => $v->selling_price,
        'estimated_cost' => $v->estimated_cost,
        'has_cost_history' => $v->stockMovements()->exists(),
        'status' => (bool) $v->status,
    ]);
@endphp
<script>
    window.__selectedValues = @json($selectedValues);
    window.__existingVariants = @json($existingVariants);
</script>
@endif

<div class="mt-5 flex items-center gap-3">
    <x-primary-button>{{ $editing ? __('Update Product') : __('Create Product') }}</x-primary-button>
    <a href="{{ route('products.index') }}" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50">{{ __('Cancel') }}</a>
</div>
