<x-app-layout>
    <x-slot name="title">{{ __('New Purchase Order') }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('New Purchase Order') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ __('Purchases are made against an approved Inventory Requisition.') }}</p>
        </div>
    </x-slot>

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
</x-app-layout>
