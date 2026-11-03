<x-app-layout>
    <x-slot name="title">{{ __('Purchase Orders') }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ __('Purchase Orders') }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">{{ __('Orders placed against approved requisitions or straight from Sales Orders, and the goods received for them.') }}</p>
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
                        {{ $purchase->sourceNo() }}
                        <span class="block text-xs text-slate-400">{{ $purchase->requisition->requisition_no ?? __('Direct from order') }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ $purchase->site->name }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($purchase->total_amount, 2) }}</td>
                    <td class="px-5 py-3">
                        <x-status-badge :label="$purchase->status_label" :color="\App\Models\Purchase::STATUS_COLORS[$purchase->status] ?? 'slate'" />
                    </td>
                    <td class="px-5 py-3 text-right">
                        <div class="inline-flex items-center justify-end gap-3">
                            <a href="{{ route('purchases.print', $purchase) }}" target="_blank" class="text-slate-400 hover:text-brand-800" title="{{ __('Print') }}" aria-label="{{ __('Print') }}">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z"/></svg>
                            </a>
                            <a href="{{ route('purchases.show', $purchase) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ __('View') }}</a>
                        </div>
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
