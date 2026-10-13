<x-app-layout>
    <x-slot name="title">{{ __('Deliveries') }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ __('Deliveries') }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">{{ __('Delivery challans posted against Proforma Invoices.') }}</p>
            </div>
            @can('sales.deliver')
            <a href="{{ route('deliveries.create') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ __('New Delivery') }}
            </a>
            @endcan
        </div>
    </x-slot>

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-6">
        <form method="GET" action="{{ route('deliveries.index') }}" class="flex flex-wrap items-end gap-4">
            <div class="w-52">
                <x-input-label for="site_id" :value="__('Delivered From')" />
                <div class="mt-1">
                    <x-searchable-select name="site_id" :options="$sites" :selected="$siteId" placeholder="{{ __('All sites') }}" :auto-submit="true" />
                </div>
            </div>
            <div class="flex-1 min-w-[200px]">
                <x-input-label for="q" :value="__('Search')" />
                <input type="search" id="q" name="q" value="{{ $q }}" placeholder="{{ __('Challan no. or PI no.…') }}"
                       class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
            </div>
            <button type="submit" class="rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">{{ __('Filter') }}</button>
        </form>
    </div>

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full min-w-[860px] text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('Challan') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Proforma Invoice') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('From') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Items') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Value') }}</th>
                    <th class="px-5 py-3 font-semibold"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($deliveries as $delivery)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $delivery->delivery_no }}</span>
                        <span class="block text-xs text-slate-400">{{ $delivery->delivery_date->format('d M, Y') }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">
                        {{ $delivery->proformaInvoice->pi_no }}
                        <span class="block text-xs text-slate-400">{{ $delivery->proformaInvoice->party->name }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ $delivery->site->name }}</td>
                    <td class="px-5 py-3 text-right text-slate-600">{{ $delivery->items_count }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($delivery->sale_amount, 2) }}</td>
                    <td class="px-5 py-3 text-right">
                        <a href="{{ route('deliveries.show', $delivery) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ __('View') }}</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-5 py-10 text-center text-slate-400">{{ __('No deliveries yet.') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>

        @if ($deliveries->hasPages())
        <div class="border-t border-slate-100 px-5 py-4">
            {{ $deliveries->links() }}
        </div>
        @endif
    </div>
</x-app-layout>
