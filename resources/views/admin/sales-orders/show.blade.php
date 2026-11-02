<x-app-layout>
    <x-slot name="title">{{ $order->order_no }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ $order->order_no }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">{{ __('Sales Order from :buyer', ['buyer' => $order->party->name]) }}</p>
            </div>
            <a href="{{ route('sales-orders.index') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                {{ __('Back') }}
            </a>
        </div>
    </x-slot>

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6 mb-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="text-sm">
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Customer') }}</span>
                <span class="font-semibold text-slate-800">{{ $order->party->name }}</span>
                @if ($order->buyer_name)
                    <span class="block text-xs text-slate-500">{{ __('Buyer / Brand') }}: {{ $order->buyer_name }}</span>
                @endif
            </div>
            <x-status-badge size="lg" :label="$order->status_label" :color="\App\Models\SalesOrder::STATUS_COLORS[$order->status] ?? 'slate'" />
        </div>

        <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Order Date') }}</span>
                <span class="block text-slate-700">{{ $order->order_date->format('d M, Y') }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Delivery Window') }}</span>
                <span class="block text-slate-700">{{ $order->delivery_start_date?->format('d M, Y') ?? '—' }} → {{ $order->delivery_date?->format('d M, Y') ?? '—' }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Sales Person') }}</span>
                <span class="block text-slate-700">{{ $order->salesPerson->name ?? '—' }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Customer Order No.') }}</span>
                <span class="block text-slate-700">{{ $order->buyer_reference ?: '—' }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Entered By') }}</span>
                <span class="block text-slate-700">{{ $order->creator->name ?? '—' }} · {{ $order->created_at->format('d M, Y') }}</span>
            </div>
            @if ($order->confirmed_at)
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Confirmed') }}</span>
                <span class="block text-slate-700">{{ $order->confirmed_at->format('d M, Y') }} {{ __('by') }} {{ $order->confirmedBy->name ?? '—' }}</span>
            </div>
            @endif
            @if ($order->delivery_terms)
            <div class="sm:col-span-2">
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Delivery Terms') }}</span>
                <span class="block whitespace-pre-line text-slate-700">{{ $order->delivery_terms }}</span>
            </div>
            @endif
            @if ($order->note)
            <div class="sm:col-span-2">
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Note') }}</span>
                <span class="block whitespace-pre-line text-slate-700">{{ $order->note }}</span>
            </div>
            @endif
        </div>

        @php
            $canEdit = $order->isEditable() && auth()->user()->can('sales.edit');
            $canDelete = $order->isEditable() && auth()->user()->can('sales.delete');
            $canApprove = auth()->user()->can('sales.approve');
            $livePi = $order->liveProformaInvoice();
            $canDeliver = $order->canDeliverDirectly() && auth()->user()->can('sales.deliver');
            $anyDelivered = $order->items->contains(fn ($item) => (float) $item->delivered_quantity > 0);
            $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 4), '0'), '.') ?: '0';
        @endphp
        @if ($canEdit || $canDelete || $livePi || $canDeliver || ($canApprove && in_array($order->status, ['pending', 'confirmed'], true)))
        <div class="mt-5 flex flex-wrap gap-3 border-t border-slate-100 pt-5">
            @if ($canEdit)
            <a href="{{ route('sales-orders.edit', $order) }}"
               class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Z"/></svg>
                {{ __('Edit') }}
            </a>
            @endif

            @if ($canApprove && $order->status === 'pending')
            <form method="POST" action="{{ route('sales-orders.confirm', $order) }}"
                  onsubmit="return confirm('{{ __('Confirm :no? It will be locked for editing.', ['no' => $order->order_no]) }}');">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    {{ __('Confirm Order') }}
                </button>
            </form>
            @endif

            @if ($canDeliver)
            <a href="{{ route('deliveries.create', ['source' => 'order', 'sales_order_id' => $order->id]) }}"
               class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>
                {{ __('Create Delivery') }}
            </a>
            @endif

            @if ($canApprove && in_array($order->status, \App\Models\SalesOrder::OPEN_STATUSES, true))
            <a href="{{ route('proforma-invoices.create', ['party_id' => $order->party_id, 'orders' => [$order->id]]) }}"
               class="inline-flex items-center gap-2 rounded-lg {{ $canDeliver ? 'border border-brand-200 bg-white text-brand-800 hover:bg-brand-50' : 'bg-brand-800 text-white hover:bg-brand-700' }} px-4 py-2 text-sm font-semibold">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                    {{ __('Generate Proforma Invoice') }}
            </a>
            @endif

            @if ($livePi)
            <a href="{{ route('proforma-invoices.show', $livePi) }}"
               class="inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100">
                {{ __('View :no', ['no' => $livePi->pi_no]) }}
            </a>
            @endif

            @if ($canApprove && in_array($order->status, ['pending', 'confirmed'], true))
            <form method="POST" action="{{ route('sales-orders.cancel', $order) }}"
                  onsubmit="return confirm('{{ __('Cancel :no?', ['no' => $order->order_no]) }}');">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-100">
                    {{ __('Cancel Order') }}
                </button>
            </form>
            @endif

            @if ($canDelete)
            <form method="POST" action="{{ route('sales-orders.destroy', $order) }}" class="ml-auto"
                  onsubmit="return confirm('{{ __('Delete :no? This cannot be undone.', ['no' => $order->order_no]) }}');">
                @csrf
                @method('DELETE')
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-50">
                    {{ __('Delete') }}
                </button>
            </form>
            @endif
        </div>
        @endif
    </div>

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full min-w-[1000px] text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-4 py-3 font-semibold">{{ __('Style') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('Product') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('Color') }}</th>
                    <th class="px-4 py-3 font-semibold text-right">{{ __('Quantity') }}</th>
                    @if ($anyDelivered)
                    <th class="px-4 py-3 font-semibold text-right">{{ __('Delivered') }}</th>
                    @endif
                    <th class="px-4 py-3 font-semibold text-right">{{ __('Length / Unit (m)') }}</th>
                    <th class="px-4 py-3 font-semibold text-right">{{ __('Unit Price (USD)') }}</th>
                    <th class="px-4 py-3 font-semibold text-right">{{ __('Subtotal (USD)') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('Remarks') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($order->items as $item)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-semibold text-slate-800">{{ $item->style ?: '—' }}</td>
                    <td class="px-4 py-3">
                        <span class="block font-semibold text-slate-800">{{ $item->product->name }}{{ $item->productVariant ? ' — '.$item->productVariant->label : '' }}</span>
                        <span class="block text-xs text-slate-400">{{ $item->productVariant->sku ?? $item->product->sku }} · {{ __(\App\Models\Product::TYPES[$item->product->product_type] ?? '—') }}</span>
                    </td>
                    <td class="px-4 py-3 text-slate-600">{{ $item->color ?: '—' }}</td>
                    <td class="px-4 py-3 text-right font-semibold text-slate-800">{{ rtrim(rtrim(number_format($item->quantity, 4), '0'), '.') }} {{ $item->product->stockUnit->short_name ?? '' }}</td>
                    @if ($anyDelivered)
                    <td class="px-4 py-3 text-right {{ $item->remaining() <= 0 ? 'text-teal-600' : 'text-slate-600' }}">{{ $qty($item->delivered_quantity) }}</td>
                    @endif
                    <td class="px-4 py-3 text-right text-slate-600">{{ $item->length_per_unit !== null ? rtrim(rtrim(number_format($item->length_per_unit, 2), '0'), '.') : '—' }}</td>
                    @if ($item->unit_price === null)
                    <td class="px-4 py-3 text-right text-xs text-amber-600" colspan="2">{{ __('No price yet — set it on the delivery or PI') }}</td>
                    @else
                    <td class="px-4 py-3 text-right text-slate-600">{{ number_format($item->unit_price, 4) }}</td>
                    <td class="px-4 py-3 text-right font-semibold text-slate-800">{{ number_format($item->subtotal, 2) }}</td>
                    @endif
                    <td class="px-4 py-3 text-slate-600">{{ $item->remarks ?: '' }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t border-slate-200 bg-slate-50">
                <tr>
                    <td colspan="{{ $anyDelivered ? 7 : 6 }}" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Subtotal') }}</td>
                    <td class="px-4 py-2 text-right font-semibold text-slate-800">{{ number_format($order->subtotal_amount, 2) }}</td>
                    <td></td>
                </tr>
                @if ((float) $order->discount_amount > 0)
                <tr>
                    <td colspan="{{ $anyDelivered ? 7 : 6 }}" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Discount') }}</td>
                    <td class="px-4 py-2 text-right text-slate-600">− {{ number_format($order->discount_amount, 2) }}</td>
                    <td></td>
                </tr>
                @endif
                <tr>
                    <td colspan="{{ $anyDelivered ? 7 : 6 }}" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total') }}</td>
                    <td class="px-4 py-2 text-right text-base font-bold text-brand-900">USD {{ number_format($order->total_amount, 2) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>

    @if ($order->deliveries->isNotEmpty())
    <div class="mt-4 rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="px-6 pt-5 pb-3">
            <h3 class="font-bold text-brand-900">{{ __('Deliveries') }}</h3>
            <p class="text-xs text-slate-400">{{ __('Challans posted straight against this order.') }}</p>
        </div>
        <table class="w-full text-sm">
            <thead>
                <tr class="border-y border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('Challan') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('From') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Items') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Value (USD)') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('PI') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($order->deliveries as $delivery)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <a href="{{ route('deliveries.show', $delivery) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ $delivery->delivery_no }}</a>
                        <span class="block text-xs text-slate-400">{{ $delivery->delivery_date->format('d M, Y') }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ $delivery->site->name }}</td>
                    <td class="px-5 py-3 text-right text-slate-600">{{ $delivery->items_count }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($delivery->sale_amount_usd, 2) }}</td>
                    <td class="px-5 py-3 text-slate-600">{{ $delivery->proformaInvoice->pi_no ?? '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</x-app-layout>
