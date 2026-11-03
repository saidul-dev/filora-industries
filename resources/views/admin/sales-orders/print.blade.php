<x-app-layout>
    <x-slot name="title">{{ __('Print') }} — {{ $order->order_no }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('Sales Order') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ $order->order_no }}</p>
        </div>
    </x-slot>

    <div class="no-print mb-4 flex justify-end gap-3">
        <a href="{{ route('sales-orders.show', $order) }}"
           class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">
            {{ __('Back') }}
        </a>
        <button type="button" onclick="window.print()"
                class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
            {{ __('Print') }}
        </button>
    </div>

    @php $unpriced = $order->items->contains(fn ($item) => $item->unit_price === null); @endphp

    <div class="print-area mx-auto max-w-4xl rounded-2xl bg-white p-8 ring-1 ring-slate-200">
        @include('admin.documents._letterhead', [
            'documentTitle' => __('Sales Order'),
            'documentNo' => $order->order_no,
            'documentDate' => $order->order_date,
        ])

        <div class="mt-6 grid grid-cols-2 gap-6 text-sm">
            <div>
                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Customer') }}</p>
                <p class="font-semibold text-slate-800">{{ $order->party->name }}</p>
                @if ($order->party->contact_person ?? null)<p class="text-slate-600">{{ __('Attn.') }} {{ $order->party->contact_person }}</p>@endif
                @if ($order->party->phone)<p class="text-slate-600">{{ $order->party->phone }}</p>@endif
                @if ($order->party->address)<p class="text-slate-500">{{ $order->party->address }}</p>@endif
            </div>
            <div class="text-right space-y-0.5">
                @if ($order->buyer_reference)
                <p class="text-slate-600"><span class="text-slate-400">{{ __('Customer Order No.') }}:</span> {{ $order->buyer_reference }}</p>
                @endif
                @if ($order->buyer_name)
                <p class="text-slate-600"><span class="text-slate-400">{{ __('Buyer') }}:</span> {{ $order->buyer_name }}</p>
                @endif
                @if ($order->delivery_start_date || $order->delivery_date)
                <p class="text-slate-600"><span class="text-slate-400">{{ __('Delivery') }}:</span> {{ $order->delivery_start_date?->format('d M, Y') ?? '—' }} → {{ $order->delivery_date?->format('d M, Y') ?? '—' }}</p>
                @endif
                <p class="text-slate-600"><span class="text-slate-400">{{ __('Status') }}:</span> {{ __($order->status_label) }}</p>
            </div>
        </div>

        <table class="mt-6 w-full text-sm">
            <thead>
                <tr class="border-y-2 border-brand-800 text-left text-xs uppercase tracking-wide text-brand-800">
                    <th class="py-2 pr-2 font-semibold w-8">#</th>
                    <th class="py-2 pr-2 font-semibold">{{ __('Description') }}</th>
                    <th class="py-2 px-2 font-semibold">{{ __('Style') }}</th>
                    <th class="py-2 px-2 font-semibold">{{ __('Color') }}</th>
                    <th class="py-2 px-2 font-semibold text-right">{{ __('Quantity') }}</th>
                    <th class="py-2 px-2 font-semibold text-right">{{ __('Length (m)') }}</th>
                    <th class="py-2 px-2 font-semibold text-right">{{ __('Unit Price (USD)') }}</th>
                    <th class="py-2 px-2 font-semibold text-right">{{ __('Amount (USD)') }}</th>
                    <th class="py-2 pl-2 font-semibold">{{ __('Remarks') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @foreach ($order->items as $i => $item)
                <tr>
                    <td class="py-2 pr-2 text-slate-500">{{ $i + 1 }}</td>
                    <td class="py-2 pr-2">
                        <span class="font-semibold text-slate-800">{{ $item->label() }}</span>
                        <span class="block text-xs text-slate-400">{{ $item->productVariant->sku ?? $item->product->sku }}</span>
                    </td>
                    <td class="py-2 px-2 font-semibold text-slate-800">{{ $item->style }}</td>
                    <td class="py-2 px-2 text-slate-700">{{ $item->color }}</td>
                    <td class="py-2 px-2 text-right text-slate-700">{{ rtrim(rtrim(number_format($item->quantity, 4), '0'), '.') }} {{ $item->product->stockUnit->short_name ?? '' }}</td>
                    <td class="py-2 px-2 text-right text-slate-700">{{ $item->length_per_unit !== null ? rtrim(rtrim(number_format($item->length_per_unit, 2), '0'), '.') : '' }}</td>
                    <td class="py-2 px-2 text-right text-slate-700">{{ $item->unit_price !== null ? number_format($item->unit_price, 4) : '—' }}</td>
                    <td class="py-2 px-2 text-right font-semibold text-slate-800">{{ $item->unit_price !== null ? number_format($item->subtotal, 2) : '—' }}</td>
                    <td class="py-2 pl-2 text-xs text-slate-600">{{ $item->remarks }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-slate-300">
                    <td colspan="7" class="pt-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Subtotal') }}</td>
                    <td class="pt-3 px-2 text-right font-semibold text-slate-800">{{ number_format($order->subtotal_amount, 2) }}</td>
                    <td></td>
                </tr>
                @if ((float) $order->discount_amount > 0)
                <tr>
                    <td colspan="7" class="pt-1 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Discount') }}</td>
                    <td class="pt-1 px-2 text-right text-slate-700">− {{ number_format($order->discount_amount, 2) }}</td>
                    <td></td>
                </tr>
                @endif
                <tr>
                    <td colspan="7" class="pt-1 text-right text-xs font-bold uppercase tracking-wide text-brand-800">{{ __('Total') }}</td>
                    <td class="pt-1 px-2 text-right text-lg font-black text-brand-900">USD {{ number_format($order->total_amount, 2) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        @if ($unpriced)
        <p class="mt-3 text-xs text-slate-500">{{ __('Lines marked — are not priced yet; the total covers priced lines only. Final prices are set on the Proforma Invoice.') }}</p>
        @else
        <p class="mt-4 text-xs italic text-slate-600">
            {{-- Sales documents are always priced in USD, whatever the company currency. --}}
            {{ __('In Words') }}: {{ \App\Support\AmountInWords::convert((float) $order->total_amount, 'US Dollar') }}
        </p>
        @endif

        @if ($order->delivery_terms)
        <div class="mt-6 text-sm">
            <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Delivery Terms') }}</p>
            <p class="whitespace-pre-line text-slate-700">{{ $order->delivery_terms }}</p>
        </div>
        @endif

        <div class="mt-16 grid grid-cols-2 gap-16 text-center text-xs text-slate-500 print-footer">
            <div class="border-t border-slate-400 pt-2">{{ __('Customer\'s Confirmation') }}</div>
            <div class="border-t border-slate-400 pt-2">{{ __('For :company', ['company' => $company->name]) }}</div>
        </div>
    </div>

    @include('admin.documents._print-styles')
</x-app-layout>
