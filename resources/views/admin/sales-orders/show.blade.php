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
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Buyer') }}</span>
                <span class="font-semibold text-slate-800">{{ $order->party->name }}</span>
            </div>
            <x-status-badge size="lg" :label="$order->status_label" :color="\App\Models\SalesOrder::STATUS_COLORS[$order->status] ?? 'slate'" />
        </div>

        <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Order Date') }}</span>
                <span class="block text-slate-700">{{ $order->order_date->format('d M, Y') }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Requested Delivery') }}</span>
                <span class="block text-slate-700">{{ $order->delivery_date?->format('d M, Y') ?? '—' }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Sales Person') }}</span>
                <span class="block text-slate-700">{{ $order->salesPerson->name ?? '—' }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Buyer Reference') }}</span>
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
        @endphp
        @if ($canEdit || $canDelete || ($canApprove && in_array($order->status, ['pending', 'confirmed'], true)))
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
        <table class="w-full min-w-[760px] text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('Product') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Type') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Quantity') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Unit Price') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Subtotal') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($order->items as $item)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $item->product->name }}{{ $item->productVariant ? ' — '.$item->productVariant->label : '' }}</span>
                        <span class="block text-xs text-slate-400">{{ $item->productVariant->sku ?? $item->product->sku }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ __(\App\Models\Product::TYPES[$item->product->product_type] ?? '—') }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ rtrim(rtrim(number_format($item->quantity, 4), '0'), '.') }} {{ $item->product->stockUnit->short_name ?? '' }}</td>
                    <td class="px-5 py-3 text-right text-slate-600">{{ number_format($item->unit_price, 2) }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($item->subtotal, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t border-slate-200 bg-slate-50">
                <tr>
                    <td colspan="4" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Subtotal') }}</td>
                    <td class="px-5 py-2 text-right font-semibold text-slate-800">{{ number_format($order->subtotal_amount, 2) }}</td>
                </tr>
                @if ((float) $order->discount_amount > 0)
                <tr>
                    <td colspan="4" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Discount') }}</td>
                    <td class="px-5 py-2 text-right text-slate-600">− {{ number_format($order->discount_amount, 2) }}</td>
                </tr>
                @endif
                <tr>
                    <td colspan="4" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total') }}</td>
                    <td class="px-5 py-2 text-right text-base font-bold text-brand-900">{{ number_format($order->total_amount, 2) }}</td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</x-app-layout>
