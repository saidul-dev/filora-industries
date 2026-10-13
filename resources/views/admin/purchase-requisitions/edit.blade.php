<x-app-layout>
    <x-slot name="title">{{ __('Edit') }} — {{ $requisition->requisition_no }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('Edit Requisition') }} {{ $requisition->requisition_no }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ __('Under :pi — :buyer', ['pi' => $invoice->pi_no, 'buyer' => $invoice->party->name]) }}</p>
        </div>
    </x-slot>

    @include('admin.purchase-requisitions._form')
</x-app-layout>
