<x-app-layout>
    <x-slot name="title">{{ __('Purchase Orders') }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ __('Purchase Orders') }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">{{ __('Orders placed against approved requisitions, and the goods received for them.') }}</p>
            </div>
            @can('purchases.create')
            <a href="{{ route('purchases.create') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ __('New Purchase Order') }}
                @if ($awaitingOrder)
                <span class="rounded-full bg-accent-500 px-2 py-0.5 text-[11px] font-bold text-brand-950">{{ $awaitingOrder }}</span>
                @endif
            </a>
            @endcan
        </div>
    </x-slot>

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-6">
        <form method="GET" action="{{ route('purchases.index') }}" class="flex flex-wrap items-end gap-4">
            <div class="w-52">
                <x-input-label for="status" :value="__('Status')" />
                <select id="status" name="status" onchange="this.form.submit()"
                        class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach (\App\Models\Purchase::STATUSES as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-1 min-w-[200px]">
                <x-input-label for="q" :value="__('Search')" />
                <input type="search" id="q" name="q" value="{{ $q }}" placeholder="{{ __('PO no. or vendor…') }}"
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
                    <th class="px-5 py-3 font-semibold">{{ __('Purchase Order') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Vendor') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('PI / Requisition') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Deliver To') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Total') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Status') }}</th>
                    <th class="px-5 py-3 font-semibold"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($purchases as $purchase)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $purchase->purchase_no }}</span>
                        <span class="block text-xs text-slate-400">{{ $purchase->order_date->format('d M, Y') }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">
                        {{ $purchase->vendorName() }}
                        @if ($purchase->isOneTimeVendor())<span class="block text-xs text-slate-400">{{ __('One-time vendor') }}</span>@endif
                    </td>
                    <td class="px-5 py-3 text-slate-600">
                        {{ $purchase->proformaInvoice->pi_no }}
                        <span class="block text-xs text-slate-400">{{ $purchase->requisition->requisition_no }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ $purchase->site->name }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($purchase->total_amount, 2) }}</td>
                    <td class="px-5 py-3">
                        <x-status-badge :label="$purchase->status_label" :color="\App\Models\Purchase::STATUS_COLORS[$purchase->status] ?? 'slate'" />
                    </td>
                    <td class="px-5 py-3 text-right">
                        <a href="{{ route('purchases.show', $purchase) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ __('View') }}</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-5 py-10 text-center text-slate-400">{{ __('No purchase orders yet.') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>

        @if ($purchases->hasPages())
        <div class="border-t border-slate-100 px-5 py-4">
            {{ $purchases->links() }}
        </div>
        @endif
    </div>
</x-app-layout>
