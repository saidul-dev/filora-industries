<x-app-layout>
    <x-slot name="title">{{ $order->order_no }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ $order->order_no }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">{{ __('Sales Order from :buyer', ['buyer' => $order->party->name]) }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('sales-orders.print', $order) }}" target="_blank"
                   class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z"/></svg>
                    {{ __('Print') }}
                </a>
                <a href="{{ route('sales-orders.index') }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                    {{ __('Back') }}
                </a>
            </div>
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

            @if ($order->canSourceDirectly())
            @php
                $toBuyTop = auth()->user()->can('purchases.create') ? $order->purchaseNeeds()->sum('to_buy') : 0;
                $toProduceTop = auth()->user()->can('production.create') ? $order->linesToProduce()->count() : 0;
            @endphp
            <a href="#fulfillment-plan"
               class="inline-flex items-center gap-2 rounded-lg border border-brand-200 bg-white px-4 py-2 text-sm font-semibold text-brand-800 hover:bg-brand-50">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z"/></svg>
                {{ __('Production / Purchase Plan') }}
            </a>
            @if ($toProduceTop > 0)
            <a href="{{ route('production-orders.create', ['source' => 'so-'.$order->id]) }}"
               class="inline-flex items-center gap-2 rounded-lg border border-brand-200 bg-white px-4 py-2 text-sm font-semibold text-brand-800 hover:bg-brand-50">
                {{ __('Create Production Order') }}
                @if ($toProduceTop > 1)<span class="rounded-full bg-brand-50 px-1.5 text-xs text-brand-700">{{ $toProduceTop }}</span>@endif
            </a>
            @endif
            @if ($toBuyTop > 0)
            <a href="{{ route('purchases.create', ['from_sales_order' => $order->id]) }}"
               class="inline-flex items-center gap-2 rounded-lg border border-brand-200 bg-white px-4 py-2 text-sm font-semibold text-brand-800 hover:bg-brand-50">
                {{ __('Create Purchase Order') }}
            </a>
            @endif
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
        @if ($order->status === 'pending')
        <p class="mt-3 text-xs text-slate-400">{{ __('Confirm the order to plan production and purchases, deliver against it or put it on a Proforma Invoice. A pending order stays editable, so nothing is sourced for it yet.') }}</p>
        @endif
        @endif
    </div>

    @php
        $typeNames = ['manufactured' => 'Manufactured', 'direct_purchase' => 'Direct Purchase', 'raw_material' => 'Raw Material'];
        $lineFilterRows = $order->items->map(fn ($item) => [
            'text' => mb_strtolower(implode(' ', array_filter([
                $item->product->name,
                $item->productVariant->label ?? null,
                $item->productVariant->sku ?? $item->product->sku,
                $item->style,
            ]))),
            'type' => $item->product->product_type,
            'color' => (string) $item->color,
            'length' => $item->length_per_unit !== null ? rtrim(rtrim(number_format($item->length_per_unit, 2), '0'), '.') : '',
        ])->values();
        $filterTypes = $order->items->pluck('product.product_type')->unique()->sort()->values();
        $filterColors = $lineFilterRows->pluck('color')->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
        $filterLengths = $lineFilterRows->pluck('length')->filter(fn ($v) => $v !== '')->unique()->sortBy(fn ($v) => (float) $v)->values();
    @endphp
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden"
         x-data="{
            rows: {{ Js::from($lineFilterRows) }},
            q: '', type: '', color: '', length: '',
            show(i) {
                const r = this.rows[i], q = this.q.trim().toLowerCase();
                return (!q || r.text.includes(q))
                    && (!this.type || r.type === this.type)
                    && (!this.color || r.color === this.color)
                    && (!this.length || r.length === this.length);
            },
            get visible() { return this.rows.filter((r, i) => this.show(i)).length; },
            get active() { return this.q || this.type || this.color || this.length; },
            reset() { this.q = ''; this.type = ''; this.color = ''; this.length = ''; },
         }">
        @if ($order->items->count() > 1)
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3">
            <div class="relative min-w-[200px] flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
                <input type="search" x-model="q" placeholder="{{ __('Search product, SKU or style…') }}"
                       class="w-full rounded-lg border-slate-200 py-2 pl-9 text-sm focus:border-brand-500 focus:ring-brand-500">
            </div>
            <select x-model="type" class="rounded-lg border-slate-200 py-2 text-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">{{ __('All types') }}</option>
                @foreach ($filterTypes as $t)
                <option value="{{ $t }}">{{ __($typeNames[$t] ?? $t) }}</option>
                @endforeach
            </select>
            <select x-model="color" class="rounded-lg border-slate-200 py-2 text-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">{{ __('All colors') }}</option>
                @foreach ($filterColors as $c)
                <option value="{{ $c }}">{{ $c }}</option>
                @endforeach
            </select>
            <select x-model="length" class="rounded-lg border-slate-200 py-2 text-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">{{ __('All lengths') }}</option>
                @foreach ($filterLengths as $l)
                <option value="{{ $l }}">{{ $l }} m</option>
                @endforeach
            </select>
            <button type="button" x-show="active" x-cloak @click="reset()"
                    class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100">{{ __('Clear') }}</button>
            <span class="ml-auto text-xs text-slate-400" x-text="`${visible} / ${rows.length} {{ __('lines') }}`"></span>
        </div>
        @endif
        <div class="overflow-x-auto">
        <table class="w-full min-w-[1000px] text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-4 py-3 font-semibold">{{ __('Style') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('Product') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('Type') }}</th>
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
                <tr class="hover:bg-slate-50" x-show="show({{ $loop->index }})">
                    <td class="px-4 py-3 font-semibold text-slate-800">{{ $item->style ?: '—' }}</td>
                    <td class="px-4 py-3">
                        <span class="block font-semibold text-slate-800">{{ $item->product->name }}{{ $item->productVariant ? ' — '.$item->productVariant->label : '' }}</span>
                        <span class="block text-xs text-slate-400">{{ $item->productVariant->sku ?? $item->product->sku }}</span>
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $typeBadge = [
                                'manufactured' => ['Manufactured', 'bg-indigo-50 text-indigo-700'],
                                'direct_purchase' => ['Direct Purchase', 'bg-sky-50 text-sky-700'],
                                'raw_material' => ['Raw Material', 'bg-slate-100 text-slate-600'],
                            ][$item->product->product_type] ?? [$item->product->product_type, 'bg-slate-100 text-slate-600'];
                        @endphp
                        <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $typeBadge[1] }}">{{ __($typeBadge[0]) }}</span>
                        @if (in_array($order->status, \App\Models\SalesOrder::OPEN_STATUSES, true))
                            <span class="mt-1 block whitespace-nowrap text-xs text-slate-500">{{ __('Plan') }}: {{ __($item->fulfillment_label) }}</span>
                        @endif
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
                <tr x-show="visible === 0" x-cloak>
                    <td colspan="{{ $anyDelivered ? 10 : 9 }}" class="px-4 py-8 text-center text-sm text-slate-400">
                        {{ __('No lines match the filter.') }}
                        <button type="button" @click="reset()" class="ml-1 font-semibold text-brand-700 hover:underline">{{ __('Clear') }}</button>
                    </td>
                </tr>
            </tbody>
            <tfoot class="border-t border-slate-200 bg-slate-50">
                <tr>
                    <td colspan="{{ $anyDelivered ? 8 : 7 }}" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Subtotal') }}</td>
                    <td class="px-4 py-2 text-right font-semibold text-slate-800">{{ number_format($order->subtotal_amount, 2) }}</td>
                    <td></td>
                </tr>
                @if ((float) $order->discount_amount > 0)
                <tr>
                    <td colspan="{{ $anyDelivered ? 8 : 7 }}" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Discount') }}</td>
                    <td class="px-4 py-2 text-right text-slate-600">− {{ number_format($order->discount_amount, 2) }}</td>
                    <td></td>
                </tr>
                @endif
                <tr>
                    <td colspan="{{ $anyDelivered ? 8 : 7 }}" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total') }}</td>
                    <td class="px-4 py-2 text-right text-base font-bold text-brand-900">USD {{ number_format($order->total_amount, 2) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>

    @php
        $canPlan = $order->canSourceDirectly();
        $purchaseNeeds = $canPlan ? $order->purchaseNeeds() : collect();
        $toBuy = $purchaseNeeds->sum('to_buy');
        $user = auth()->user();
    @endphp
    @if ($canPlan || $order->productionOrders->isNotEmpty() || $order->purchases->isNotEmpty())
    <div id="fulfillment-plan" class="mt-4 scroll-mt-24 rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="flex flex-wrap items-end justify-between gap-4 px-6 pt-5 pb-4">
            <div>
                <h3 class="font-bold text-brand-900">{{ __('Fulfillment Plan') }}</h3>
                <p class="text-xs text-slate-400">
                    @if ($canPlan)
                        {{ __('Decide how each line is fulfilled, then start production or buy straight from this order. A PI raised later takes over the plan and everything started here.') }}
                    @else
                        {{ __('Production and purchases started from this order. Further sourcing happens on its Proforma Invoice.') }}
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($canPlan && $user->can('production.create') && ($linesToProduce = $order->linesToProduce()->count()) > 0)
                <a href="{{ route('production-orders.create', ['source' => 'so-'.$order->id]) }}"
                   class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    {{ $linesToProduce > 1 ? __('Produce :count lines together', ['count' => $linesToProduce]) : __('Create Production Order') }}
                </a>
                @endif
                @if ($canPlan && $toBuy > 0 && $user->can('purchases.create'))
                <a href="{{ route('purchases.create', ['from_sales_order' => $order->id]) }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-brand-200 bg-white px-4 py-2 text-sm font-semibold text-brand-800 hover:bg-brand-50">
                    {{ __('Create Purchase Order') }}
                </a>
                @endif
            </div>
        </div>

        @if ($canPlan)
        <form method="POST" action="{{ route('sales-orders.plan', $order) }}">
            @csrf
            <div class="overflow-x-auto border-y border-slate-100">
            @php
                // Finished goods on hand across all sites, per product / variant.
                $onHand = \App\Models\StockMovement::query()
                    ->whereIn('product_id', $order->items->pluck('product_id')->unique())
                    ->selectRaw("product_id, product_variant_id, SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) as balance")
                    ->groupBy('product_id', 'product_variant_id')
                    ->get()
                    ->mapWithKeys(fn ($row) => [$row->product_id.':'.($row->product_variant_id ?? '') => (float) $row->balance]);
            @endphp
            <table class="w-full min-w-[1100px] text-sm">
                <thead>
                    <tr class="whitespace-nowrap bg-slate-50 text-left text-xs font-semibold text-slate-500">
                        <th class="py-2.5 pl-6 pr-2">{{ __('Product') }}</th>
                        <th class="px-2 py-2.5">{{ __('Style') }}</th>
                        <th class="px-2 py-2.5">{{ __('Color') }}</th>
                        <th class="px-2 py-2.5 text-right">{{ __('Size / Length (m)') }}</th>
                        <th class="px-2 py-2.5 text-right">{{ __('Ordered') }}</th>
                        <th class="px-2 py-2.5 text-right">{{ __('Available') }}</th>
                        <th class="w-28 px-2 py-2.5 text-right">{{ __('Still to deliver') }}</th>
                        <th class="w-56 px-2 py-2.5">{{ __('Fulfilled by') }}</th>
                        <th class="w-56 py-2.5 pl-2 pr-6">{{ __('Next step') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($order->items as $item)
                    @php
                        $plan = $item->plannedFulfillment();
                        $unitName = $item->product->stockUnit->short_name ?? '';
                        $available = $onHand[$item->product_id.':'.($item->product_variant_id ?? '')] ?? 0.0;
                    @endphp
                    <tr class="hover:bg-slate-50/70">
                        <td class="py-2.5 pl-6 pr-2">
                            <span class="block font-semibold text-slate-800">{{ $item->label() }}</span>
                            <span class="block text-xs text-slate-400">{{ $item->productVariant->sku ?? $item->product->sku }}{{ $item->remarks ? ' · '.$item->remarks : '' }}</span>
                        </td>
                        <td class="px-2 py-2.5 text-slate-700">{{ $item->style ?: '—' }}</td>
                        <td class="px-2 py-2.5 text-slate-700">{{ $item->color ?: '—' }}</td>
                        <td class="px-2 py-2.5 text-right tabular-nums text-slate-700">{{ $item->length_per_unit !== null ? rtrim(rtrim(number_format($item->length_per_unit, 2), '0'), '.') : '—' }}</td>
                        <td class="whitespace-nowrap px-2 py-2.5 text-right tabular-nums text-slate-700">{{ $qty($item->quantity) }} {{ $unitName }}</td>
                        <td class="whitespace-nowrap px-2 py-2.5 text-right tabular-nums font-semibold {{ $available <= 0 ? 'text-slate-400' : ($available + 0.00001 >= $item->remaining() ? 'text-emerald-600' : 'text-amber-600') }}"
                            title="{{ __('Finished stock on hand across all sites') }}">{{ $qty(max($available, 0)) }}</td>
                        <td class="px-2 py-2.5 text-right tabular-nums text-slate-700">{{ $qty($item->remaining()) }}</td>
                        <td class="px-2 py-2">
                            @if ($user->can('sales.edit'))
                            <select name="fulfillment[{{ $item->id }}]"
                                    class="block w-full rounded-md border-slate-300 py-1.5 text-sm focus:border-accent-500 focus:ring-accent-500">
                                @foreach (\App\Models\ProformaInvoiceItem::FULFILLMENTS as $key => $label)
                                    <option value="{{ $key }}" @selected($plan === $key) @disabled($key === 'production' && ! $item->product->isManufactured())>{{ __($label) }}</option>
                                @endforeach
                            </select>
                            <x-input-error class="mt-1" :messages="$errors->get('fulfillment.'.$item->id)" />
                            @else
                            <span class="text-slate-700">{{ __($item->fulfillment_label) }}</span>
                            @endif
                        </td>
                        <td class="py-2.5 pl-2 pr-6 text-xs">
                            @if ($item->remaining() <= 0)
                                <span class="text-teal-600">{{ __('Delivered') }}</span>
                            @elseif ($plan === 'production')
                                @php $toProduce = $item->quantityToProduce(); $liveProduction = $item->liveProductionOrder(); @endphp
                                @if ($liveProduction)
                                    <a href="{{ route('production-orders.show', $liveProduction) }}" class="font-semibold text-emerald-600 hover:underline">{{ __('In production — :no', ['no' => $liveProduction->production_no]) }}</a>
                                @elseif ($toProduce > 0 && $user->can('production.create'))
                                    <a href="{{ route('production-orders.create', ['sales_order_item_id' => $item->id]) }}"
                                       class="inline-flex items-center rounded-md bg-brand-800 px-2.5 py-1 font-semibold text-white hover:bg-brand-700">{{ __('Produce :qty', ['qty' => $qty($toProduce)]) }}</a>
                                @elseif ($toProduce > 0)
                                    <span class="text-amber-600">{{ __(':qty to produce', ['qty' => $qty($toProduce)]) }}</span>
                                @else
                                    <span class="text-emerald-600">{{ __('In production') }}</span>
                                @endif
                            @elseif ($plan === 'purchase')
                                @php $need = $purchaseNeeds->get($item->product_id.':'.($item->product_variant_id ?? '')); @endphp
                                @if ($need && $need->to_buy > 0)
                                    <span class="text-amber-600">{{ __('To buy — use "Create Purchase Order"') }}</span>
                                @else
                                    <span class="text-emerald-600">{{ __('On a Purchase Order') }}</span>
                                @endif
                            @else
                                <span class="text-slate-500">{{ __('Deliver from stock') }}</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            @if ($user->can('sales.edit'))
            <div class="flex justify-end px-6 py-4">
                <button type="submit" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Save Plan') }}</button>
            </div>
            @endif
        </form>
        @endif

        @if ($order->productionOrders->isNotEmpty() || $order->purchases->isNotEmpty())
        <div class="grid grid-cols-1 gap-4 px-6 pb-6 {{ $canPlan ? '' : 'pt-1' }} lg:grid-cols-2">
            <div>
                <p class="mb-2 text-xs font-semibold text-slate-500">{{ __('Production Orders') }}</p>
                <ul class="divide-y divide-slate-100 rounded-xl ring-1 ring-slate-200 text-sm">
                    @forelse ($order->productionOrders as $production)
                    <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                        <span>
                            <a href="{{ route('production-orders.show', $production) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ $production->production_no }}</a>
                            <span class="block text-xs text-slate-400">{{ $production->label() }} · {{ $qty($production->totalQuantity()) }} · {{ $production->site->name }}</span>
                        </span>
                        <x-status-badge :label="$production->status_label" :color="\App\Models\ProductionOrder::STATUS_COLORS[$production->status] ?? 'slate'" />
                    </li>
                    @empty
                    <li class="px-4 py-2.5 text-xs text-slate-400">{{ __('None yet.') }}</li>
                    @endforelse
                </ul>
            </div>
            <div>
                <p class="mb-2 text-xs font-semibold text-slate-500">{{ __('Purchase Orders') }}</p>
                <ul class="divide-y divide-slate-100 rounded-xl ring-1 ring-slate-200 text-sm">
                    @forelse ($order->purchases as $purchase)
                    <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                        <span>
                            <a href="{{ route('purchases.show', $purchase) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ $purchase->purchase_no }}</a>
                            <span class="block text-xs text-slate-400">{{ $purchase->vendorName() }} · {{ number_format($purchase->total_amount, 2) }}</span>
                        </span>
                        <x-status-badge :label="$purchase->status_label" :color="\App\Models\Purchase::STATUS_COLORS[$purchase->status] ?? 'slate'" />
                    </li>
                    @empty
                    <li class="px-4 py-2.5 text-xs text-slate-400">{{ __('None yet.') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
        @endif
    </div>
    @endif

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
