<x-app-layout>
    <x-slot name="title">{{ $delivery->delivery_no }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ $delivery->delivery_no }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">{{ __('Delivery Challan against :pi', ['pi' => $delivery->proformaInvoice->pi_no]) }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('deliveries.print', $delivery) }}" target="_blank"
                   class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                    {{ __('Print Challan') }}
                </a>
                <a href="{{ route('proforma-invoices.show', $delivery->proformaInvoice) }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                    {{ __('Back to PI') }}
                </a>
            </div>
        </div>
    </x-slot>

    @php $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 4), '0'), '.') ?: '0'; @endphp

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6 mb-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Buyer') }}</span>
                <span class="block font-semibold text-slate-800">{{ $delivery->proformaInvoice->party->name }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Delivered From') }}</span>
                <span class="block text-slate-700">{{ $delivery->site->name }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Delivery Date') }}</span>
                <span class="block text-slate-700">{{ $delivery->delivery_date->format('d M, Y') }} {{ __('by') }} {{ $delivery->creator->name ?? '—' }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Vehicle / Receiver') }}</span>
                <span class="block text-slate-700">{{ $delivery->vehicle_no ?: '—' }} · {{ $delivery->receiver_name ?: '—' }}</span>
            </div>
            @if ($delivery->note)
            <div class="sm:col-span-2">
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Note') }}</span>
                <span class="block whitespace-pre-line text-slate-700">{{ $delivery->note }}</span>
            </div>
            @endif
        </div>
    </div>

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
        <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('Product') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Quantity') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Unit Price') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Value') }}</th>
                    @can('accounts.view')
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Unit Cost') }}</th>
                    @endcan
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($delivery->items as $item)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $item->label() }}</span>
                        <span class="block text-xs text-slate-400">{{ $item->productVariant->sku ?? $item->product->sku }}</span>
                    </td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ $qty($item->quantity) }} {{ $item->product->stockUnit->short_name ?? '' }}</td>
                    <td class="px-5 py-3 text-right text-slate-600">{{ number_format($item->unit_price, 2) }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format((float) $item->quantity * (float) $item->unit_price, 2) }}</td>
                    @can('accounts.view')
                    <td class="px-5 py-3 text-right text-slate-600">{{ number_format($item->unit_cost, 2) }}</td>
                    @endcan
                </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t border-slate-200 bg-slate-50">
                <tr>
                    <td colspan="3" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Delivered Value') }}</td>
                    <td class="px-5 py-2 text-right text-base font-bold text-brand-900">{{ number_format($delivery->sale_amount, 2) }}</td>
                    @can('accounts.view')
                    <td class="px-5 py-2 text-right text-xs text-slate-500">{{ __('Cost') }} {{ number_format($delivery->cost_amount, 2) }}</td>
                    @endcan
                </tr>
            </tfoot>
        </table>
        </div>
    </div>

    @can('accounts.view')
    @if ($delivery->ledgerTransaction)
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="px-6 pt-5 pb-3">
            <h3 class="font-bold text-brand-900">{{ __('Accounts Posting') }} — {{ $delivery->ledgerTransaction->voucher_no }}</h3>
        </div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100 border-t border-slate-100">
                @foreach ($delivery->ledgerTransaction->lines as $line)
                <tr>
                    <td class="px-5 py-2 text-slate-700">{{ $line->account->name }}</td>
                    <td class="px-5 py-2 text-right text-slate-700">{{ (float) $line->debit > 0 ? number_format($line->debit, 2) : '' }}</td>
                    <td class="px-5 py-2 text-right text-slate-700">{{ (float) $line->credit > 0 ? number_format($line->credit, 2) : '' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
    @endcan
</x-app-layout>
