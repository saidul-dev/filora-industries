<x-app-layout>
    <x-slot name="title">{{ __('Edit') }} — {{ $purchase->purchase_no }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('Edit Purchase Order') }} {{ $purchase->purchase_no }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ __('From :req under :pi', ['req' => $requisition->requisition_no, 'pi' => $requisition->proformaInvoice->pi_no]) }}</p>
        </div>
    </x-slot>

    @include('admin.purchases._form')
</x-app-layout>
