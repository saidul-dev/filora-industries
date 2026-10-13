<x-app-layout>
    <x-slot name="title">{{ $invoice->pi_no }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ $invoice->pi_no }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">{{ __('Proforma Invoice for :buyer', ['buyer' => $invoice->party->name]) }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('proforma-invoices.print', $invoice) }}" target="_blank"
                   class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z"/></svg>
                    {{ __('Print') }}
                </a>
                <a href="{{ route('proforma-invoices.index') }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                    {{ __('Back') }}
                </a>
            </div>
        </div>
    </x-slot>

    @php
        $user = auth()->user();
        $canApprove = $user->can('sales.approve');
        $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 4), '0'), '.') ?: '0';
    @endphp

    <!-- Summary + lifecycle actions -->
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6 mb-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="text-sm">
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Buyer') }}</span>
                <span class="font-semibold text-slate-800">{{ $invoice->party->name }}</span>
            </div>
            <div class="flex items-center gap-2">
                @if ($invoice->hasLc())
                    <x-status-badge size="lg" label="LC Attached" color="violet" />
                @endif
                <x-status-badge size="lg" :label="$invoice->status_label" :color="\App\Models\ProformaInvoice::STATUS_COLORS[$invoice->status] ?? 'slate'" />
            </div>
        </div>

        <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('PI Date') }}</span>
                <span class="block text-slate-700">{{ $invoice->pi_date->format('d M, Y') }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Valid Until') }}</span>
                <span class="block text-slate-700">{{ $invoice->valid_until?->format('d M, Y') ?? '—' }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Delivery Date') }}</span>
                <span class="block text-slate-700">{{ $invoice->delivery_date?->format('d M, Y') ?? '—' }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Sales Order') }}</span>
                @if ($invoice->salesOrder)
                    <a href="{{ route('sales-orders.show', $invoice->salesOrder) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ $invoice->salesOrder->order_no }}</a>
                @else
                    <span class="block text-slate-700">—</span>
                @endif
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Sales Person') }}</span>
                <span class="block text-slate-700">{{ $invoice->salesPerson->name ?? '—' }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Issued') }}</span>
                <span class="block text-slate-700">{{ $invoice->issued_at?->format('d M, Y') ?? '—' }}</span>
            </div>
            @if ($invoice->terms)
            <div class="sm:col-span-2">
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Terms') }}</span>
                <span class="block whitespace-pre-line text-slate-700">{{ $invoice->terms }}</span>
            </div>
            @endif
            @if ($invoice->note)
            <div class="sm:col-span-2">
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Internal Note') }}</span>
                <span class="block whitespace-pre-line text-slate-700">{{ $invoice->note }}</span>
            </div>
            @endif
        </div>

        <div class="mt-5 flex flex-wrap gap-3 border-t border-slate-100 pt-5">
            @if ($invoice->isEditable() && $user->can('sales.edit'))
            <a href="{{ route('proforma-invoices.edit', $invoice) }}"
               class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                {{ __('Edit Items') }}
            </a>
            @endif

            @if ($canApprove && $invoice->isEditable())
            <form method="POST" action="{{ route('proforma-invoices.issue', $invoice) }}"
                  onsubmit="return confirm('{{ __('Issue :no? Its items will be locked.', ['no' => $invoice->pi_no]) }}');">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    {{ __('Issue PI') }}
                </button>
            </form>
            @endif

            @if ($canApprove && in_array($invoice->status, ['issued', 'lc_issued', 'in_progress'], true))
            <form method="POST" action="{{ route('proforma-invoices.ready', $invoice) }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-teal-200 bg-teal-50 px-4 py-2 text-sm font-semibold text-teal-700 hover:bg-teal-100">
                    {{ __('Mark Ready for Delivery') }}
                </button>
            </form>
            @endif

            @if ($canApprove && $invoice->status === 'delivered')
            <form method="POST" action="{{ route('proforma-invoices.close', $invoice) }}"
                  onsubmit="return confirm('{{ __('Close :no?', ['no' => $invoice->pi_no]) }}');">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    {{ __('Close PI') }}
                </button>
            </form>
            @endif

            @if ($canApprove && in_array($invoice->status, ['draft', 'issued', 'lc_issued'], true))
            <form method="POST" action="{{ route('proforma-invoices.cancel', $invoice) }}" class="ml-auto"
                  onsubmit="return confirm('{{ __('Cancel :no? The Sales Order goes back to Confirmed.', ['no' => $invoice->pi_no]) }}');">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-100">
                    {{ __('Cancel PI') }}
                </button>
            </form>
            @endif
        </div>
    </div>

    <!-- Letter of Credit -->
    @php $lcEditable = $canApprove && ! in_array($invoice->status, ['draft', 'closed', 'cancelled'], true); @endphp
    @if ($invoice->hasLc() || $lcEditable)
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6 mb-4" x-data="{ editing: {{ $errors->hasAny(['lc_no', 'lc_bank', 'lc_date', 'lc_expiry_date', 'lc_amount', 'lc_file']) ? 'true' : 'false' }} }">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="font-bold text-brand-900">{{ __('Letter of Credit') }}</h3>
                <p class="text-xs text-slate-400">{{ __('Attaching an LC to an issued PI moves it to "LC Issued".') }}</p>
            </div>
            @if ($lcEditable)
            <button type="button" @click="editing = ! editing"
                    class="rounded-lg border border-violet-200 bg-violet-50 px-4 py-2 text-sm font-semibold text-violet-700 hover:bg-violet-100">
                {{ $invoice->hasLc() ? __('Update LC') : __('Attach LC') }}
            </button>
            @endif
        </div>

        @if ($invoice->hasLc())
        <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 text-sm">
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('LC No.') }}</span>
                <span class="block font-semibold text-slate-800">{{ $invoice->lc_no }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Issuing Bank') }}</span>
                <span class="block text-slate-700">{{ $invoice->lc_bank ?: '—' }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('LC Date') }}</span>
                <span class="block text-slate-700">{{ $invoice->lc_date?->format('d M, Y') ?? '—' }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Expiry Date') }}</span>
                <span class="block font-semibold {{ $invoice->lc_expiry_date?->isPast() ? 'text-rose-600' : 'text-slate-700' }}">
                    {{ $invoice->lc_expiry_date?->format('d M, Y') ?? '—' }}
                    @if ($invoice->lc_expiry_date?->isPast()) · {{ __('Expired') }} @endif
                </span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('LC Amount') }}</span>
                <span class="block text-slate-700">{{ $invoice->lc_amount !== null ? number_format($invoice->lc_amount, 2) : '—' }}</span>
            </div>
            @if ($invoice->lc_file_path)
            <div class="sm:col-span-2">
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('LC Document') }}</span>
                <a href="{{ route('proforma-invoices.lc-file', $invoice) }}" target="_blank" class="font-semibold text-accent-600 hover:text-accent-800">{{ $invoice->lc_file_name ?? __('View file') }}</a>
            </div>
            @endif
        </div>
        @endif

        @if ($lcEditable)
        <form method="POST" action="{{ route('proforma-invoices.lc', $invoice) }}" enctype="multipart/form-data"
              x-show="editing" x-cloak class="mt-5 border-t border-slate-100 pt-5">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <x-input-label for="lc_no" :value="__('LC No.')" />
                    <x-text-input id="lc_no" name="lc_no" type="text" class="mt-1 block w-full" :value="old('lc_no', $invoice->lc_no)" required />
                    <x-input-error class="mt-2" :messages="$errors->get('lc_no')" />
                </div>
                <div>
                    <x-input-label for="lc_bank" :value="__('Issuing Bank (optional)')" />
                    <x-text-input id="lc_bank" name="lc_bank" type="text" class="mt-1 block w-full" :value="old('lc_bank', $invoice->lc_bank)" />
                    <x-input-error class="mt-2" :messages="$errors->get('lc_bank')" />
                </div>
                <div>
                    <x-input-label for="lc_amount" :value="__('LC Amount (optional)')" />
                    <x-text-input id="lc_amount" name="lc_amount" type="number" step="0.01" min="0" class="mt-1 block w-full" :value="old('lc_amount', $invoice->lc_amount ?? $invoice->total_amount)" />
                    <x-input-error class="mt-2" :messages="$errors->get('lc_amount')" />
                </div>
                <div>
                    <x-input-label for="lc_date" :value="__('LC Date (optional)')" />
                    <x-text-input id="lc_date" name="lc_date" type="date" class="mt-1 block w-full" :value="old('lc_date', $invoice->lc_date?->toDateString())" />
                    <x-input-error class="mt-2" :messages="$errors->get('lc_date')" />
                </div>
                <div>
                    <x-input-label for="lc_expiry_date" :value="__('LC Expiry Date')" />
                    <x-text-input id="lc_expiry_date" name="lc_expiry_date" type="date" class="mt-1 block w-full" :value="old('lc_expiry_date', $invoice->lc_expiry_date?->toDateString())" required />
                    <x-input-error class="mt-2" :messages="$errors->get('lc_expiry_date')" />
                </div>
                <div>
                    <x-input-label for="lc_file" :value="$invoice->lc_file_path ? __('Replace LC File (optional)') : __('LC File / Image')" />
                    <input id="lc_file" name="lc_file" type="file" accept=".pdf,image/*" @if (! $invoice->lc_file_path) required @endif
                           class="mt-1 block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-800/5 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-brand-800">
                    <p class="mt-1 text-[11px] text-slate-400">{{ __('PDF or image, up to 5MB.') }}</p>
                    <x-input-error class="mt-2" :messages="$errors->get('lc_file')" />
                </div>
            </div>
            <button type="submit" class="mt-4 inline-flex items-center gap-2 rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700">
                {{ __('Save LC') }}
            </button>
        </form>
        @endif
    </div>
    @endif

    <!-- Final deliverable items -->
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
        <div class="px-6 pt-5 pb-3">
            <h3 class="font-bold text-brand-900">{{ __('Deliverable Items & Fulfillment Plan') }}</h3>
            <p class="text-xs text-slate-400">{{ __('On hand is the current stock across all sites.') }}</p>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full min-w-[1080px] text-sm">
            <thead>
                <tr class="border-y border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('Product') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Fulfillment') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Quantity') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Delivered') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Remaining') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('On Hand') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Unit Price') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Subtotal') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($invoice->items as $item)
                @php $available = $onHand[$item->id] ?? 0; @endphp
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $item->label() }}</span>
                        <span class="block text-xs text-slate-400">{{ $item->productVariant->sku ?? $item->product->sku }} · {{ __(\App\Models\Product::TYPES[$item->product->product_type] ?? '') }}</span>
                    </td>
                    <td class="px-5 py-3">
                        <x-status-badge :label="$item->fulfillment_label" :color="['stock' => 'emerald', 'purchase' => 'sky', 'production' => 'indigo'][$item->fulfillment] ?? 'slate'" />
                        @if ($item->fulfillment === 'production' && $item->product->bomItems->isEmpty())
                            <span class="mt-1 block text-[11px] font-semibold text-rose-500">{{ __('No BOM defined') }}</span>
                        @endif
                        @if ($item->product->isManufactured() && $invoice->isActive() && $item->quantityToProduce() > 0 && $user->can('production.create'))
                            <a href="{{ route('production-orders.create', ['proforma_invoice_item_id' => $item->id]) }}"
                               class="mt-1 block text-[11px] font-semibold text-indigo-600 hover:text-indigo-800">
                                {{ __('Produce :qty →', ['qty' => $qty($item->quantityToProduce())]) }}
                            </a>
                        @endif
                    </td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ $qty($item->quantity) }} {{ $item->product->stockUnit->short_name ?? '' }}</td>
                    <td class="px-5 py-3 text-right text-slate-600">{{ $qty($item->delivered_quantity) }}</td>
                    <td class="px-5 py-3 text-right font-semibold {{ $item->remaining() > 0 ? 'text-amber-600' : 'text-emerald-600' }}">{{ $qty($item->remaining()) }}</td>
                    <td class="px-5 py-3 text-right {{ $available >= $item->remaining() ? 'text-emerald-600' : 'text-rose-600' }}">{{ $qty($available) }}</td>
                    <td class="px-5 py-3 text-right text-slate-600">{{ number_format($item->unit_price, 2) }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($item->subtotal, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t border-slate-200 bg-slate-50">
                <tr>
                    <td colspan="7" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Subtotal') }}</td>
                    <td class="px-5 py-2 text-right font-semibold text-slate-800">{{ number_format($invoice->subtotal_amount, 2) }}</td>
                </tr>
                @if ((float) $invoice->discount_amount > 0)
                <tr>
                    <td colspan="7" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Discount') }}</td>
                    <td class="px-5 py-2 text-right text-slate-600">− {{ number_format($invoice->discount_amount, 2) }}</td>
                </tr>
                @endif
                <tr>
                    <td colspan="7" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total') }}</td>
                    <td class="px-5 py-2 text-right text-base font-bold text-brand-900">{{ number_format($invoice->total_amount, 2) }}</td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>

    @if ($materials->isNotEmpty())
    <!-- Raw material requirement for production lines -->
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
        <div class="px-6 pt-5 pb-3">
            <h3 class="font-bold text-brand-900">{{ __('Raw Material Requirement') }}</h3>
            <p class="text-xs text-slate-400">{{ __('For the lines planned for factory production, per their BOMs.') }}</p>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-sm">
            <thead>
                <tr class="border-y border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('Material') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Required') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('On Hand') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Shortage') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($materials as $row)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $row->material->name }}</span>
                        <span class="block text-xs text-slate-400">{{ $row->material->sku }}</span>
                    </td>
                    <td class="px-5 py-3 text-right text-slate-700">{{ $qty($row->required) }} {{ $row->material->stockUnit->short_name ?? '' }}</td>
                    <td class="px-5 py-3 text-right text-slate-600">{{ $qty($row->on_hand) }}</td>
                    <td class="px-5 py-3 text-right font-semibold {{ $row->shortage > 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $row->shortage > 0 ? $qty($row->shortage) : __('None') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
    @endif

    <!-- Procurement: requisitions raised under this PI -->
    @can('requisitions.view')
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
        <div class="flex flex-wrap items-center justify-between gap-3 px-6 pt-5 pb-3">
            <div>
                <h3 class="font-bold text-brand-900">{{ __('Inventory Requisitions') }}</h3>
                <p class="text-xs text-slate-400">{{ __('Finished goods and raw materials requested for this PI.') }}</p>
            </div>
            @if ($invoice->isActive() && $user->can('requisitions.create'))
            <a href="{{ route('purchase-requisitions.create', ['proforma_invoice_id' => $invoice->id]) }}"
               class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ __('Raise Requisition') }}
            </a>
            @endif
        </div>
        <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-sm">
            <tbody class="divide-y divide-slate-100 border-t border-slate-100">
                @forelse ($invoice->requisitions as $requisition)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <a href="{{ route('purchase-requisitions.show', $requisition) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ $requisition->requisition_no }}</a>
                        <span class="block text-xs text-slate-400">{{ $requisition->request_date->format('d M, Y') }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ $requisition->site->name }}</td>
                    <td class="px-5 py-3 text-slate-600">{{ trans_choice(':count item|:count items', $requisition->items_count, ['count' => $requisition->items_count]) }}</td>
                    <td class="px-5 py-3 text-right">
                        <x-status-badge :label="$requisition->status_label" :color="\App\Models\PurchaseRequisition::STATUS_COLORS[$requisition->status] ?? 'slate'" />
                    </td>
                </tr>
                @empty
                <tr>
                    <td class="px-5 py-6 text-center text-slate-400">{{ __('No requisitions raised under this PI yet.') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
    @endcan

    <!-- Procurement: purchase orders placed under this PI -->
    @can('purchases.view')
    @if ($invoice->purchases->isNotEmpty())
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
        <div class="px-6 pt-5 pb-3">
            <h3 class="font-bold text-brand-900">{{ __('Purchase Orders') }}</h3>
            <p class="text-xs text-slate-400">{{ __('Goods received against these are already in stock at their receiving location.') }}</p>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-sm">
            <tbody class="divide-y divide-slate-100 border-t border-slate-100">
                @foreach ($invoice->purchases as $purchase)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <a href="{{ route('purchases.show', $purchase) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ $purchase->purchase_no }}</a>
                        <span class="block text-xs text-slate-400">{{ $purchase->order_date->format('d M, Y') }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ $purchase->vendorName() }}</td>
                    <td class="px-5 py-3 text-slate-600">{{ $purchase->site->name }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($purchase->total_amount, 2) }}</td>
                    <td class="px-5 py-3 text-right">
                        <x-status-badge :label="$purchase->status_label" :color="\App\Models\Purchase::STATUS_COLORS[$purchase->status] ?? 'slate'" />
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
    @endif
    @endcan

    <!-- Production orders issued under this PI -->
    @can('production.view')
    @if ($invoice->productionOrders->isNotEmpty())
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
        <div class="px-6 pt-5 pb-3">
            <h3 class="font-bold text-brand-900">{{ __('Production Orders') }}</h3>
            <p class="text-xs text-slate-400">{{ __('Completed output is in finished goods stock at the production site.') }}</p>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-sm">
            <tbody class="divide-y divide-slate-100 border-t border-slate-100">
                @foreach ($invoice->productionOrders as $production)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <a href="{{ route('production-orders.show', $production) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ $production->production_no }}</a>
                        <span class="block text-xs text-slate-400">{{ $production->created_at->format('d M, Y') }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-700">{{ $production->label() }}</td>
                    <td class="px-5 py-3 text-slate-600">{{ $production->site->name }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ $qty($production->status === 'completed' ? $production->produced_quantity : $production->quantity) }} {{ $production->product->stockUnit->short_name ?? '' }}</td>
                    <td class="px-5 py-3 text-right">
                        <x-status-badge :label="$production->status_label" :color="\App\Models\ProductionOrder::STATUS_COLORS[$production->status] ?? 'slate'" />
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
    @endif
    @endcan
    <!-- Deliveries against this PI -->
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
        <div class="flex flex-wrap items-center justify-between gap-3 px-6 pt-5 pb-3">
            <div>
                <h3 class="font-bold text-brand-900">{{ __('Deliveries') }}</h3>
                <p class="text-xs text-slate-400">{{ __('Fulfilled from existing stock, purchased finished goods and factory output — from any site.') }}</p>
            </div>
            @if ($invoice->isActive() && $user->can('sales.deliver') && $invoice->items->contains(fn ($item) => $item->remaining() > 0))
            <a href="{{ route('deliveries.create', ['proforma_invoice_id' => $invoice->id]) }}"
               class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ __('New Delivery') }}
            </a>
            @endif
        </div>
        <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-sm">
            <tbody class="divide-y divide-slate-100 border-t border-slate-100">
                @forelse ($invoice->deliveries as $delivery)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <a href="{{ route('deliveries.show', $delivery) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ $delivery->delivery_no }}</a>
                        <span class="block text-xs text-slate-400">{{ $delivery->delivery_date->format('d M, Y') }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ __('From :site', ['site' => $delivery->site->name]) }}</td>
                    <td class="px-5 py-3 text-slate-600">{{ trans_choice(':count item|:count items', $delivery->items_count, ['count' => $delivery->items_count]) }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($delivery->sale_amount, 2) }}</td>
                </tr>
                @empty
                <tr>
                    <td class="px-5 py-6 text-center text-slate-400">{{ __('Nothing delivered yet.') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</x-app-layout>
