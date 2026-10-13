<x-app-layout>
    <x-slot name="title">{{ __('Print') }} — {{ $delivery->delivery_no }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('Delivery Challan') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ $delivery->delivery_no }}</p>
        </div>
    </x-slot>

    <div class="no-print mb-4 flex justify-end gap-3">
        <a href="{{ route('deliveries.show', $delivery) }}"
           class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">
            {{ __('Back') }}
        </a>
        <button type="button" onclick="window.print()"
                class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
            {{ __('Print') }}
        </button>
    </div>

    <div class="print-area mx-auto max-w-4xl rounded-2xl bg-white p-8 ring-1 ring-slate-200">
        @include('admin.documents._letterhead', [
            'documentTitle' => __('Delivery Challan'),
            'documentNo' => $delivery->delivery_no,
            'documentDate' => $delivery->delivery_date,
        ])

        @php $invoice = $delivery->proformaInvoice; @endphp
        <div class="mt-6 grid grid-cols-2 gap-6 text-sm">
            <div>
                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Deliver To') }}</p>
                <p class="font-semibold text-slate-800">{{ $invoice->party->name }}</p>
                @if ($invoice->party->phone)<p class="text-slate-600">{{ $invoice->party->phone }}</p>@endif
                @if ($invoice->party->address)<p class="text-slate-500">{{ $invoice->party->address }}</p>@endif
            </div>
            <div class="text-right space-y-0.5">
                <p class="text-slate-600"><span class="text-slate-400">{{ __('PI No.') }}:</span> {{ $invoice->pi_no }}</p>
                @if ($invoice->salesOrder?->buyer_reference)
                <p class="text-slate-600"><span class="text-slate-400">{{ __('Buyer Ref.') }}:</span> {{ $invoice->salesOrder->buyer_reference }}</p>
                @endif
                @if ($invoice->hasLc())
                <p class="text-slate-600"><span class="text-slate-400">{{ __('LC No.') }}:</span> {{ $invoice->lc_no }}</p>
                @endif
                <p class="text-slate-600"><span class="text-slate-400">{{ __('Dispatched From') }}:</span> {{ $delivery->site->name }}</p>
                @if ($delivery->vehicle_no)
                <p class="text-slate-600"><span class="text-slate-400">{{ __('Vehicle No.') }}:</span> {{ $delivery->vehicle_no }}</p>
                @endif
            </div>
        </div>

        <table class="mt-6 w-full text-sm">
            <thead>
                <tr class="border-y-2 border-brand-800 text-left text-xs uppercase tracking-wide text-brand-800">
                    <th class="py-2 pr-2 font-semibold w-10">#</th>
                    <th class="py-2 pr-2 font-semibold">{{ __('Description') }}</th>
                    <th class="py-2 pl-2 font-semibold text-right">{{ __('Quantity') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @foreach ($delivery->items as $i => $item)
                <tr>
                    <td class="py-2 pr-2 text-slate-500">{{ $i + 1 }}</td>
                    <td class="py-2 pr-2">
                        <span class="font-semibold text-slate-800">{{ $item->label() }}</span>
                        <span class="block text-xs text-slate-400">{{ $item->productVariant->sku ?? $item->product->sku }}</span>
                    </td>
                    <td class="py-2 pl-2 text-right font-semibold text-slate-800">{{ rtrim(rtrim(number_format($item->quantity, 4), '0'), '.') }} {{ $item->product->stockUnit->short_name ?? '' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        @if ($delivery->note)
        <div class="mt-6 text-sm">
            <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Note') }}</p>
            <p class="whitespace-pre-line text-slate-700">{{ $delivery->note }}</p>
        </div>
        @endif

        <div class="mt-16 grid grid-cols-3 gap-10 text-center text-xs text-slate-500 print-footer">
            <div class="border-t border-slate-400 pt-2">{{ __('Prepared By') }}: {{ $delivery->creator->name ?? '' }}</div>
            <div class="border-t border-slate-400 pt-2">{{ __('Authorised Signature') }}</div>
            <div class="border-t border-slate-400 pt-2">{{ __('Received By') }}{{ $delivery->receiver_name ? ': '.$delivery->receiver_name : '' }}</div>
        </div>
    </div>

    @include('admin.documents._print-styles')
</x-app-layout>
