{{-- Shared by create/edit. $order is only set when editing. --}}
@php
    $editing = isset($order);
    // Re-hydrate the cart from the submitted rows after a validation error,
    // otherwise from the order being edited (or nothing, for a new order).
    $cartItems = collect(old('items', []))
        ->map(fn ($row) => ($opt = $itemOptions->firstWhere('id', $row['item'] ?? null))
            ? array_merge($opt, collect(['quantity', 'unit_price', 'style', 'color', 'length_per_unit', 'remarks'])
                ->mapWithKeys(fn ($field) => [$field => $row[$field] ?? ''])->all())
            : null)
        ->filter()->values();
    if ($cartItems->isEmpty()) {
        $cartItems = $items;
    }
    // One look for every control on the page, so text inputs, dates and the
    // customer picker line up at the same height.
    $field = 'block w-full rounded-lg border-slate-300 py-2 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500';
    $cell = 'block w-full rounded-md border-slate-300 py-1.5 text-sm focus:border-accent-500 focus:ring-accent-500';
@endphp

<div x-data="saleCart(@js(['itemOptions' => $itemOptions, 'items' => $cartItems, 'discount' => old('discount', $editing ? (float) $order->discount_amount : '')]))">
    <form method="POST" action="{{ $editing ? route('sales-orders.update', $order) : route('sales-orders.store') }}" class="space-y-4">
        @csrf
        @if ($editing) @method('PUT') @endif

        <!-- Buyer & dates -->
        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="px-6 pt-5 pb-4">
                <h3 class="font-bold text-brand-900">{{ __('Buyer & Order Details') }}</h3>
                <p class="text-xs text-slate-400">{{ __('Who the order is from and when they need it.') }}</p>
            </div>

            <div class="grid grid-cols-1 gap-4 px-6 pb-5 md:grid-cols-2 xl:grid-cols-3">
                <div>
                    <div class="flex h-5 items-center justify-between gap-2">
                        <x-input-label for="party_id" :value="__('Customer')" />
                        <x-quick-add-party role="customer" />
                    </div>
                    <div class="mt-1">
                        <x-searchable-select name="party_id" :options="$customers" :selected="old('party_id', $order->party_id ?? null)"
                                              placeholder="{{ __('Select a customer…') }}" />
                    </div>
                    <x-input-error class="mt-2" :messages="$errors->get('party_id')" />
                    @if ($customers->isEmpty())
                        <p class="mt-1 text-xs text-amber-600">{{ __('No customers yet — use “New Customer” to add one.') }}</p>
                    @endif
                </div>

                <div>
                    <x-input-label for="buyer_name" :value="__('Buyer / Brand')" class="flex h-5 items-center" />
                    <input id="buyer_name" name="buyer_name" type="text" class="mt-1 {{ $field }}"
                           value="{{ old('buyer_name', $order->buyer_name ?? '') }}" placeholder="{{ __('e.g. BHPC & HENRY COTTON') }}">
                    <x-input-error class="mt-2" :messages="$errors->get('buyer_name')" />
                </div>

                <div>
                    <x-input-label for="buyer_reference" :value="__('Customer Order No.')" class="flex h-5 items-center" />
                    <input id="buyer_reference" name="buyer_reference" type="text" class="mt-1 {{ $field }}"
                           value="{{ old('buyer_reference', $order->buyer_reference ?? '') }}" placeholder="{{ __('As printed on their order') }}">
                    <x-input-error class="mt-2" :messages="$errors->get('buyer_reference')" />
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 rounded-b-2xl border-t border-slate-100 bg-slate-50/60 px-6 py-5 md:grid-cols-3">
                <div>
                    <x-input-label for="order_date" :value="__('Order Date')" />
                    <input id="order_date" name="order_date" type="date" class="mt-1 {{ $field }}" required
                           value="{{ old('order_date', $editing ? $order->order_date->toDateString() : now()->toDateString()) }}">
                    <x-input-error class="mt-2" :messages="$errors->get('order_date')" />
                </div>

                <div>
                    <x-input-label for="delivery_start_date" :value="__('Delivery Starts')" />
                    <input id="delivery_start_date" name="delivery_start_date" type="date" class="mt-1 {{ $field }}"
                           value="{{ old('delivery_start_date', $editing ? $order->delivery_start_date?->toDateString() : '') }}">
                    <x-input-error class="mt-2" :messages="$errors->get('delivery_start_date')" />
                </div>

                <div>
                    <x-input-label for="delivery_date" :value="__('Delivery Closes')" />
                    <input id="delivery_date" name="delivery_date" type="date" class="mt-1 {{ $field }}"
                           value="{{ old('delivery_date', $editing ? $order->delivery_date?->toDateString() : '') }}">
                    <x-input-error class="mt-2" :messages="$errors->get('delivery_date')" />
                </div>
            </div>
        </div>

        <!-- Products: picker + lines + totals -->
        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-wrap items-end justify-between gap-4 px-6 pt-5 pb-4">
                <div>
                    <h3 class="font-bold text-brand-900">{{ __('Products') }}</h3>
                    <p class="text-xs text-slate-400">{{ __('Finished products only — manufactured or bought in. Unit price is optional; leave it blank to set it on the Proforma Invoice.') }}</p>
                </div>

                <div class="relative w-full sm:w-96" @click.outside="open = false">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
                    <input type="text" x-model="query" @focus="open = true" @keydown.escape="open = false"
                           placeholder="{{ __('Add a product — search by name or code') }}"
                           class="{{ $field }} pl-9">
                    <div x-show="open" x-cloak x-transition
                         class="absolute right-0 z-20 mt-1 w-full max-h-72 overflow-y-auto rounded-xl bg-white py-1.5 shadow-lg ring-1 ring-slate-200">
                        <template x-for="opt in filtered" :key="opt.id">
                            <div @click="addItem(opt)"
                                 class="flex cursor-pointer items-center justify-between gap-3 px-4 py-2 text-sm hover:bg-slate-50">
                                <span x-text="opt.name" class="text-slate-700"></span>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                      :class="opt.type === 'manufactured' ? 'bg-indigo-50 text-indigo-600' : 'bg-sky-50 text-sky-600'"
                                      x-text="opt.type === 'manufactured' ? '{{ __('Manufactured') }}' : '{{ __('Direct Purchase') }}'"></span>
                            </div>
                        </template>
                        <div x-show="filtered.length === 0" class="px-4 py-2 text-sm text-slate-400">{{ __('No matching products.') }}</div>
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div x-show="! items.length" class="px-6 pb-6">
            <div class="rounded-xl border-2 border-dashed border-slate-200 px-6 py-10 text-center">
                <svg class="mx-auto h-8 w-8 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/></svg>
                <p class="mt-2 text-sm font-semibold text-slate-600">{{ __('No products on this order yet') }}</p>
                <p class="text-xs text-slate-400">{{ __('Search above to add the first line. The same product can go on several lines with a different style or color.') }}</p>
            </div>
            </div>

            <div x-show="items.length" x-cloak>
                <div class="overflow-x-auto border-y border-slate-100">
                <table class="w-full min-w-[1120px] text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-left text-xs font-semibold text-slate-500">
                            <th class="w-10 py-2.5 pl-6 pr-2">#</th>
                            <th class="px-2 py-2.5">{{ __('Product') }}</th>
                            <th class="w-32 px-2 py-2.5">{{ __('Style') }}</th>
                            <th class="w-28 px-2 py-2.5">{{ __('Color') }}</th>
                            <th class="w-28 px-2 py-2.5">{{ __('Quantity') }}</th>
                            <th class="w-28 px-2 py-2.5">{{ __('Length/unit (m)') }}</th>
                            <th class="w-32 px-2 py-2.5">{{ __('Unit price (USD)') }} <span class="font-normal text-slate-400">{{ __('optional') }}</span></th>
                            <th class="w-28 px-2 py-2.5 text-right">{{ __('Subtotal') }}</th>
                            <th class="w-44 px-2 py-2.5">{{ __('Remarks') }}</th>
                            <th class="w-12 py-2.5 pl-2 pr-6"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="(item, i) in items" :key="item.uid">
                            <tr class="align-top hover:bg-slate-50/70">
                                <td class="py-3 pl-6 pr-2 pt-4 text-xs font-semibold text-slate-400" x-text="i + 1"></td>
                                <td class="px-2 py-3 pt-3.5">
                                    <input type="hidden" :name="'items[' + i + '][item]'" :value="item.id">
                                    <span class="font-semibold text-slate-800" x-text="item.name"></span>
                                    <span class="block text-xs text-slate-400" x-text="item.unit || ''"></span>
                                </td>
                                <td class="px-2 py-3">
                                    <input type="text" maxlength="100" :name="'items[' + i + '][style]'" x-model="item.style" class="{{ $cell }}">
                                </td>
                                <td class="px-2 py-3">
                                    <input type="text" maxlength="100" :name="'items[' + i + '][color]'" x-model="item.color" class="{{ $cell }}">
                                </td>
                                <td class="px-2 py-3">
                                    <input type="number" step="0.0001" min="0.0001" :name="'items[' + i + '][quantity]'" x-model="item.quantity"
                                           class="{{ $cell }}" placeholder="0" required>
                                </td>
                                <td class="px-2 py-3">
                                    <input type="number" step="0.01" min="0" :name="'items[' + i + '][length_per_unit]'" x-model="item.length_per_unit"
                                           class="{{ $cell }}">
                                </td>
                                <td class="px-2 py-3">
                                    <input type="number" step="0.0001" min="0" :name="'items[' + i + '][unit_price]'" x-model="item.unit_price"
                                           class="{{ $cell }}" placeholder="{{ __('Set on PI') }}">
                                </td>
                                <td class="px-2 py-3 pt-4 text-right font-semibold tabular-nums text-slate-800"
                                    x-text="item.unit_price === '' || item.unit_price === null ? '—' : (Math.round((Number(item.quantity) || 0) * (Number(item.unit_price) || 0) * 100) / 100).toFixed(2)"></td>
                                <td class="px-2 py-3">
                                    <input type="text" maxlength="255" :name="'items[' + i + '][remarks]'" x-model="item.remarks" class="{{ $cell }}">
                                </td>
                                <td class="py-3 pl-2 pr-6 pt-3.5 text-right">
                                    <button type="button" @click="removeItem(item.uid)" class="rounded-md p-1 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="{{ __('Remove line') }}">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>

                <!-- Totals -->
                <div class="flex justify-end px-6 py-5">
                    <dl class="w-full max-w-xs space-y-2 text-sm">
                        <div class="flex items-center justify-between">
                            <dt class="text-slate-500">{{ __('Subtotal') }} <span class="text-xs text-slate-400" x-text="'(' + items.length + ' ' + (items.length === 1 ? '{{ __('line') }}' : '{{ __('lines') }}') + ')'"></span></dt>
                            <dd class="font-semibold tabular-nums text-slate-800" x-text="subtotal.toFixed(2)"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt><label for="discount" class="text-slate-500">{{ __('Discount') }}</label></dt>
                            <dd>
                                <input id="discount" type="number" step="0.01" min="0" name="discount" x-model="discount"
                                       class="w-32 rounded-md border-slate-300 py-1.5 text-right text-sm tabular-nums focus:border-accent-500 focus:ring-accent-500" placeholder="0.00">
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between border-t border-slate-200 pt-3">
                            <dt class="font-semibold text-brand-900">{{ __('Total') }}</dt>
                            <dd class="text-lg font-bold tabular-nums text-brand-900" x-text="'USD ' + total.toFixed(2)"></dd>
                        </div>
                    </dl>
                </div>
            </div>

            @if ($errors->has('items') || $errors->has('discount') || $errors->has('items.*'))
            <div class="border-t border-slate-100 px-6 py-3">
                <x-input-error class="mb-1" :messages="$errors->get('items')" />
                <x-input-error class="mb-1" :messages="$errors->get('discount')" />
                @foreach ($errors->get('items.*') as $messages)
                    <x-input-error class="mb-1" :messages="$messages" />
                @endforeach
            </div>
            @endif
        </div>

        <!-- Terms, note, save -->
        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="grid grid-cols-1 gap-4 px-6 py-5 md:grid-cols-2">
                <div>
                    <x-input-label for="delivery_terms" :value="__('Delivery Terms (optional)')" />
                    <textarea id="delivery_terms" name="delivery_terms" rows="3" class="mt-1 {{ $field }}">{{ old('delivery_terms', $order->delivery_terms ?? '') }}</textarea>
                    <x-input-error class="mt-2" :messages="$errors->get('delivery_terms')" />
                </div>
                <div>
                    <x-input-label for="note" :value="__('Note (optional)')" />
                    <textarea id="note" name="note" rows="3" class="mt-1 {{ $field }}">{{ old('note', $order->note ?? '') }}</textarea>
                    <x-input-error class="mt-2" :messages="$errors->get('note')" />
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-3 rounded-b-2xl border-t border-slate-100 bg-slate-50/60 px-6 py-4">
                <p x-show="! items.length" class="mr-auto text-xs text-slate-400">{{ __('Add at least one product to save the order.') }}</p>
                <a href="{{ $editing ? route('sales-orders.show', $order) : route('sales-orders.index') }}"
                   class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">{{ __('Cancel') }}</a>
                <button type="submit" :disabled="items.length === 0"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-accent-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    {{ $editing ? __('Update Sales Order') : __('Save Sales Order') }}
                </button>
            </div>
        </div>
    </form>
</div>
