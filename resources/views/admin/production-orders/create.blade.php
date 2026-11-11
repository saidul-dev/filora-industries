<x-app-layout>
    <x-slot name="title">{{ __('New Production Order') }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('New Production Order') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ __('Produce one or several lines of a Sales Order or Proforma Invoice. Issuing the order consumes the BOM raw materials from stock at the production site immediately.') }}</p>
        </div>
    </x-slot>

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-6">
        <form method="GET" action="{{ route('production-orders.create') }}">
            <div class="grid grid-cols-1 items-start gap-4 md:grid-cols-3">
                <div class="md:col-span-2">
                    <x-input-label for="source" :value="__('Sales Order / Proforma Invoice')" />
                    <div class="mt-1">
                        <x-searchable-select name="source" :options="$sourceOptions" :selected="$selectedSource"
                                              placeholder="{{ __('Select a Sales Order or PI…') }}" :auto-submit="true" />
                    </div>
                </div>
                @if ($document)
                <div>
                    <x-input-label for="site_id" :value="__('Production Site')" />
                    <div class="mt-1">
                        <x-searchable-select name="site_id" :options="$sites" :selected="$site?->id" :auto-submit="true" />
                    </div>
                </div>
                @endif
            </div>
            <p class="mt-2 text-xs text-slate-400">{{ __('Only Sales Orders and PIs that still have manufactured lines not in production are listed. Raw materials are consumed from the production site.') }}</p>
        </form>
    </div>

    @if (! $document)
        <div class="rounded-2xl bg-white p-10 text-center text-slate-400 shadow-sm ring-1 ring-slate-200">
            {{ __('Pick a confirmed Sales Order or an active Proforma Invoice above.') }}
        </div>
    @elseif ($rows->isEmpty())
        <div class="rounded-2xl bg-white p-10 text-center text-slate-400 shadow-sm ring-1 ring-slate-200">
            {{ __('This document has no manufactured lines planned for production.') }}
        </div>
    @else
        @php
            $isOrder = $document instanceof \App\Models\SalesOrder;
            $docNo = $isOrder ? $document->order_no : $document->pi_no;
            $docUrl = $isOrder ? route('sales-orders.show', $document) : route('proforma-invoices.show', $document);
            $selectedIds = collect(old('items', []))->keys()->map(fn ($id) => (int) $id)->all();
            $rowsForJs = $rows->map(function ($row) use ($selectedIds) {
                if (old('items') !== null) {
                    $row['checked'] = $row['available'] && in_array($row['id'], $selectedIds, true);
                    $row['qty'] = $row['checked'] ? (float) old('items.'.$row['id'], $row['qty']) : $row['qty'];
                }

                return $row;
            });
        @endphp

        <form method="POST" action="{{ route('production-orders.store') }}"
              x-data="productionBuilder({{ Js::from($rowsForJs) }}, {{ Js::from($materials) }})">
            @csrf
            <input type="hidden" name="source" value="{{ $selectedSource }}">
            <input type="hidden" name="site_id" value="{{ $site->id }}">

            <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
                <div class="flex flex-wrap items-end justify-between gap-4 px-6 pt-5 pb-4">
                    <div>
                        <h3 class="font-bold text-brand-900">{{ __('Lines to Produce') }}</h3>
                        <p class="text-xs text-slate-400">
                            {{ $isOrder ? __('Sales Order') : __('Proforma Invoice') }}
                            <a href="{{ $docUrl }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ $docNo }}</a>
                            · {{ $document->party->name }}
                            — {{ __('tick one or more lines; they all go into one Production Order.') }}
                        </p>
                    </div>
                    <span class="text-xs font-semibold text-slate-500" x-text="`${selectedCount} {{ __('of') }} ${selectableCount} {{ __('lines selected') }}`"></span>
                </div>
                <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead>
                        <tr class="whitespace-nowrap border-y border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <th class="w-10 py-3 pl-6 pr-2">
                                <input type="checkbox" class="rounded border-slate-300 text-brand-700 focus:ring-brand-500"
                                       :checked="allChecked" @change="toggleAll($event.target.checked)" :disabled="selectableCount === 0">
                            </th>
                            <th class="px-3 py-3 font-semibold">{{ __('Product') }}</th>
                            <th class="px-3 py-3 font-semibold text-right">{{ __('Ordered') }}</th>
                            <th class="px-3 py-3 font-semibold text-right">{{ __('Still To Produce') }}</th>
                            <th class="w-44 py-3 pl-3 pr-6 font-semibold">{{ __('Quantity to Produce') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="row in rows" :key="row.id">
                            <tr :class="row.available ? 'hover:bg-slate-50' : 'bg-slate-50/60'">
                                <td class="py-3 pl-6 pr-2 align-top">
                                    <input type="checkbox" class="rounded border-slate-300 text-brand-700 focus:ring-brand-500 disabled:opacity-40"
                                           x-model="row.checked" :disabled="! row.available">
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <span class="block font-semibold text-slate-800" x-text="row.label"></span>
                                    <span class="block text-xs text-slate-500" x-show="row.details" x-text="row.details"></span>
                                    <template x-if="row.live_no">
                                        <a :href="row.live_url" class="mt-0.5 inline-block text-xs font-semibold text-emerald-600 hover:underline"
                                           x-text="`{{ __('Already in production') }} — ${row.live_no}`"></a>
                                    </template>
                                    <template x-if="! row.live_no && row.no_bom">
                                        <a :href="row.bom_url" class="mt-0.5 inline-block text-xs font-semibold text-rose-600 hover:underline">{{ __('No BOM defined — define it first') }}</a>
                                    </template>
                                    <template x-if="! row.live_no && ! row.no_bom && row.to_produce <= 0">
                                        <span class="mt-0.5 block text-xs text-slate-400">{{ __('Nothing left to produce') }}</span>
                                    </template>
                                </td>
                                <td class="px-3 py-3 text-right align-top tabular-nums text-slate-600" x-text="`${fmt(row.ordered)} ${row.unit}`"></td>
                                <td class="px-3 py-3 text-right align-top tabular-nums font-semibold"
                                    :class="row.to_produce > 0 ? 'text-amber-600' : 'text-emerald-600'" x-text="fmt(row.to_produce)"></td>
                                <td class="py-2 pl-3 pr-6 align-top">
                                    <input type="number" step="0.0001" min="0.0001" :max="row.to_produce"
                                           :name="`items[${row.id}]`" x-model.number="row.qty"
                                           :disabled="! row.checked" x-show="row.available"
                                           class="block w-full rounded-lg border-slate-300 text-right text-sm focus:border-accent-500 focus:ring-accent-500 disabled:bg-slate-50 disabled:text-slate-400">
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>
                @foreach ($errors->getMessages() as $key => $messages)
                    @if (str_starts_with($key, 'items') || $key === 'source')
                        <x-input-error class="border-t border-slate-100 px-6 py-3" :messages="$messages" />
                    @endif
                @endforeach
            </div>

            <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
                <div class="px-6 pt-5 pb-3">
                    <h3 class="font-bold text-brand-900">{{ __('Raw Material Consumption') }}</h3>
                    <p class="text-xs text-slate-400">{{ __('Total for the selected lines at :site, per their BOMs.', ['site' => $site->name]) }}</p>
                </div>
                <div class="overflow-x-auto">
                <table class="w-full min-w-[620px] text-sm">
                    <thead>
                        <tr class="border-y border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <th class="px-6 py-3 font-semibold">{{ __('Material') }}</th>
                            <th class="px-5 py-3 font-semibold text-right">{{ __('Required') }}</th>
                            <th class="px-6 py-3 font-semibold text-right">{{ __('Available at Site') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="need in needs" :key="need.id">
                            <tr class="hover:bg-slate-50">
                                <td class="px-6 py-3 font-semibold text-slate-800" x-text="need.name"></td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-700" x-text="`${fmt(need.required)} ${need.unit}`"></td>
                                <td class="px-6 py-3 text-right tabular-nums font-semibold"
                                    :class="need.short ? 'text-rose-600' : 'text-emerald-600'" x-text="fmt(need.available)"></td>
                            </tr>
                        </template>
                        <tr x-show="needs.length === 0">
                            <td colspan="3" class="px-6 py-6 text-center text-sm text-slate-400">{{ __('Select a line to see its materials.') }}</td>
                        </tr>
                    </tbody>
                </table>
                </div>
                <p x-show="short" x-cloak class="border-t border-slate-100 px-6 py-3 text-xs font-medium text-rose-600">
                    {{ __('Not enough raw material at :site — receive or transfer it there first, or raise a requisition.', ['site' => $site->name]) }}
                </p>
            </div>

            <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
                    <div>
                        <x-input-label for="start_date" :value="__('Planned Start (optional)')" />
                        <x-text-input id="start_date" name="start_date" type="date" class="mt-1 block w-full" :value="old('start_date', now()->toDateString())" />
                        <x-input-error class="mt-2" :messages="$errors->get('start_date')" />
                    </div>
                    <div>
                        <x-input-label for="due_date" :value="__('Due Date (optional)')" />
                        <x-text-input id="due_date" name="due_date" type="date" class="mt-1 block w-full"
                                      :value="old('due_date', $document->delivery_date?->toDateString())" />
                        <x-input-error class="mt-2" :messages="$errors->get('due_date')" />
                    </div>
                    <div>
                        <x-input-label for="note" :value="__('Note (optional)')" />
                        <textarea id="note" name="note" rows="1"
                                  class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">{{ old('note') }}</textarea>
                    </div>
                </div>

                <button type="submit" :disabled="selectedCount === 0 || short"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    <span x-text="selectedCount > 1 ? `{{ __('Issue Production Order') }} (${selectedCount} {{ __('lines') }})` : `{{ __('Issue Production Order') }}`"></span>
                </button>
            </div>
        </form>

        <script>
            function productionBuilder(rows, materials) {
                return {
                    rows,
                    materials,
                    fmt(value) {
                        return Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 4 });
                    },
                    get selectableCount() { return this.rows.filter(r => r.available).length; },
                    get selectedCount() { return this.rows.filter(r => r.checked && Number(r.qty) > 0).length; },
                    get allChecked() { return this.selectableCount > 0 && this.rows.every(r => ! r.available || r.checked); },
                    toggleAll(on) { this.rows.forEach(r => { if (r.available) r.checked = on; }); },
                    get needs() {
                        const totals = {};
                        this.rows.filter(r => r.checked && Number(r.qty) > 0).forEach(r => {
                            r.bom.forEach(b => { totals[b.material] = (totals[b.material] || 0) + Number(r.qty) * b.per_unit; });
                        });
                        return Object.entries(totals).map(([id, required]) => {
                            const m = this.materials[id] || { name: id, unit: '', available: 0 };
                            required = Math.round(required * 10000) / 10000;
                            return { id, name: m.name, unit: m.unit, required, available: m.available, short: required > m.available + 0.00001 };
                        });
                    },
                    get short() { return this.needs.some(n => n.short); },
                };
            }
        </script>
    @endif
</x-app-layout>
