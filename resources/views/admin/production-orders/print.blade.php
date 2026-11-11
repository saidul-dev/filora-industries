<x-app-layout>
    <x-slot name="title">{{ __('Print') }} — {{ $order->production_no }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('Production Order') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ $order->production_no }}</p>
        </div>
    </x-slot>

    @php
        $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 4), '0'), '.') ?: '0';
        $completed = $order->status === 'completed';
    @endphp

    <div class="no-print mb-4 flex justify-end gap-3">
        <a href="{{ route('production-orders.show', $order) }}"
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
            'documentTitle' => __('Production Order'),
            'documentNo' => $order->production_no,
            'documentDate' => $order->created_at,
        ])

        <div class="mt-6 grid grid-cols-2 gap-6 text-sm">
            <div>
                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('For Buyer') }}</p>
                <p class="font-semibold text-slate-800">{{ $order->buyer()?->name ?? '—' }}</p>
                <p class="text-slate-600">{{ $order->proformaInvoice ? __('Proforma Invoice') : __('Sales Order') }}: {{ $order->sourceNo() }}</p>
                @if ($order->salesOrder?->buyer_reference)
                <p class="text-slate-500">{{ __('Customer Order No.') }} {{ $order->salesOrder->buyer_reference }}</p>
                @endif
            </div>
            <div class="text-right">
                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Production Site') }}</p>
                <p class="font-semibold text-slate-800">{{ $order->site->name }}</p>
                <p class="text-slate-600">{{ __('Planned') }}: {{ $order->start_date?->format('d M, Y') ?? '—' }} → {{ $order->due_date?->format('d M, Y') ?? '—' }}</p>
                <p class="text-slate-500">{{ __('Status') }}: {{ __($order->status_label) }}</p>
            </div>
        </div>

        <table class="mt-6 w-full text-sm">
            <thead>
                <tr class="border-y-2 border-brand-800 text-left text-xs uppercase tracking-wide text-brand-800">
                    <th class="py-2 pr-2 font-semibold w-10">#</th>
                    <th class="py-2 pr-2 font-semibold">{{ __('Product') }}</th>
                    <th class="py-2 px-2 font-semibold">{{ __('Style / Color / Size') }}</th>
                    <th class="py-2 px-2 font-semibold text-right">{{ __('Quantity') }}</th>
                    <th class="py-2 pl-2 font-semibold text-right">{{ __('Produced') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @foreach ($order->items as $i => $item)
                @php $unit = $item->product->stockUnit->short_name ?? ''; @endphp
                <tr>
                    <td class="py-2 pr-2 text-slate-500">{{ $i + 1 }}</td>
                    <td class="py-2 pr-2">
                        <span class="font-semibold text-slate-800">{{ $item->label() }}</span>
                        <span class="block text-xs text-slate-400">{{ $item->productVariant->sku ?? $item->product->sku }}</span>
                    </td>
                    <td class="py-2 px-2 text-slate-600">{{ $item->lineDetails() ?: '—' }}</td>
                    <td class="py-2 px-2 text-right font-semibold text-slate-800">{{ $qty($item->quantity) }} {{ $unit }}</td>
                    <td class="py-2 pl-2 text-right text-slate-700">{{ $item->produced_quantity !== null ? $qty($item->produced_quantity).' '.$unit : '' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        @if ($order->materials->isNotEmpty())
        <p class="mt-8 mb-2 text-xs font-bold uppercase tracking-wide text-brand-800">{{ __('Raw Materials Issued') }}</p>
        <table class="w-full text-sm">
            <thead>
                <tr class="border-y border-slate-300 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="py-1.5 pr-2 font-semibold">{{ __('Material') }}</th>
                    @if ($order->items->count() > 1)<th class="py-1.5 px-2 font-semibold">{{ __('For') }}</th>@endif
                    <th class="py-1.5 px-2 font-semibold text-right">{{ __('Per Unit') }}</th>
                    <th class="py-1.5 px-2 font-semibold text-right">{{ __('Quantity') }}</th>
                    <th class="py-1.5 pl-2 font-semibold text-right">{{ __('Cost') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @foreach ($order->materials as $material)
                <tr>
                    <td class="py-1.5 pr-2 text-slate-800">{{ $material->material->name }}</td>
                    @if ($order->items->count() > 1)<td class="py-1.5 px-2 text-slate-600">{{ $material->item?->label() ?? '—' }}</td>@endif
                    <td class="py-1.5 px-2 text-right text-slate-600">{{ $qty($material->quantity_per_unit) }}</td>
                    <td class="py-1.5 px-2 text-right text-slate-700">{{ $qty($material->quantity) }} {{ $material->material->stockUnit->short_name ?? '' }}</td>
                    <td class="py-1.5 pl-2 text-right text-slate-700">{{ number_format((float) $material->quantity * (float) $material->unit_cost, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif

        @if ($order->services->isNotEmpty())
        <p class="mt-8 mb-2 text-xs font-bold uppercase tracking-wide text-brand-800">{{ __('Third-Party Work') }}</p>
        <table class="w-full text-sm">
            <thead>
                <tr class="border-y border-slate-300 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="py-1.5 pr-2 font-semibold">{{ __('Date') }}</th>
                    <th class="py-1.5 px-2 font-semibold">{{ __('Work Done') }}</th>
                    <th class="py-1.5 px-2 font-semibold">{{ __('Supplier') }}</th>
                    <th class="py-1.5 px-2 font-semibold">{{ __('Bill No.') }}</th>
                    <th class="py-1.5 pl-2 font-semibold text-right">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @foreach ($order->services->sortBy('service_date') as $service)
                <tr>
                    <td class="py-1.5 pr-2 text-slate-600">{{ $service->service_date->format('d M, Y') }}</td>
                    <td class="py-1.5 px-2 text-slate-800">{{ $service->service }}{{ $service->item ? ' — '.$service->item->label() : '' }}</td>
                    <td class="py-1.5 px-2 text-slate-700">{{ $service->party->name }}</td>
                    <td class="py-1.5 px-2 text-slate-600">{{ $service->reference_no ?: '—' }}</td>
                    <td class="py-1.5 pl-2 text-right text-slate-700">{{ number_format($service->amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif

        <div class="mt-6 ml-auto w-72 text-sm">
            <div class="flex justify-between py-1 text-slate-600"><span>{{ __('Material Cost') }}</span><span>{{ number_format($order->material_cost, 2) }}</span></div>
            <div class="flex justify-between py-1 text-slate-600"><span>{{ __('Third-Party Cost') }}</span><span>{{ number_format($order->service_cost, 2) }}</span></div>
            <div class="mt-1 flex justify-between border-t-2 border-slate-300 pt-2 font-bold text-brand-900"><span>{{ __('Total Production Cost') }}</span><span>{{ number_format($order->totalCost(), 2) }}</span></div>
        </div>

        @if ($order->note)
        <div class="mt-6 text-sm">
            <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Note') }}</p>
            <p class="whitespace-pre-line text-slate-700">{{ $order->note }}</p>
        </div>
        @endif

        <div class="mt-16 grid grid-cols-3 gap-10 text-center text-xs text-slate-500 print-footer">
            <div class="border-t border-slate-400 pt-2">{{ __('Prepared By') }}: {{ $order->creator->name ?? '' }}</div>
            <div class="border-t border-slate-400 pt-2">{{ __('Production In-charge') }}</div>
            <div class="border-t border-slate-400 pt-2">{{ __('Authorised Signature') }}</div>
        </div>
    </div>

    @include('admin.documents._print-styles')
</x-app-layout>
