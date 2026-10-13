<x-app-layout>
    <x-slot name="title">{{ __('New Sales Order') }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('New Sales Order') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ __('Enter the order as received from the buyer — it stays editable until it is confirmed.') }}</p>
        </div>
    </x-slot>

    @include('admin.sales-orders._form')
</x-app-layout>
