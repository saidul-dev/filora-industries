<x-app-layout>
    <x-slot name="title">{{ __('New Requisition') }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('New Inventory Requisition') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ __('Request finished goods or raw materials to be bought for a Proforma Invoice.') }}</p>
        </div>
    </x-slot>

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-6">
        <form method="GET" action="{{ route('purchase-requisitions.create') }}" class="flex flex-wrap items-end gap-4">
            <div class="w-96 max-w-full">
                <x-input-label for="proforma_invoice_id" :value="__('Controlling Proforma Invoice')" />
                <div class="mt-1">
                    <x-searchable-select name="proforma_invoice_id" :options="$invoices" :selected="$invoice?->id"
                                          placeholder="{{ __('Select an open PI…') }}" :auto-submit="true" />
                </div>
            </div>
        </form>
        @if ($invoice)
        <p class="mt-3 text-xs text-slate-500">
            {{ __('Pre-filled with what :pi still needs to buy — undelivered quantity (or BOM materials for production lines), net of stock on hand and open requisitions. Adjust as needed.', ['pi' => $invoice->pi_no]) }}
        </p>
        @endif
    </div>

    @if (! $invoice)
        <div class="rounded-2xl bg-white p-10 text-center text-slate-400 shadow-sm ring-1 ring-slate-200">
            {{ __('Pick an issued Proforma Invoice above — every requisition is raised under one.') }}
        </div>
    @else
        @include('admin.purchase-requisitions._form')
    @endif
</x-app-layout>
