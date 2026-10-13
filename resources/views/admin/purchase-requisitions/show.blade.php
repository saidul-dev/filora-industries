<x-app-layout>
    <x-slot name="title">{{ $requisition->requisition_no }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ $requisition->requisition_no }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">{{ __('Inventory Requisition under :pi', ['pi' => $requisition->proformaInvoice->pi_no]) }}</p>
            </div>
            <a href="{{ route('purchase-requisitions.index') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                {{ __('Back') }}
            </a>
        </div>
    </x-slot>

    @php $user = auth()->user(); @endphp

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6 mb-4" x-data="{ rejecting: false }">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="text-sm">
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Proforma Invoice') }}</span>
                @can('sales.view')
                <a href="{{ route('proforma-invoices.show', $requisition->proformaInvoice) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ $requisition->proformaInvoice->pi_no }}</a>
                @else
                <span class="font-semibold text-slate-800">{{ $requisition->proformaInvoice->pi_no }}</span>
                @endcan
                <span class="text-slate-500">— {{ $requisition->proformaInvoice->party->name }}</span>
            </div>
            <x-status-badge size="lg" :label="$requisition->status_label" :color="\App\Models\PurchaseRequisition::STATUS_COLORS[$requisition->status] ?? 'slate'" />
        </div>

        <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Needed At') }}</span>
                <span class="block text-slate-700">{{ $requisition->site->name }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Requested') }}</span>
                <span class="block text-slate-700">{{ $requisition->request_date->format('d M, Y') }} {{ __('by') }} {{ $requisition->requestedBy->name ?? '—' }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Required By') }}</span>
                <span class="block text-slate-700">{{ $requisition->required_by?->format('d M, Y') ?? '—' }}</span>
            </div>
            @if ($requisition->approved_at)
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ $requisition->status === 'rejected' ? __('Rejected') : __('Approved') }}</span>
                <span class="block text-slate-700">{{ $requisition->approved_at->format('d M, Y') }} {{ __('by') }} {{ $requisition->approvedBy->name ?? '—' }}</span>
            </div>
            @endif
            @if ($requisition->rejection_reason)
            <div class="sm:col-span-2">
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Rejection Reason') }}</span>
                <span class="block text-rose-600">{{ $requisition->rejection_reason }}</span>
            </div>
            @endif
            @if ($requisition->note)
            <div class="sm:col-span-2">
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Note') }}</span>
                <span class="block whitespace-pre-line text-slate-700">{{ $requisition->note }}</span>
            </div>
            @endif
        </div>

        <div class="mt-5 flex flex-wrap gap-3 border-t border-slate-100 pt-5">
            @if ($requisition->isEditable() && $user->can('requisitions.edit'))
            <a href="{{ route('purchase-requisitions.edit', $requisition) }}"
               class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                {{ __('Edit') }}
            </a>
            @endif

            @if ($requisition->status === 'pending' && $user->can('requisitions.approve'))
            <form method="POST" action="{{ route('purchase-requisitions.approve', $requisition) }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    {{ __('Approve') }}
                </button>
            </form>
            <button type="button" @click="rejecting = ! rejecting"
                    class="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-100">
                {{ __('Reject') }}
            </button>
            @endif

            @if ($requisition->status === 'approved' && $user->can('purchases.create'))
            <a href="{{ route('purchases.create', ['from_requisition' => $requisition->id]) }}"
               class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272"/></svg>
                {{ __('Create Purchase Order') }}
            </a>
            @endif

            @if ($requisition->purchase && $user->can('purchases.view'))
            <a href="{{ route('purchases.show', $requisition->purchase) }}"
               class="inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100">
                {{ __('View :no', ['no' => $requisition->purchase->purchase_no]) }}
            </a>
            @endif

            @if ($requisition->isEditable() && $user->can('requisitions.delete'))
            <form method="POST" action="{{ route('purchase-requisitions.destroy', $requisition) }}" class="ml-auto"
                  onsubmit="return confirm('{{ __('Delete :no?', ['no' => $requisition->requisition_no]) }}');">
                @csrf
                @method('DELETE')
                <button type="submit" class="rounded-lg px-4 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-50">{{ __('Delete') }}</button>
            </form>
            @endif
        </div>

        @if ($requisition->status === 'pending' && $user->can('requisitions.approve'))
        <form method="POST" action="{{ route('purchase-requisitions.reject', $requisition) }}" x-show="rejecting" x-cloak class="mt-4 flex max-w-xl items-end gap-3">
            @csrf
            <div class="flex-1">
                <x-input-label for="rejection_reason" :value="__('Reason for rejection')" />
                <x-text-input id="rejection_reason" name="rejection_reason" type="text" class="mt-1 block w-full" required />
                <x-input-error class="mt-2" :messages="$errors->get('rejection_reason')" />
            </div>
            <button type="submit" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">{{ __('Confirm Rejection') }}</button>
        </form>
        @endif
    </div>

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full min-w-[820px] text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('Item') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Type') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Quantity') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Est. Unit Cost') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Est. Total') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Purpose / Note') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($requisition->items as $item)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $item->product->name }}{{ $item->productVariant ? ' — '.$item->productVariant->label : '' }}</span>
                        <span class="block text-xs text-slate-400">{{ $item->productVariant->sku ?? $item->product->sku }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ __(\App\Models\Product::TYPES[$item->product->product_type] ?? '—') }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ rtrim(rtrim(number_format($item->quantity, 4), '0'), '.') }} {{ $item->product->stockUnit->short_name ?? '' }}</td>
                    <td class="px-5 py-3 text-right text-slate-600">{{ $item->estimated_unit_cost !== null ? number_format($item->estimated_unit_cost, 2) : '—' }}</td>
                    <td class="px-5 py-3 text-right text-slate-700">{{ number_format((float) $item->quantity * (float) $item->estimated_unit_cost, 2) }}</td>
                    <td class="px-5 py-3 text-slate-600">{{ $item->note ?: '—' }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t border-slate-200 bg-slate-50">
                <tr>
                    <td colspan="4" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Estimated Total') }}</td>
                    <td class="px-5 py-2 text-right font-semibold text-slate-800">{{ number_format($requisition->estimatedTotal(), 2) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</x-app-layout>
