<x-app-layout>
    <x-slot name="title">{{ __('BOM') }} — {{ $product->name }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ __('Bill of Materials') }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">
                    {{ $product->name }} ({{ $product->sku }}) —
                    {{ __('materials consumed to produce 1 :unit', ['unit' => $product->stockUnit->short_name ?? __('unit')]) }}
                </p>
            </div>
            <a href="{{ route('bom.index') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                {{ __('Back') }}
            </a>
        </div>
    </x-slot>

    @php $canEdit = auth()->user()->can('production.edit'); @endphp

    <div x-data="bomEditor(@js(['itemOptions' => $materialOptions, 'items' => $items]))">
        <form method="POST" action="{{ route('bom.update', $product) }}">
            @csrf
            @method('PUT')

            @if ($canEdit)
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-4">
                <x-input-label :value="__('Add Raw Material')" />
                <div class="relative mt-1 max-w-md" @click.outside="open = false">
                    <input type="text" x-model="query" @focus="open = true"
                           placeholder="{{ __('Search a raw material or component…') }}"
                           class="block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                    <div x-show="open" x-cloak x-transition
                         class="absolute z-20 mt-1 w-full max-h-64 overflow-y-auto rounded-xl bg-white py-1.5 shadow-lg ring-1 ring-slate-200">
                        <template x-for="opt in filtered" :key="opt.id">
                            <div @click="addItem(opt)"
                                 class="flex cursor-pointer items-center justify-between gap-3 px-4 py-2 text-sm hover:bg-slate-50">
                                <span x-text="opt.name" class="text-slate-700"></span>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                      :class="opt.type === 'raw_material' ? 'bg-amber-50 text-amber-600' : 'bg-slate-100 text-slate-500'"
                                      x-text="opt.type === 'raw_material' ? '{{ __('Raw Material') }}' : '{{ __('Other') }}'"></span>
                            </div>
                        </template>
                        <div x-show="filtered.length === 0" class="px-4 py-2 text-sm text-slate-400">{{ __('No matching items.') }}</div>
                    </div>
                </div>
                <p class="mt-2 text-xs text-slate-400">{{ __('Only simple (non-variant) products can be BOM materials. Raw materials are listed first.') }}</p>
            </div>
            @endif

            <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
                <div class="overflow-x-auto">
                <table class="w-full min-w-[820px] text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <th class="px-5 py-3 font-semibold">{{ __('Material') }}</th>
                            <th class="px-5 py-3 font-semibold w-40">{{ __('Qty per Unit') }}</th>
                            <th class="px-5 py-3 font-semibold w-20">{{ __('Unit') }}</th>
                            <th class="px-5 py-3 font-semibold text-right w-32">{{ __('Est. Cost') }}</th>
                            <th class="px-5 py-3 font-semibold">{{ __('Note') }}</th>
                            <th class="px-5 py-3 font-semibold w-12"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="(item, i) in items" :key="item.id">
                            <tr class="hover:bg-slate-50">
                                <td class="px-5 py-3">
                                    <input type="hidden" :name="'items[' + i + '][material_id]'" :value="item.id">
                                    <span class="font-semibold text-slate-800" x-text="item.name"></span>
                                </td>
                                <td class="px-5 py-3">
                                    <input type="number" step="0.0001" min="0.0001" :name="'items[' + i + '][quantity]'" x-model="item.quantity"
                                           @if (! $canEdit) readonly @endif
                                           class="w-32 rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500" required>
                                </td>
                                <td class="px-5 py-3 text-slate-600" x-text="item.unit || '—'"></td>
                                <td class="px-5 py-3 text-right text-slate-600" x-text="((Number(item.quantity) || 0) * (Number(item.cost) || 0)).toFixed(2)"></td>
                                <td class="px-5 py-3">
                                    <input type="text" maxlength="255" :name="'items[' + i + '][note]'" x-model="item.note"
                                           @if (! $canEdit) readonly @endif
                                           class="w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                                </td>
                                <td class="px-5 py-3 text-right">
                                    @if ($canEdit)
                                    <button type="button" @click="removeItem(item.id)" class="text-slate-400 hover:text-rose-600" title="{{ __('Remove') }}">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                    </button>
                                    @endif
                                </td>
                            </tr>
                        </template>
                        <tr x-show="items.length === 0">
                            <td colspan="6" class="px-5 py-10 text-center text-slate-400">{{ __('No materials yet — add the raw materials this product consumes.') }}</td>
                        </tr>
                    </tbody>
                    <tfoot x-show="items.length">
                        <tr class="border-t border-slate-200 bg-slate-50">
                            <td colspan="3" class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Estimated material cost per unit') }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-slate-800" x-text="unitCost.toFixed(2)"></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
                </div>
            </div>
            <x-input-error class="mb-4" :messages="$errors->get('items')" />
            @foreach ($errors->get('items.*') as $messages)
                <x-input-error class="mb-1" :messages="$messages" />
            @endforeach

            @if ($canEdit)
            <button type="submit"
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-accent-500 focus:ring-offset-2">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                {{ __('Save BOM') }}
            </button>
            @endif
        </form>
    </div>
</x-app-layout>
