<x-app-layout>
    <x-slot name="title">{{ __('Edit') }} — {{ $order->order_no }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('Edit Sales Order') }} {{ $order->order_no }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ __('Change quantities, add or remove products — allowed while the order is pending.') }}</p>
        </div>
    </x-slot>

    @include('admin.sales-orders._form')
</x-app-layout>
