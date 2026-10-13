{{-- Shared by create/edit. $order is only set when editing. --}}
@php
    $editing = isset($order);
    // Re-hydrate the cart from the submitted rows after a validation error,
    // otherwise from the order being edited (or nothing, for a new order).
    $cartItems = collect(old('items', []))
        ->map(fn ($row) => ($opt = $itemOptions->firstWhere('id', $row['item'] ?? null))
            ? array_merge($opt, ['quantity' => $row['quantity'] ?? '', 'unit_price' => $row['unit_price'] ?? ''])
            : null)
        ->filter()->values();
    if ($cartItems->isEmpty()) {
        $cartItems = $items;
    }
@endphp

<div x-data="saleCart(@js(['itemOptions' => $itemOptions, 'items' => $cartItems, 'discount' => old('discount', $editing ? (float) $order->discount_amount : '')]))">
    <form method="POST" action="{{ $editing ? route('sales-orders.update', $order) : route('sales-orders.store') }}">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 mb-4">
            <div class="mb-4">
                <h3 class="font-bold text-brand-900">{{ __('Buyer & Order Details') }}</h3>
                <p class="text-xs text-slate-400">{{ __('Who the order is from and when they need it.') }}</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <x-input-label for="party_id" :value="__('Buyer (Customer)')" />
                    <div class="mt-1">
                        <x-searchable-select name="party_id" :options="$customers" :selected="old('party_id', $order->party_id ?? null)"
                                              placeholder="{{ __('Select a buyer…') }}" />
                    </div>
                    <x-input-error class="mt-2" :messages="$errors->get('party_id')" />
                    @if ($customers->isEmpty())
                        <p class="mt-1 text-xs text-amber-600">{{ __('No customers yet —') }} <a href="{{ route('parties.create') }}" class="underline">{{ __('add one first') }}</a>.</p>
                    @endif
                </div>

                @if ($salesPeople->isNotEmpty())
                <div>
                    <x-input-label for="sales_person_id" :value="__('Responsible Sales Person')" />
                    <select id="sales_person_id" name="sales_person_id"
                            class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                        <option value="">{{ __('— None —') }}</option>
                        @foreach ($salesPeople as $person)
                            <option value="{{ $person->id }}" @selected(old('sales_person_id', $order->sales_person_id ?? null) == $person->id)>{{ $person->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-2" :messages="$errors->get('sales_person_id')" />
                </div>
                @endif

                <div>
                    <x-input-label for="buyer_reference" :value="__('Buyer Reference / PO No. (optional)')" />
                    <x-text-input id="buyer_reference" name="buyer_reference" type="text" class="mt-1 block w-full"
                                  :value="old('buyer_reference', $order->buyer_reference ?? '')" />
                    <x-input-error class="mt-2" :messages="$errors->get('buyer_reference')" />
                </div>

                <div>
                    <x-input-label for="order_date" :value="__('Order Date')" />
                    <x-text-input id="order_date" name="order_date" type="date" class="mt-1 block w-full"
                                  :value="old('order_date', $editing ? $order->order_date->toDateString() : now()->toDateString())" required />
                    <x-input-error class="mt-2" :messages="$errors->get('order_date')" />
                </div>

                <div>
                    <x-input-label for="delivery_date" :value="__('Requested Delivery Date (optional)')" />
                    <x-text-input id="delivery_date" name="delivery_date" type="date" class="mt-1 block w-full"
                                  :value="old('delivery_date', $editing ? $order->delivery_date?->toDateString() : '')" />
                    <x-input-error class="mt-2" :messages="$errors->get('delivery_date')" />
                </div>
            </div>
        </div>

        <!-- Item picker -->
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-4">
            <x-input-label :value="__('Add Product')" />
            <div class="relative mt-1 max-w-md" @click.outside="open = false">
                <input type="text" x-model="query" @focus="open = true"
                       placeholder="{{ __('Search a product or variant…') }}"
                       class="block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                <div x-show="open" x-cloak x-transition
                     class="absolute z-20 mt-1 w-full max-h-64 overflow-y-auto rounded-xl bg-white py-1.5 shadow-lg ring-1 ring-slate-200">
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
            <p class="mt-2 text-xs text-slate-400">{{ __('Manufactured and direct-purchase finished products can be ordered — raw materials cannot.') }}</p>
        </div>

        <!-- Cart -->
        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4" x-show="items.length" x-cloak>
            <div class="overflow-x-auto">
            <table class="w-full min-w-[820px] text-sm">
                <thead>
                    <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3 font-semibold">{{ __('Product') }}</th>
                        <th class="px-5 py-3 font-semibold w-36">{{ __('Quantity') }}</th>
                        <th class="px-5 py-3 font-semibold w-36">{{ __('Unit Price') }}</th>
                        <th class="px-5 py-3 font-semibold w-36 text-right">{{ __('Subtotal') }}</th>
                        <th class="px-5 py-3 font-semibold w-12"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="(item, i) in items" :key="item.id">
                        <tr class="hover:bg-slate-50">
                            <td class="px-5 py-3">
                                <input type="hidden" :name="'items[' + i + '][item]'" :value="item.id">
                                <span class="font-semibold text-slate-800" x-text="item.name"></span>
                                <span class="block text-xs text-slate-400" x-text="item.unit || ''"></span>
                            </td>
                            <td class="px-5 py-3">
                                <input type="number" step="0.0001" min="0.0001" :name="'items[' + i + '][quantity]'" x-model="item.quantity"
                                       class="w-32 rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500" placeholder="0" required>
                            </td>
                            <td class="px-5 py-3">
                                <input type="number" step="0.01" min="0" :name="'items[' + i + '][unit_price]'" x-model="item.unit_price"
                                       class="w-32 rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500" placeholder="0.00" required>
                            </td>
                            <td class="px-5 py-3 text-right font-semibold text-slate-800"
                                x-text="((Number(item.quantity) || 0) * (Number(item.unit_price) || 0)).toFixed(2)"></td>
                            <td class="px-5 py-3 text-right">
                                <button type="button" @click="removeItem(item.id)" class="text-slate-400 hover:text-rose-600" title="{{ __('Remove') }}">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot class="border-t border-slate-200 bg-slate-50 text-sm">
                    <tr>
                        <td colspan="3" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Subtotal') }}</td>
                        <td class="px-5 py-2 text-right font-semibold text-slate-800" x-text="subtotal.toFixed(2)"></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="3" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Discount') }}</td>
                        <td class="px-5 py-2 text-right">
                            <input type="number" step="0.01" min="0" name="discount" x-model="discount"
                                   class="w-32 rounded-lg border-slate-300 text-right text-sm focus:border-accent-500 focus:ring-accent-500" placeholder="0.00">
                        </td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="3" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total') }}</td>
                        <td class="px-5 py-2 text-right text-base font-bold text-brand-900" x-text="total.toFixed(2)"></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>
        <x-input-error class="mb-2" :messages="$errors->get('items')" />
        <x-input-error class="mb-2" :messages="$errors->get('discount')" />
        @foreach ($errors->get('items.*') as $messages)
            <x-input-error class="mb-1" :messages="$messages" />
        @endforeach

        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                <div>
                    <x-input-label for="delivery_terms" :value="__('Delivery Terms (optional)')" />
                    <textarea id="delivery_terms" name="delivery_terms" rows="3"
                              class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">{{ old('delivery_terms', $order->delivery_terms ?? '') }}</textarea>
                    <x-input-error class="mt-2" :messages="$errors->get('delivery_terms')" />
                </div>
                <div>
                    <x-input-label for="note" :value="__('Note (optional)')" />
                    <textarea id="note" name="note" rows="3"
                              class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">{{ old('note', $order->note ?? '') }}</textarea>
                    <x-input-error class="mt-2" :messages="$errors->get('note')" />
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" :disabled="items.length === 0"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-accent-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    {{ $editing ? __('Update Sales Order') : __('Save Sales Order') }}
                </button>
                <a href="{{ $editing ? route('sales-orders.show', $order) : route('sales-orders.index') }}"
                   class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">{{ __('Cancel') }}</a>
            </div>
        </div>
    </form>
</div>
