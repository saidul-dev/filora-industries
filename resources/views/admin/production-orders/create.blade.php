<x-app-layout>
    <x-slot name="title">{{ __('New Production Order') }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('New Production Order') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ __('Issuing the order consumes the BOM raw materials from stock at the production site immediately.') }}</p>
        </div>
    </x-slot>

    @php $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 4), '0'), '.') ?: '0'; @endphp

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-6">
        <form method="GET" action="{{ route('production-orders.create') }}" class="flex flex-wrap items-end gap-4">
            <div class="w-[28rem] max-w-full">
                <x-input-label for="proforma_invoice_item_id" :value="__('Proforma Invoice Line (Manufactured Product)')" />
                <div class="mt-1">
                    <x-searchable-select name="proforma_invoice_item_id" :options="$lineOptions" :selected="$line?->id"
                                          placeholder="{{ __('Select a PI line…') }}" :auto-submit="true" />
                </div>
            </div>
            @if ($line)
            <div class="w-56">
                <x-input-label for="site_id" :value="__('Production Site')" />
                <div class="mt-1">
                    <x-searchable-select name="site_id" :options="$sites" :selected="$site?->id" :auto-submit="true" />
                </div>
            </div>
            <div class="w-40">
                <x-input-label for="preview_quantity" :value="__('Quantity')" />
                <input id="preview_quantity" type="number" step="0.0001" min="0.0001" name="quantity" value="{{ $quantity }}"
                       class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
            </div>
            <button type="submit" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Recalculate') }}</button>
            @endif
        </form>
    </div>

    @if (! $line)
        <div class="rounded-2xl bg-white p-10 text-center text-slate-400 shadow-sm ring-1 ring-slate-200">
            {{ __('Pick a Proforma Invoice line above — production is never started without a controlling PI.') }}
        </div>
    @else
        @php $short = $materials->contains(fn ($m) => $m->required > $m->available + 0.00001); @endphp

        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 mb-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                <div>
                    <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Product') }}</span>
                    <span class="block font-semibold text-slate-800">{{ $line->label() }}</span>
                </div>
                <div>
                    <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Proforma Invoice') }}</span>
                    <span class="block text-slate-700">{{ $line->proformaInvoice->pi_no }} — {{ $line->proformaInvoice->party->name }}</span>
                </div>
                <div>
                    <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('PI Quantity') }}</span>
                    <span class="block text-slate-700">{{ $qty($line->quantity) }} {{ $line->product->stockUnit->short_name ?? '' }}</span>
                </div>
                <div>
                    <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Still To Produce') }}</span>
                    <span class="block font-semibold {{ $line->quantityToProduce() > 0 ? 'text-amber-600' : 'text-emerald-600' }}">{{ $qty($line->quantityToProduce()) }}</span>
                </div>
            </div>
        </div>

        @if ($line->product->bomItems->isEmpty())
            <div class="rounded-2xl bg-rose-50 p-6 text-sm font-medium text-rose-700 ring-1 ring-rose-200">
                {{ __(':product has no Bill of Materials yet.', ['product' => $line->product->name]) }}
                @can('production.edit')
                <a href="{{ route('bom.edit', $line->product) }}" class="underline">{{ __('Define its BOM') }}</a>
                @endcan
            </div>
        @else
        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
            <div class="px-6 pt-5 pb-3">
                <h3 class="font-bold text-brand-900">{{ __('Raw Material Consumption') }}</h3>
                <p class="text-xs text-slate-400">{{ __('For :qty units at :site, per the BOM.', ['qty' => $qty($quantity), 'site' => $site->name]) }}</p>
            </div>
            <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-sm">
                <thead>
                    <tr class="border-y border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3 font-semibold">{{ __('Material') }}</th>
                        <th class="px-5 py-3 font-semibold text-right">{{ __('Per Unit') }}</th>
                        <th class="px-5 py-3 font-semibold text-right">{{ __('Required') }}</th>
                        <th class="px-5 py-3 font-semibold text-right">{{ __('Available at Site') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($materials as $row)
                    @php $enough = $row->available + 0.00001 >= $row->required; @endphp
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-3 font-semibold text-slate-800">{{ $row->material->name }}</td>
                        <td class="px-5 py-3 text-right text-slate-600">{{ $qty($row->per_unit) }}</td>
                        <td class="px-5 py-3 text-right text-slate-700">{{ $qty($row->required) }} {{ $row->material->stockUnit->short_name ?? '' }}</td>
                        <td class="px-5 py-3 text-right font-semibold {{ $enough ? 'text-emerald-600' : 'text-rose-600' }}">{{ $qty($row->available) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            @if ($short)
            <p class="border-t border-slate-100 px-6 py-3 text-xs font-medium text-rose-600">
                {{ __('Not enough raw material at :site — receive or transfer it there first, or raise a requisition.', ['site' => $site->name]) }}
            </p>
            @endif
        </div>

        <form method="POST" action="{{ route('production-orders.store') }}" class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            @csrf
            <input type="hidden" name="proforma_invoice_item_id" value="{{ $line->id }}">
            <input type="hidden" name="site_id" value="{{ $site->id }}">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
                <div>
                    <x-input-label for="quantity" :value="__('Quantity to Produce')" />
                    <x-text-input id="quantity" name="quantity" type="number" step="0.0001" min="0.0001" class="mt-1 block w-full"
                                  :value="old('quantity', $quantity)" required />
                    <x-input-error class="mt-2" :messages="$errors->get('quantity')" />
                </div>
                <div>
                    <x-input-label for="start_date" :value="__('Planned Start (optional)')" />
                    <x-text-input id="start_date" name="start_date" type="date" class="mt-1 block w-full" :value="old('start_date', now()->toDateString())" />
                    <x-input-error class="mt-2" :messages="$errors->get('start_date')" />
                </div>
                <div>
                    <x-input-label for="due_date" :value="__('Due Date (optional)')" />
                    <x-text-input id="due_date" name="due_date" type="date" class="mt-1 block w-full"
                                  :value="old('due_date', $line->proformaInvoice->delivery_date?->toDateString())" />
                    <x-input-error class="mt-2" :messages="$errors->get('due_date')" />
                </div>
            </div>
            <div class="mb-5 max-w-2xl">
                <x-input-label for="note" :value="__('Note (optional)')" />
                <textarea id="note" name="note" rows="2"
                          class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">{{ old('note') }}</textarea>
            </div>
            <x-input-error class="mb-4" :messages="$errors->get('proforma_invoice_item_id')" />

            <button type="submit" @disabled($short)
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                {{ __('Issue Production Order') }}
            </button>
        </form>
        @endif
    @endif
</x-app-layout>
