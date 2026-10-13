<x-app-layout>
    <x-slot name="title">{{ __('Print') }} — {{ $purchase->purchase_no }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('Purchase Order') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ $purchase->purchase_no }}</p>
        </div>
    </x-slot>

    <div class="no-print mb-4 flex justify-end gap-3">
        <a href="{{ route('purchases.show', $purchase) }}"
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
            'documentTitle' => __('Purchase Order'),
            'documentNo' => $purchase->purchase_no,
            'documentDate' => $purchase->order_date,
        ])

        <div class="mt-6 grid grid-cols-2 gap-6 text-sm">
            <div>
                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Vendor') }}</p>
                <p class="font-semibold text-slate-800">{{ $purchase->vendorName() }}</p>
                @php $phone = $purchase->party->phone ?? $purchase->vendor_phone; @endphp
                @if ($phone)<p class="text-slate-600">{{ $phone }}</p>@endif
                @if ($purchase->party?->address)<p class="text-slate-500">{{ $purchase->party->address }}</p>@endif
            </div>
            <div class="text-right">
                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Deliver To') }}</p>
                <p class="font-semibold text-slate-800">{{ $purchase->site->name }}</p>
                @if ($purchase->site->address)<p class="text-slate-500">{{ $purchase->site->address }}</p>@endif
                @if ($purchase->expected_date)<p class="text-slate-600">{{ __('Expected by') }} {{ $purchase->expected_date->format('d M, Y') }}</p>@endif
                <p class="text-slate-500">{{ __('Ref.') }} {{ $purchase->proformaInvoice->pi_no }} / {{ $purchase->requisition->requisition_no }}</p>
            </div>
        </div>

        <table class="mt-6 w-full text-sm">
            <thead>
                <tr class="border-y-2 border-brand-800 text-left text-xs uppercase tracking-wide text-brand-800">
                    <th class="py-2 pr-2 font-semibold w-10">#</th>
                    <th class="py-2 pr-2 font-semibold">{{ __('Item') }}</th>
                    <th class="py-2 px-2 font-semibold text-right">{{ __('Quantity') }}</th>
                    <th class="py-2 px-2 font-semibold text-right">{{ __('Unit Cost') }}</th>
                    <th class="py-2 pl-2 font-semibold text-right">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @foreach ($purchase->items as $i => $item)
                <tr>
                    <td class="py-2 pr-2 text-slate-500">{{ $i + 1 }}</td>
                    <td class="py-2 pr-2">
                        <span class="font-semibold text-slate-800">{{ $item->label() }}</span>
                        <span class="block text-xs text-slate-400">{{ $item->productVariant->sku ?? $item->product->sku }}</span>
                    </td>
                    <td class="py-2 px-2 text-right text-slate-700">{{ rtrim(rtrim(number_format($item->quantity, 4), '0'), '.') }} {{ $item->product->stockUnit->short_name ?? '' }}</td>
                    <td class="py-2 px-2 text-right text-slate-700">{{ number_format($item->unit_cost, 2) }}</td>
                    <td class="py-2 pl-2 text-right font-semibold text-slate-800">{{ number_format($item->subtotal, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-slate-300">
                    <td colspan="4" class="pt-3 text-right text-xs font-bold uppercase tracking-wide text-brand-800">{{ __('Total') }}</td>
                    <td class="pt-3 text-right text-lg font-black text-brand-900">{{ number_format($purchase->total_amount, 2) }}</td>
                </tr>
            </tfoot>
        </table>

        @if ($purchase->note)
        <div class="mt-6 text-sm">
            <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Note') }}</p>
            <p class="whitespace-pre-line text-slate-700">{{ $purchase->note }}</p>
        </div>
        @endif

        <div class="mt-16 grid grid-cols-2 gap-16 text-center text-xs text-slate-500 print-footer">
            <div class="border-t border-slate-400 pt-2">{{ __('Prepared By') }}: {{ $purchase->creator->name ?? '' }}</div>
            <div class="border-t border-slate-400 pt-2">{{ __('Authorised Signature') }}</div>
        </div>
    </div>

    @include('admin.documents._print-styles')
</x-app-layout>
