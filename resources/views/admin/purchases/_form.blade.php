{{-- Shared by create/edit. $purchase is only set when editing; $requisition is the source requisition. --}}
@php
    $editing = isset($purchase);
    $cartItems = collect(old('items', []))
        ->map(fn ($row) => ($opt = $itemOptions->firstWhere('id', $row['item'] ?? null))
            ? array_merge($opt, ['quantity' => $row['quantity'] ?? '', 'unit_cost' => $row['unit_cost'] ?? ''])
            : null)
        ->filter()->values();
    if ($cartItems->isEmpty()) {
        $cartItems = $items;
    }
    $vendorType = old('vendor_type', $editing && $purchase->isOneTimeVendor() ? 'one_time' : 'supplier');
    $defaultSiteId = $editing ? $purchase->site_id : $requisition->site_id;
@endphp

<div x-data="purchaseCart(@js(['itemOptions' => $itemOptions, 'items' => $cartItems]))">
    <form method="POST" action="{{ $editing ? route('purchases.update', $purchase) : route('purchases.store') }}">
        @csrf
        @if ($editing) @method('PUT') @else
        <input type="hidden" name="purchase_requisition_id" value="{{ $requisition->id }}">
        @endif

        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 mb-4" x-data="{ vendorType: @js($vendorType) }">
            <div class="mb-4">
                <h3 class="font-bold text-brand-900">{{ __('Vendor & Delivery') }}</h3>
                <p class="text-xs text-slate-400">{{ __('A registered supplier is billed through Accounts Payable; a one-time vendor is paid on receipt.') }}</p>
            </div>

            <div class="mb-4 flex flex-wrap gap-4 text-sm">
                <label class="inline-flex items-center gap-2">
                    <input type="radio" name="vendor_type" value="supplier" x-model="vendorType" class="text-brand-800 focus:ring-accent-500">
                    {{ __('Registered Supplier') }}
                </label>
                <label class="inline-flex items-center gap-2">
                    <input type="radio" name="vendor_type" value="one_time" x-model="vendorType" class="text-brand-800 focus:ring-accent-500">
                    {{ __('One-time / Ad-hoc Vendor') }}
                </label>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div x-show="vendorType === 'supplier'">
                    <x-input-label for="party_id" :value="__('Supplier')" />
                    <div class="mt-1">
                        <x-searchable-select name="party_id" :options="$suppliers" :selected="old('party_id', $purchase->party_id ?? null)"
                                              placeholder="{{ __('Select a supplier…') }}" />
                    </div>
                    <x-input-error class="mt-2" :messages="$errors->get('party_id')" />
                </div>
                <div x-show="vendorType === 'one_time'" x-cloak>
                    <x-input-label for="vendor_name" :value="__('Vendor Name')" />
                    <x-text-input id="vendor_name" name="vendor_name" type="text" class="mt-1 block w-full" :value="old('vendor_name', $purchase->vendor_name ?? '')" />
                    <x-input-error class="mt-2" :messages="$errors->get('vendor_name')" />
                </div>
                <div x-show="vendorType === 'one_time'" x-cloak>
                    <x-input-label for="vendor_phone" :value="__('Vendor Phone (optional)')" />
                    <x-text-input id="vendor_phone" name="vendor_phone" type="text" class="mt-1 block w-full" :value="old('vendor_phone', $purchase->vendor_phone ?? '')" />
                    <x-input-error class="mt-2" :messages="$errors->get('vendor_phone')" />
                </div>

                <div>
                    <x-input-label for="site_id" :value="__('Deliver To')" />
                    <select id="site_id" name="site_id" required
                            class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}" @selected(old('site_id', $defaultSiteId) == $site->id)>{{ $site->name }} ({{ $site->type }})</option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-2" :messages="$errors->get('site_id')" />
                </div>
                <div>
                    <x-input-label for="order_date" :value="__('Order Date')" />
                    <x-text-input id="order_date" name="order_date" type="date" class="mt-1 block w-full"
                                  :value="old('order_date', $editing ? $purchase->order_date->toDateString() : now()->toDateString())" required />
                    <x-input-error class="mt-2" :messages="$errors->get('order_date')" />
                </div>
                <div>
                    <x-input-label for="expected_date" :value="__('Expected Delivery (optional)')" />
                    <x-text-input id="expected_date" name="expected_date" type="date" class="mt-1 block w-full"
                                  :value="old('expected_date', $editing ? $purchase->expected_date?->toDateString() : $requisition->required_by?->toDateString())" />
                    <x-input-error class="mt-2" :messages="$errors->get('expected_date')" />
                </div>
            </div>
        </div>

        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-4">
            <x-input-label :value="__('Add Item')" />
            <div class="relative mt-1 max-w-md" @click.outside="open = false">
                <input type="text" x-model="query" @focus="open = true" placeholder="{{ __('Search a product or variant…') }}"
                       class="block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                <div x-show="open" x-cloak x-transition
                     class="absolute z-20 mt-1 w-full max-h-64 overflow-y-auto rounded-xl bg-white py-1.5 shadow-lg ring-1 ring-slate-200">
                    <template x-for="opt in filtered" :key="opt.id">
                        <div @click="addItem(opt)" class="cursor-pointer px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" x-text="opt.name"></div>
                    </template>
                    <div x-show="filtered.length === 0" class="px-4 py-2 text-sm text-slate-400">{{ __('No matching items.') }}</div>
                </div>
            </div>
        </div>

        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
            <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] text-sm">
                <thead>
                    <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3 font-semibold">{{ __('Item') }}</th>
                        <th class="px-5 py-3 font-semibold w-36">{{ __('Quantity') }}</th>
                        <th class="px-5 py-3 font-semibold w-36">{{ __('Unit Cost') }}</th>
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
                                       class="w-32 rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500" required>
                            </td>
                            <td class="px-5 py-3">
                                <input type="number" step="0.0001" min="0" :name="'items[' + i + '][unit_cost]'" x-model="item.unit_cost"
                                       class="w-32 rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500" required>
                            </td>
                            <td class="px-5 py-3 text-right font-semibold text-slate-800"
                                x-text="((Number(item.quantity) || 0) * (Number(item.unit_cost) || 0)).toFixed(2)"></td>
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
                        <td colspan="3" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Order Total') }}</td>
                        <td class="px-5 py-2 text-right text-base font-bold text-brand-900" x-text="subtotal.toFixed(2)"></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>
        <x-input-error class="mb-2" :messages="$errors->get('items')" />
        <x-input-error class="mb-2" :messages="$errors->get('purchase_requisition_id')" />
        @foreach ($errors->get('items.*') as $messages)
            <x-input-error class="mb-1" :messages="$messages" />
        @endforeach

        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <div class="mb-5 max-w-2xl">
                <x-input-label for="note" :value="__('Note (optional)')" />
                <textarea id="note" name="note" rows="2"
                          class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">{{ old('note', $purchase->note ?? '') }}</textarea>
            </div>
            <div class="flex items-center gap-3">
                <button type="submit" :disabled="items.length === 0"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    {{ $editing ? __('Update Purchase Order') : __('Place Purchase Order') }}
                </button>
                <a href="{{ $editing ? route('purchases.show', $purchase) : route('purchase-requisitions.show', $requisition) }}"
                   class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">{{ __('Cancel') }}</a>
            </div>
        </div>
    </form>
</div>
