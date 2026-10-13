<x-app-layout>
    <x-slot name="title">{{ __('Print') }} — {{ $invoice->pi_no }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('Proforma Invoice') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ $invoice->pi_no }}</p>
        </div>
    </x-slot>

    <div class="no-print mb-4 flex justify-end gap-3">
        <a href="{{ route('proforma-invoices.show', $invoice) }}"
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
            'documentTitle' => __('Proforma Invoice'),
            'documentNo' => $invoice->pi_no,
            'documentDate' => $invoice->pi_date,
        ])

        <div class="mt-6 grid grid-cols-2 gap-6 text-sm">
            <div>
                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Buyer') }}</p>
                <p class="font-semibold text-slate-800">{{ $invoice->party->name }}</p>
                @if ($invoice->party->contact_person ?? null)<p class="text-slate-600">{{ __('Attn.') }} {{ $invoice->party->contact_person }}</p>@endif
                @if ($invoice->party->phone)<p class="text-slate-600">{{ $invoice->party->phone }}</p>@endif
                @if ($invoice->party->address)<p class="text-slate-500">{{ $invoice->party->address }}</p>@endif
            </div>
            <div class="text-right space-y-0.5">
                @if ($invoice->salesOrder)
                <p class="text-slate-600"><span class="text-slate-400">{{ __('Order Ref.') }}:</span> {{ $invoice->salesOrder->order_no }}{{ $invoice->salesOrder->buyer_reference ? ' / '.$invoice->salesOrder->buyer_reference : '' }}</p>
                @endif
                @if ($invoice->valid_until)
                <p class="text-slate-600"><span class="text-slate-400">{{ __('Valid Until') }}:</span> {{ $invoice->valid_until->format('d M, Y') }}</p>
                @endif
                @if ($invoice->delivery_date)
                <p class="text-slate-600"><span class="text-slate-400">{{ __('Delivery Date') }}:</span> {{ $invoice->delivery_date->format('d M, Y') }}</p>
                @endif
                @if ($invoice->hasLc())
                <p class="text-slate-600"><span class="text-slate-400">{{ __('LC No.') }}:</span> {{ $invoice->lc_no }}{{ $invoice->lc_expiry_date ? ' ('.__('exp.').' '.$invoice->lc_expiry_date->format('d M, Y').')' : '' }}</p>
                @endif
            </div>
        </div>

        <table class="mt-6 w-full text-sm">
            <thead>
                <tr class="border-y-2 border-brand-800 text-left text-xs uppercase tracking-wide text-brand-800">
                    <th class="py-2 pr-2 font-semibold w-10">#</th>
                    <th class="py-2 pr-2 font-semibold">{{ __('Description') }}</th>
                    <th class="py-2 px-2 font-semibold text-right">{{ __('Quantity') }}</th>
                    <th class="py-2 px-2 font-semibold text-right">{{ __('Unit Price') }}</th>
                    <th class="py-2 pl-2 font-semibold text-right">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @foreach ($invoice->items as $i => $item)
                <tr>
                    <td class="py-2 pr-2 text-slate-500">{{ $i + 1 }}</td>
                    <td class="py-2 pr-2">
                        <span class="font-semibold text-slate-800">{{ $item->label() }}</span>
                        <span class="block text-xs text-slate-400">{{ $item->productVariant->sku ?? $item->product->sku }}</span>
                    </td>
                    <td class="py-2 px-2 text-right text-slate-700">{{ rtrim(rtrim(number_format($item->quantity, 4), '0'), '.') }} {{ $item->product->stockUnit->short_name ?? '' }}</td>
                    <td class="py-2 px-2 text-right text-slate-700">{{ number_format($item->unit_price, 2) }}</td>
                    <td class="py-2 pl-2 text-right font-semibold text-slate-800">{{ number_format($item->subtotal, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-slate-300">
                    <td colspan="4" class="pt-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Subtotal') }}</td>
                    <td class="pt-3 text-right font-semibold text-slate-800">{{ number_format($invoice->subtotal_amount, 2) }}</td>
                </tr>
                @if ((float) $invoice->discount_amount > 0)
                <tr>
                    <td colspan="4" class="pt-1 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Discount') }}</td>
                    <td class="pt-1 text-right text-slate-700">− {{ number_format($invoice->discount_amount, 2) }}</td>
                </tr>
                @endif
                <tr>
                    <td colspan="4" class="pt-1 text-right text-xs font-bold uppercase tracking-wide text-brand-800">{{ __('Total') }}</td>
                    <td class="pt-1 text-right text-lg font-black text-brand-900">{{ number_format($invoice->total_amount, 2) }}</td>
                </tr>
            </tfoot>
        </table>

        <p class="mt-4 text-xs italic text-slate-600">
            {{ __('In Words') }}: {{ \App\Support\AmountInWords::convert((float) $invoice->total_amount, $company->currency_label) }}
        </p>

        @if ($invoice->terms)
        <div class="mt-6 text-sm">
            <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Terms & Conditions') }}</p>
            <p class="whitespace-pre-line text-slate-700">{{ $invoice->terms }}</p>
        </div>
        @endif

        <div class="mt-16 grid grid-cols-2 gap-16 text-center text-xs text-slate-500 print-footer">
            <div class="border-t border-slate-400 pt-2">{{ __('Buyer\'s Acceptance') }}</div>
            <div class="border-t border-slate-400 pt-2">{{ __('For :company', ['company' => $company->name]) }}</div>
        </div>
    </div>

    @include('admin.documents._print-styles')
</x-app-layout>
