{{-- Shared by create/edit. $requisition is only set when editing; $invoice is the controlling PI. --}}
@php
    $editing = isset($requisition);
    $cartItems = collect(old('items', []))
        ->map(fn ($row) => ($opt = $itemOptions->firstWhere('id', $row['item'] ?? null))
            ? array_merge($opt, ['quantity' => $row['quantity'] ?? '', 'unit_cost' => $row['unit_cost'] ?? '', 'note' => $row['note'] ?? ''])
            : null)
        ->filter()->values();
    if ($cartItems->isEmpty()) {
        $cartItems = $items;
    }
    $defaultSiteId = $editing
        ? $requisition->site_id
        : ($sites->firstWhere('type', 'Factory')?->id ?? $sites->first()?->id);
@endphp

<div x-data="purchaseCart(@js(['itemOptions' => $itemOptions, 'items' => $cartItems]))">
    <form method="POST" action="{{ $editing ? route('purchase-requisitions.update', $requisition) : route('purchase-requisitions.store') }}">
        @csrf
        @if ($editing) @method('PUT') @endif
        <input type="hidden" name="proforma_invoice_id" value="{{ $invoice->id }}">

        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 mb-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <x-input-label for="site_id" :value="__('Needed At (Factory / Office)')" />
                    <select id="site_id" name="site_id" required
                            class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}" @selected(old('site_id', $defaultSiteId) == $site->id)>{{ $site->name }} ({{ $site->type }})</option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-2" :messages="$errors->get('site_id')" />
                </div>
                <div>
                    <x-input-label for="request_date" :value="__('Request Date')" />
                    <x-text-input id="request_date" name="request_date" type="date" class="mt-1 block w-full"
                                  :value="old('request_date', $editing ? $requisition->request_date->toDateString() : now()->toDateString())" required />
                    <x-input-error class="mt-2" :messages="$errors->get('request_date')" />
                </div>
                <div>
                    <x-input-label for="required_by" :value="__('Required By (optional)')" />
                    <x-text-input id="required_by" name="required_by" type="date" class="mt-1 block w-full"
                                  :value="old('required_by', $editing ? $requisition->required_by?->toDateString() : $invoice->delivery_date?->toDateString())" />
                    <x-input-error class="mt-2" :messages="$errors->get('required_by')" />
                </div>
            </div>
        </div>

        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-4">
            <x-input-label :value="__('Add Item')" />
            <div class="relative mt-1 max-w-md" @click.outside="open = false">
                <input type="text" x-model="query" @focus="open = true"
                       placeholder="{{ __('Search finished goods or raw materials…') }}"
                       class="block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                <div x-show="open" x-cloak x-transition
                     class="absolute z-20 mt-1 w-full max-h-64 overflow-y-auto rounded-xl bg-white py-1.5 shadow-lg ring-1 ring-slate-200">
                    <template x-for="opt in filtered" :key="opt.id">
                        <div @click="addItem(opt)" class="flex cursor-pointer items-center justify-between gap-3 px-4 py-2 text-sm hover:bg-slate-50">
                            <span x-text="opt.name" class="text-slate-700"></span>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                  :class="opt.type === 'raw_material' ? 'bg-amber-50 text-amber-600' : (opt.type === 'manufactured' ? 'bg-indigo-50 text-indigo-600' : 'bg-sky-50 text-sky-600')"
                                  x-text="opt.type === 'raw_material' ? '{{ __('Raw Material') }}' : (opt.type === 'manufactured' ? '{{ __('Manufactured') }}' : '{{ __('Direct Purchase') }}')"></span>
                        </div>
                    </template>
                    <div x-show="filtered.length === 0" class="px-4 py-2 text-sm text-slate-400">{{ __('No matching items.') }}</div>
                </div>
            </div>
        </div>

        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
            <div class="overflow-x-auto">
            <table class="w-full min-w-[920px] text-sm">
                <thead>
                    <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3 font-semibold">{{ __('Item') }}</th>
                        <th class="px-5 py-3 font-semibold w-36">{{ __('Quantity') }}</th>
                        <th class="px-5 py-3 font-semibold w-36">{{ __('Est. Unit Cost') }}</th>
                        <th class="px-5 py-3 font-semibold w-32 text-right">{{ __('Est. Total') }}</th>
                        <th class="px-5 py-3 font-semibold">{{ __('Purpose / Note') }}</th>
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
                                <input type="number" step="0.01" min="0" :name="'items[' + i + '][unit_cost]'" x-model="item.unit_cost"
                                       class="w-32 rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                            </td>
                            <td class="px-5 py-3 text-right text-slate-700"
                                x-text="((Number(item.quantity) || 0) * (Number(item.unit_cost) || 0)).toFixed(2)"></td>
                            <td class="px-5 py-3">
                                <input type="text" maxlength="255" :name="'items[' + i + '][note]'" x-model="item.note"
                                       class="w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                            </td>
                            <td class="px-5 py-3 text-right">
                                <button type="button" @click="removeItem(item.id)" class="text-slate-400 hover:text-rose-600" title="{{ __('Remove') }}">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                </button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="items.length === 0">
                        <td colspan="6" class="px-5 py-10 text-center text-slate-400">{{ __('Nothing to requisition yet — add the items to buy.') }}</td>
                    </tr>
                </tbody>
                <tfoot x-show="items.length" class="border-t border-slate-200 bg-slate-50">
                    <tr>
                        <td colspan="3" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Estimated Total') }}</td>
                        <td class="px-5 py-2 text-right font-semibold text-slate-800" x-text="subtotal.toFixed(2)"></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>
        <x-input-error class="mb-2" :messages="$errors->get('items')" />
        <x-input-error class="mb-2" :messages="$errors->get('proforma_invoice_id')" />
        @foreach ($errors->get('items.*') as $messages)
            <x-input-error class="mb-1" :messages="$messages" />
        @endforeach

        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <div class="mb-5 max-w-2xl">
                <x-input-label for="note" :value="__('Note (optional)')" />
                <textarea id="note" name="note" rows="2"
                          class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">{{ old('note', $requisition->note ?? '') }}</textarea>
                <x-input-error class="mt-2" :messages="$errors->get('note')" />
            </div>
            <div class="flex items-center gap-3">
                <button type="submit" :disabled="items.length === 0"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    {{ $editing ? __('Update Requisition') : __('Submit Requisition') }}
                </button>
                <a href="{{ $editing ? route('purchase-requisitions.show', $requisition) : route('proforma-invoices.show', $invoice) }}"
                   class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">{{ __('Cancel') }}</a>
            </div>
        </div>
    </form>
</div>
