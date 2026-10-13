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
                    <th class="px-5 py-3 font-semibold">{{ __('Proforma Invoice') }}</th>
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
                    <td class="px-5 py-3 text-slate-700">{{ $order->label() }}</td>
                    <td class="px-5 py-3 text-slate-600">
                        {{ $order->proformaInvoice->pi_no }}
                        <span class="block text-xs text-slate-400">{{ $order->proformaInvoice->party->name }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ $order->site->name }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">
                        {{ rtrim(rtrim(number_format($order->quantity, 4), '0'), '.') }} {{ $order->product->stockUnit->short_name ?? '' }}
                        @if ($order->status === 'completed' && (float) $order->produced_quantity !== (float) $order->quantity)
                            <span class="block text-xs font-normal text-slate-400">{{ __('Produced') }} {{ rtrim(rtrim(number_format($order->produced_quantity, 4), '0'), '.') }}</span>
                        @endif
                    </td>
                    <td class="px-5 py-3 {{ $order->isOpen() && $order->due_date?->isPast() ? 'font-semibold text-rose-600' : 'text-slate-600' }}">{{ $order->due_date?->format('d M, Y') ?? '—' }}</td>
                    <td class="px-5 py-3">
                        <x-status-badge :label="$order->status_label" :color="\App\Models\ProductionOrder::STATUS_COLORS[$order->status] ?? 'slate'" />
                    </td>
                    <td class="px-5 py-3 text-right">
                        <a href="{{ route('production-orders.show', $order) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ __('View') }}</a>
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
