<x-app-layout>
    <x-slot name="title">{{ __('Import Sales Order') }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('Import Sales Order from Excel') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ __('Upload the order sheet as the customer sent it — you will check the customer, products and lines before anything is saved.') }}</p>
        </div>
    </x-slot>

    <div class="max-w-2xl rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <form method="POST" action="{{ route('sales-orders.import.upload') }}" enctype="multipart/form-data">
            @csrf

            <x-input-label for="file" :value="__('Order sheet (.xlsx or .xls, up to 5 MB)')" />
            <input id="file" name="file" type="file" accept=".xlsx,.xls" required
                   class="mt-1 block w-full text-sm text-slate-700 file:mr-4 file:rounded-lg file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-brand-800 hover:file:bg-brand-100">
            <x-input-error class="mt-2" :messages="$errors->get('file')" />

            <ul class="mt-4 list-disc space-y-1 pl-5 text-xs text-slate-500">
                <li>{{ __('One order per file; only the first worksheet is read.') }}</li>
                <li>{{ __('The header needs "Customer Name"; the line table starts at the "Sl. No" heading row.') }}</li>
                <li>{{ __('Prices are read at full precision (USD per cone), even when the sheet shows them rounded.') }}</li>
            </ul>

            <div class="mt-6 flex items-center gap-3">
                <button type="submit"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-accent-500 focus:ring-offset-2">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
                    {{ __('Upload & Preview') }}
                </button>
                <a href="{{ route('sales-orders.index') }}" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
</x-app-layout>
