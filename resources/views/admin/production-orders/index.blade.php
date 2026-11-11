<x-app-layout>
    <x-slot name="title">{{ __('Production Orders') }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ __('Production Orders') }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">{{ __('Factory production against Proforma Invoice lines — raw materials consumed per BOM.') }}</p>
            </div>
            @can('production.create')
            <a href="{{ route('production-orders.create') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ __('New Production Order') }}
            </a>
            @endcan
        </div>
    </x-slot>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        @foreach (\App\Models\ProductionOrder::STATUSES as $key => $label)
        <a href="{{ route('production-orders.index', ['status' => $key]) }}"
           class="rounded-2xl bg-white p-4 shadow-sm ring-1 {{ $status === $key ? 'ring-accent-500' : 'ring-slate-200' }} hover:ring-accent-400">
            <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __($label) }}</span>
            <span class="mt-1 block text-2xl font-bold text-brand-900">{{ $counts[$key] ?? 0 }}</span>
        </a>
        @endforeach
    </div>

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-6">
        <form method="GET" action="{{ route('production-orders.index') }}" class="flex flex-wrap items-end gap-4">
            <div class="w-52">
                <x-input-label for="site_id" :value="__('Site')" />
                <div class="mt-1">
                    <x-searchable-select name="site_id" :options="$sites" :selected="$siteId" placeholder="{{ __('All sites') }}" :auto-submit="true" />
                </div>
            </div>
            <div class="w-48">
                <x-input-label for="status" :value="__('Status')" />
                <select id="status" name="status" onchange="this.form.submit()"
                        class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach (\App\Models\ProductionOrder::STATUSES as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-1 min-w-[200px]">
                <x-input-label for="q" :value="__('Search')" />
                <input type="search" id="q" name="q" value="{{ $q }}" placeholder="{{ __('Order no., product or PI…') }}"
                       class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
            </div>
            <button type="submit" class="rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">{{ __('Filter') }}</button>
        </form>
    </div>

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full min-w-[960px] text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('Production Order') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Product') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Sales Order / PI') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Site') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Quantity') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Due') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Status') }}</th>
                    <th class="px-5 py-3 font-semibold"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($orders as $order)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $order->production_no }}</span>
                        <span class="block text-xs text-slate-400">{{ $order->created_at->format('d M, Y') }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-700">
                        {{ $order->items->first()?->label() ?? '—' }}
                        @if ($order->items->count() > 1)
                        <span class="block text-xs text-slate-400">{{ __('+ :count more lines', ['count' => $order->items->count() - 1]) }}</span>
                        @endif
                    </td>
                    <td class="px-5 py-3 text-slate-600">
                        {{ $order->sourceNo() }}
                        <span class="block text-xs text-slate-400">{{ $order->buyer()?->name }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ $order->site->name }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">
                        {{ rtrim(rtrim(number_format($order->totalQuantity(), 4), '0'), '.') }} {{ $order->items->count() === 1 ? ($order->items->first()->product->stockUnit->short_name ?? '') : '' }}
                        @if ($order->status === 'completed' && $order->totalProduced() !== $order->totalQuantity())
                            <span class="block text-xs font-normal text-slate-400">{{ __('Produced') }} {{ rtrim(rtrim(number_format($order->totalProduced(), 4), '0'), '.') }}</span>
                        @endif
                    </td>
                    <td class="px-5 py-3 {{ $order->isOpen() && $order->due_date?->isPast() ? 'font-semibold text-rose-600' : 'text-slate-600' }}">{{ $order->due_date?->format('d M, Y') ?? '—' }}</td>
                    <td class="px-5 py-3">
                        <x-status-badge :label="$order->status_label" :color="\App\Models\ProductionOrder::STATUS_COLORS[$order->status] ?? 'slate'" />
                    </td>
                    <td class="px-5 py-3 text-right">
                        <span class="inline-flex items-center gap-3">
                            <a href="{{ route('production-orders.print', $order) }}" target="_blank" class="text-slate-400 hover:text-brand-800" title="{{ __('Print') }}" aria-label="{{ __('Print') }}"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z"/></svg></a>
                            <a href="{{ route('production-orders.show', $order) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ __('View') }}</a>
                        </span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-5 py-10 text-center text-slate-400">{{ __('No production orders yet.') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>

        @if ($orders->hasPages())
        <div class="border-t border-slate-100 px-5 py-4">
            {{ $orders->links() }}
        </div>
        @endif
    </div>
</x-app-layout>
