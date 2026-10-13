<x-app-layout>
    <x-slot name="title">{{ __('Edit') }} — {{ $invoice->pi_no }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('Edit Proforma Invoice') }} {{ $invoice->pi_no }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ __('Finalise exactly what will be delivered to :buyer and how each line will be fulfilled.', ['buyer' => $invoice->party->name]) }}</p>
        </div>
    </x-slot>

    @php
        $cartItems = collect(old('items', []))
            ->map(fn ($row) => ($opt = $itemOptions->firstWhere('id', $row['item'] ?? null))
                ? array_merge($opt, [
                    'quantity' => $row['quantity'] ?? '',
                    'unit_price' => $row['unit_price'] ?? '',
                    'fulfillment' => $row['fulfillment'] ?? 'stock',
                ])
                : null)
            ->filter()->values();
        if ($cartItems->isEmpty()) {
            $cartItems = $items;
        }
    @endphp

    <div x-data="piCart(@js(['itemOptions' => $itemOptions, 'items' => $cartItems, 'discount' => old('discount', (float) $invoice->discount_amount)]))">
        <form method="POST" action="{{ route('proforma-invoices.update', $invoice) }}">
            @csrf
            @method('PUT')

            <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 mb-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <x-input-label for="pi_date" :value="__('PI Date')" />
                        <x-text-input id="pi_date" name="pi_date" type="date" class="mt-1 block w-full"
                                      :value="old('pi_date', $invoice->pi_date->toDateString())" required />
                        <x-input-error class="mt-2" :messages="$errors->get('pi_date')" />
                    </div>
                    <div>
                        <x-input-label for="valid_until" :value="__('Valid Until (optional)')" />
                        <x-text-input id="valid_until" name="valid_until" type="date" class="mt-1 block w-full"
                                      :value="old('valid_until', $invoice->valid_until?->toDateString())" />
                        <x-input-error class="mt-2" :messages="$errors->get('valid_until')" />
                    </div>
                    <div>
                        <x-input-label for="delivery_date" :value="__('Delivery Date (optional)')" />
                        <x-text-input id="delivery_date" name="delivery_date" type="date" class="mt-1 block w-full"
                                      :value="old('delivery_date', $invoice->delivery_date?->toDateString())" />
                        <x-input-error class="mt-2" :messages="$errors->get('delivery_date')" />
                    </div>
                </div>
            </div>

            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-4">
                <x-input-label :value="__('Add Product')" />
                <div class="relative mt-1 max-w-md" @click.outside="open = false">
                    <input type="text" x-model="query" @focus="open = true"
                           placeholder="{{ __('Search a product or variant…') }}"
                           class="block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                    <div x-show="open" x-cloak x-transition
                         class="absolute z-20 mt-1 w-full max-h-64 overflow-y-auto rounded-xl bg-white py-1.5 shadow-lg ring-1 ring-slate-200">
                        <template x-for="opt in filtered" :key="opt.id">
                            <div @click="addItem(opt)" class="cursor-pointer px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" x-text="opt.name"></div>
                        </template>
                        <div x-show="filtered.length === 0" class="px-4 py-2 text-sm text-slate-400">{{ __('No matching products.') }}</div>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
                <div class="overflow-x-auto">
                <table class="w-full min-w-[960px] text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <th class="px-5 py-3 font-semibold">{{ __('Product') }}</th>
                            <th class="px-5 py-3 font-semibold w-52">{{ __('Fulfillment') }}</th>
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
                                    <select :name="'items[' + i + '][fulfillment]'" x-model="item.fulfillment"
                                            class="w-48 rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                                        <option value="stock">{{ __('From Existing Stock') }}</option>
                                        <option value="purchase">{{ __('Direct Purchase') }}</option>
                                        <option value="production" :disabled="item.type !== 'manufactured'">{{ __('Factory Production') }}</option>
                                    </select>
                                </td>
                                <td class="px-5 py-3">
                                    <input type="number" step="0.0001" min="0.0001" :name="'items[' + i + '][quantity]'" x-model="item.quantity"
                                           class="w-32 rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500" required>
                                </td>
                                <td class="px-5 py-3">
                                    <input type="number" step="0.01" min="0" :name="'items[' + i + '][unit_price]'" x-model="item.unit_price"
                                           class="w-32 rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500" required>
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
                    <tfoot class="border-t border-slate-200 bg-slate-50">
                        <tr>
                            <td colspan="4" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Subtotal') }}</td>
                            <td class="px-5 py-2 text-right font-semibold text-slate-800" x-text="subtotal.toFixed(2)"></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td colspan="4" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Discount') }}</td>
                            <td class="px-5 py-2 text-right">
                                <input type="number" step="0.01" min="0" name="discount" x-model="discount"
                                       class="w-32 rounded-lg border-slate-300 text-right text-sm focus:border-accent-500 focus:ring-accent-500">
                            </td>
                            <td></td>
                        </tr>
                        <tr>
                            <td colspan="4" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total') }}</td>
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
                        <x-input-label for="terms" :value="__('Terms & Conditions (printed on the PI)')" />
                        <textarea id="terms" name="terms" rows="4"
                                  class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">{{ old('terms', $invoice->terms) }}</textarea>
                        <x-input-error class="mt-2" :messages="$errors->get('terms')" />
                    </div>
                    <div>
                        <x-input-label for="note" :value="__('Internal Note (optional)')" />
                        <textarea id="note" name="note" rows="4"
                                  class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">{{ old('note', $invoice->note) }}</textarea>
                        <x-input-error class="mt-2" :messages="$errors->get('note')" />
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" :disabled="items.length === 0"
                            class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                        {{ __('Save Proforma Invoice') }}
                    </button>
                    <a href="{{ route('proforma-invoices.show', $invoice) }}" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">{{ __('Cancel') }}</a>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
