<x-app-layout>
    <x-slot name="title">{{ __('Stock by Location') }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('Stock by Location') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ __('Raw materials and finished goods at every site side by side, with the consolidated total and its value at average cost.') }}</p>
        </div>
    </x-slot>

    @php $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 4), '0'), '.') ?: '0'; @endphp

    <!-- Valuation by location -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        @foreach ($sites as $site)
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ $site->name }} <span class="normal-case font-normal">· {{ $site->type }}</span></span>
            <span class="mt-1 block text-xl font-bold text-brand-900">{{ number_format($siteValues[$site->id] ?? 0, 2) }}</span>
        </div>
        @endforeach
        <div class="rounded-2xl bg-brand-800 p-4 shadow-sm">
            <span class="block text-[11px] font-semibold uppercase tracking-wide text-brand-200">{{ __('Consolidated Value') }}</span>
            <span class="mt-1 block text-xl font-bold text-white">{{ number_format($totalValue, 2) }}</span>
        </div>
    </div>

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-6">
        <form method="GET" action="{{ route('stock.locations') }}" class="flex flex-wrap items-end gap-4">
            <div class="w-60">
                <x-input-label for="type" :value="__('Product Type')" />
                <select id="type" name="type" onchange="this.form.submit()"
                        class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                    <option value="">{{ __('All types') }}</option>
                    @foreach (\App\Models\Product::TYPES as $key => $label)
                        <option value="{{ $key }}" @selected($type === $key)>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-56">
                <x-input-label for="category_id" :value="__('Category')" />
                <div class="mt-1">
                    <x-searchable-select name="category_id" :options="$categories" :selected="$categoryId" placeholder="{{ __('All categories') }}" :auto-submit="true" />
                </div>
            </div>
            <div class="flex-1 min-w-[200px]">
                <x-input-label for="q" :value="__('Search')" />
                <input type="search" id="q" name="q" value="{{ $q }}" placeholder="{{ __('Name or SKU…') }}"
                       class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
            </div>
            <label class="mb-2 inline-flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="show_all" value="1" @checked($showAll) onchange="this.form.submit()"
                       class="rounded border-slate-300 text-brand-800 focus:ring-accent-500">
                {{ __('Include zero stock') }}
            </label>
            <button type="submit" class="rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">{{ __('Filter') }}</button>
        </form>
    </div>

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm" style="min-width: {{ 720 + $sites->count() * 120 }}px">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('Item') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Type') }}</th>
                    @foreach ($sites as $site)
                    <th class="px-5 py-3 font-semibold text-right">{{ $site->name }}</th>
                    @endforeach
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Total') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Avg. Cost') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Value') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($rows as $row)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $row->name }}</span>
                        <span class="block text-xs text-slate-400">{{ $row->sku }}{{ $row->unit ? ' · '.$row->unit : '' }}</span>
                    </td>
                    <td class="px-5 py-3 text-xs text-slate-500">{{ __(\App\Models\Product::TYPES[$row->type] ?? $row->type) }}</td>
                    @foreach ($sites as $site)
                    @php $siteQty = $row->sites[$site->id]; @endphp
                    <td class="px-5 py-3 text-right {{ $siteQty > 0 ? 'text-slate-700' : ($siteQty < 0 ? 'text-rose-600' : 'text-slate-300') }}">{{ $qty($siteQty) }}</td>
                    @endforeach
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ $qty($row->total) }}</td>
                    <td class="px-5 py-3 text-right text-slate-600">{{ number_format($row->cost, 2) }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($row->value, 2) }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $sites->count() + 5 }}" class="px-5 py-10 text-center text-slate-400">{{ __('No stock found.') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>

        @if ($rows->hasPages())
        <div class="border-t border-slate-100 px-5 py-4">
            {{ $rows->links() }}
        </div>
        @endif
    </div>
</x-app-layout>
