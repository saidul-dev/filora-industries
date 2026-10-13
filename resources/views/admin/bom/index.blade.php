<x-app-layout>
    <x-slot name="title">{{ __('Bill of Materials') }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('Bill of Materials') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ __('Raw materials consumed to produce one unit of each Manufactured product.') }}</p>
        </div>
    </x-slot>

    <form method="GET" action="{{ route('bom.index') }}" class="mb-4 max-w-md">
        <div class="relative">
            <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('Search by name or SKU…') }}"
                   class="w-full rounded-lg border-slate-200 bg-white pl-9 pr-4 py-2 text-sm focus:border-accent-500 focus:ring-accent-500 placeholder:text-slate-400">
        </div>
    </form>

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full min-w-[860px] text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('Manufactured Product') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Category') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Materials (per unit)') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Est. Material Cost / Unit') }}</th>
                    <th class="px-5 py-3 font-semibold"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($products as $product)
                @php $unitCost = $product->bomItems->sum(fn ($item) => (float) $item->quantity * (float) ($item->material->estimated_cost ?? 0)); @endphp
                <tr class="hover:bg-slate-50 align-top">
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $product->name }}</span>
                        <span class="block text-xs text-slate-400">{{ $product->sku }} · {{ $product->stockUnit->short_name ?? '—' }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ $product->category->name ?? '—' }}</td>
                    <td class="px-5 py-3 text-slate-600">
                        @forelse ($product->bomItems as $item)
                            <span class="block">{{ $item->material->name }} — {{ rtrim(rtrim(number_format($item->quantity, 4), '0'), '.') }}</span>
                        @empty
                            <span class="inline-flex rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-semibold text-rose-600 ring-1 ring-rose-200">{{ __('No BOM yet') }}</span>
                        @endforelse
                    </td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ $product->bomItems->isNotEmpty() ? number_format($unitCost, 2) : '—' }}</td>
                    <td class="px-5 py-3 text-right">
                        <a href="{{ route('bom.edit', $product) }}" class="font-semibold text-accent-600 hover:text-accent-800">
                            @can('production.edit'){{ __('Edit BOM') }}@else{{ __('View') }}@endcan
                        </a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-5 py-10 text-center text-slate-400">
                        {{ __('No Manufactured products yet — set a product\'s type to "Manufactured Product" to define its BOM.') }}
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>

        @if ($products->hasPages())
        <div class="border-t border-slate-100 px-5 py-4">
            {{ $products->links() }}
        </div>
        @endif
    </div>
</x-app-layout>
