<x-app-layout>
    <x-slot name="title">{{ $purchase->purchase_no }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ $purchase->purchase_no }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">{{ __('Purchase Order to :vendor', ['vendor' => $purchase->vendorName()]) }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('purchases.print', $purchase) }}" target="_blank"
                   class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                    {{ __('Print') }}
                </a>
                <a href="{{ route('purchases.index') }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                    {{ __('Back') }}
                </a>
            </div>
        </div>
    </x-slot>

    @php
        $user = auth()->user();
        $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 4), '0'), '.') ?: '0';
    @endphp

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6 mb-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="text-sm">
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Vendor') }}</span>
                <span class="font-semibold text-slate-800">{{ $purchase->vendorName() }}</span>
                @if ($purchase->isOneTimeVendor())
                    <span class="text-slate-500">· {{ __('One-time vendor') }}{{ $purchase->vendor_phone ? ' · '.$purchase->vendor_phone : '' }}</span>
                @endif
            </div>
            <x-status-badge size="lg" :label="$purchase->status_label" :color="\App\Models\Purchase::STATUS_COLORS[$purchase->status] ?? 'slate'" />
        </div>

        <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Proforma Invoice') }}</span>
                @can('sales.view')
                <a href="{{ route('proforma-invoices.show', $purchase->proformaInvoice) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ $purchase->proformaInvoice->pi_no }}</a>
                @else
                <span class="block text-slate-700">{{ $purchase->proformaInvoice->pi_no }}</span>
                @endcan
                <span class="block text-xs text-slate-400">{{ $purchase->proformaInvoice->party->name }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Requisition') }}</span>
                <a href="{{ route('purchase-requisitions.show', $purchase->requisition) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ $purchase->requisition->requisition_no }}</a>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Order Date') }}</span>
                <span class="block text-slate-700">{{ $purchase->order_date->format('d M, Y') }} {{ __('by') }} {{ $purchase->creator->name ?? '—' }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Deliver To / Expected') }}</span>
                <span class="block text-slate-700">{{ $purchase->site->name }} · {{ $purchase->expected_date?->format('d M, Y') ?? '—' }}</span>
            </div>
            @if ($purchase->note)
            <div class="sm:col-span-2">
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Note') }}</span>
                <span class="block whitespace-pre-line text-slate-700">{{ $purchase->note }}</span>
            </div>
            @endif
        </div>

        @if ($purchase->isReceivable())
        <div class="mt-5 flex flex-wrap gap-3 border-t border-slate-100 pt-5">
            @can('purchases.receive')
            <a href="{{ route('purchases.receive', $purchase) }}"
               class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0-3-3m3 3 3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/></svg>
                {{ __('Receive Goods') }}
            </a>
            @endcan
            @can('purchases.edit')
            @if ($purchase->status === 'pending')
            <a href="{{ route('purchases.edit', $purchase) }}"
               class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                {{ __('Edit') }}
            </a>
            @endif
            <form method="POST" action="{{ route('purchases.cancel', $purchase) }}" class="ml-auto"
                  onsubmit="return confirm('{{ __('Cancel :no? Anything not yet received will no longer be expected.', ['no' => $purchase->purchase_no]) }}');">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-100">
                    {{ __('Cancel Order') }}
                </button>
            </form>
            @endcan
        </div>
        @endif
    </div>

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
        <div class="overflow-x-auto">
        <table class="w-full min-w-[820px] text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('Item') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Ordered') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Received') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Remaining') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Unit Cost') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Subtotal') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($purchase->items as $item)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $item->label() }}</span>
                        <span class="block text-xs text-slate-400">{{ $item->productVariant->sku ?? $item->product->sku }}</span>
                    </td>
                    <td class="px-5 py-3 text-right text-slate-700">{{ $qty($item->quantity) }} {{ $item->product->stockUnit->short_name ?? '' }}</td>
                    <td class="px-5 py-3 text-right text-slate-600">{{ $qty($item->received_quantity) }}</td>
                    <td class="px-5 py-3 text-right font-semibold {{ $item->remaining() > 0 ? 'text-amber-600' : 'text-emerald-600' }}">{{ $qty($item->remaining()) }}</td>
                    <td class="px-5 py-3 text-right text-slate-600">{{ number_format($item->unit_cost, 2) }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($item->subtotal, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t border-slate-200 bg-slate-50">
                <tr>
                    <td colspan="5" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Order Total') }}</td>
                    <td class="px-5 py-2 text-right text-base font-bold text-brand-900">{{ number_format($purchase->total_amount, 2) }}</td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="px-6 pt-5 pb-3">
            <h3 class="font-bold text-brand-900">{{ __('Goods Receipts') }}</h3>
            <p class="text-xs text-slate-400">{{ __('Each receipt added stock at its location and posted the purchase cost to Accounts.') }}</p>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-sm">
            <tbody class="divide-y divide-slate-100 border-t border-slate-100">
                @forelse ($purchase->receipts as $receipt)
                <tr class="hover:bg-slate-50 align-top">
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $receipt->receipt_no }}</span>
                        <span class="block text-xs text-slate-400">{{ $receipt->received_date->format('d M, Y') }} · {{ $receipt->receivedBy->name ?? '—' }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ __('Received at') }} <span class="font-semibold text-slate-700">{{ $receipt->site->name }}</span></td>
                    <td class="px-5 py-3 text-slate-600">
                        @foreach ($receipt->items as $line)
                            <span class="block">{{ $line->purchaseItem->product->name }} — {{ $qty($line->quantity) }}</span>
                        @endforeach
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ $receipt->paidFrom ? __('Paid from :account', ['account' => $receipt->paidFrom->name]) : __('Accounts Payable') }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($receipt->total_amount, 2) }}</td>
                </tr>
                @empty
                <tr>
                    <td class="px-5 py-6 text-center text-slate-400">{{ __('Nothing received yet.') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</x-app-layout>
