<x-app-layout>
    <x-slot name="title">{{ __('Sales Orders') }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ __('Sales Orders') }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">
                    @if (auth()->user()->seesOnlyOwnSales())
                        {{ __('Orders from the buyers you are responsible for.') }}
                    @else
                        {{ __('Orders received from buyers — confirm them to generate a Proforma Invoice.') }}
                    @endif
                </p>
            </div>
            @can('sales.create')
            <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('sales-orders.import') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-brand-200 bg-white px-4 py-2 text-sm font-semibold text-brand-800 hover:bg-brand-50">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
                {{ __('Import from Excel') }}
            </a>
            <a href="{{ route('sales-orders.create') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ __('New Sales Order') }}
            </a>
            </div>
            @endcan
        </div>
    </x-slot>

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-6">
        <form method="GET" action="{{ route('sales-orders.index') }}" class="flex flex-wrap items-end gap-4">
            <div class="w-56">
                <x-input-label for="party_id" :value="__('Customer')" />
                <div class="mt-1">
                    <x-searchable-select name="party_id" :options="$customers" :selected="$partyId"
                                          placeholder="{{ __('All buyers') }}" :auto-submit="true" />
                </div>
            </div>
            <div class="w-48">
                <x-input-label for="status" :value="__('Status')" />
                <select id="status" name="status" onchange="this.form.submit()"
                        class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach (\App\Models\SalesOrder::STATUSES as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-1 min-w-[200px]">
                <x-input-label for="q" :value="__('Search')" />
                <input type="search" id="q" name="q" value="{{ $q }}" placeholder="{{ __('Order no. or buyer reference…') }}"
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
                    <th class="px-5 py-3 font-semibold">{{ __('Order') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Customer') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Sales Person') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Delivery By') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Items') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Total') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Status') }}</th>
                    <th class="px-5 py-3 font-semibold"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($orders as $order)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $order->order_no }}</span>
                        <span class="block text-xs text-slate-400">{{ $order->order_date->format('d M, Y') }}{{ $order->buyer_reference ? ' · '.$order->buyer_reference : '' }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ $order->party->name }}</td>
                    <td class="px-5 py-3 text-slate-600">{{ $order->salesPerson->name ?? '—' }}</td>
                    <td class="px-5 py-3 text-slate-600">{{ $order->delivery_date?->format('d M, Y') ?? '—' }}</td>
                    <td class="px-5 py-3 text-right text-slate-600">{{ $order->items_count }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($order->total_amount, 2) }}</td>
                    <td class="px-5 py-3">
                        <x-status-badge :label="$order->status_label" :color="\App\Models\SalesOrder::STATUS_COLORS[$order->status] ?? 'slate'" />
                    </td>
                    <td class="px-5 py-3 text-right">
                        <div class="inline-flex items-center justify-end gap-3">
                            <a href="{{ route('sales-orders.print', $order) }}" target="_blank" class="text-slate-400 hover:text-brand-800" title="{{ __('Print') }}" aria-label="{{ __('Print') }}">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z"/></svg>
                            </a>
                            <a href="{{ route('sales-orders.show', $order) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ __('View') }}</a>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-5 py-10 text-center text-slate-400">{{ __('No sales orders yet.') }}</td>
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
