<x-app-layout>
    <x-slot name="title">{{ __('New Sales Order') }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ __('New Sales Order') }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">{{ __('Enter the order as received from the customer — it stays editable until it is confirmed.') }}</p>
            </div>
            <a href="{{ route('sales-orders.import') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-brand-200 bg-white px-4 py-2 text-sm font-semibold text-brand-800 hover:bg-brand-50">
                {{ __('Import from Excel instead') }}
            </a>
        </div>
    </x-slot>

    @include('admin.sales-orders._form')
</x-app-layout>
