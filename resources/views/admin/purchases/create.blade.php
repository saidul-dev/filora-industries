<x-app-layout>
    <x-slot name="title">{{ __('New Purchase Order') }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('New Purchase Order') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ $salesOrder ? __('Buying finished goods straight for a Sales Order — no requisition needed.') : __('Purchases are made against an approved Inventory Requisition, or straight from a confirmed Sales Order ("Create Purchase Order" on the order).') }}</p>
        </div>
    </x-slot>

    @if ($salesOrder)
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-6 text-sm">
        <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Sales Order') }}</span>
        <a href="{{ route('sales-orders.show', $salesOrder) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ $salesOrder->order_no }}</a>
        <span class="text-slate-500">— {{ $salesOrder->party->name }}</span>
        <p class="mt-1 text-xs text-slate-400">{{ __('Pre-filled with what its "Direct Purchase" lines still need, after earlier Purchase Orders for this order.') }}</p>
    </div>

    @if ($items->isEmpty())
        <div class="rounded-2xl bg-white p-10 text-center text-slate-400 shadow-sm ring-1 ring-slate-200">
            {{ __('Nothing on :no is left to buy — every "Direct Purchase" line is already covered by a Purchase Order.', ['no' => $salesOrder->order_no]) }}
        </div>
    @else
        @include('admin.purchases._form')
    @endif
    @else
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-6">
        <form method="GET" action="{{ route('purchases.create') }}" class="flex flex-wrap items-end gap-4">
            <div class="w-96 max-w-full">
                <x-input-label for="from_requisition" :value="__('Approved Requisition')" />
                <div class="mt-1">
                    <x-searchable-select name="from_requisition" :options="$approvedRequisitions" :selected="$requisition?->id"
                                          placeholder="{{ __('Select an approved requisition…') }}" :auto-submit="true" />
                </div>
            </div>
        </form>
        @if ($requisition)
        <p class="mt-3 text-xs text-slate-500">
            {{ __('Ordering for :pi (:buyer) — needed at :site.', ['pi' => $requisition->proformaInvoice->pi_no, 'buyer' => $requisition->proformaInvoice->party->name, 'site' => $requisition->site->name]) }}
        </p>
        @endif
    </div>

    @if (! $requisition)
        <div class="rounded-2xl bg-white p-10 text-center text-slate-400 shadow-sm ring-1 ring-slate-200">
            @if ($approvedRequisitions->isEmpty())
                {{ __('No approved requisitions are waiting to be ordered.') }}
            @else
                {{ __('Pick an approved requisition above to order its items.') }}
            @endif
        </div>
    @else
        @include('admin.purchases._form')
    @endif
    @endif
</x-app-layout>
