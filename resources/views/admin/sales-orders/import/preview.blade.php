{{--
    Import preview. The main form (#import-form) sits at the bottom; the
    customer and product selects above it join it through the form="" attribute,
    so the small "create" forms can live beside them without nesting forms.
--}}
@php
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 4), '0'), '.');
    $sheetTotal = collect($sheet['lines'])->sum(fn ($l) => round($l['quantity'] * $l['unit_price'], 2));
    $warnings = collect($sheet['lines'])->whereNotNull('warning');
@endphp
<x-app-layout>
    <x-slot name="title">{{ __('Import Preview') }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('Check the Imported Order') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ $sheet['file_name'] }} · {{ count($sheet['lines']) }} {{ __('line(s)') }} · USD {{ number_format($sheetTotal, 2) }}</p>
        </div>
    </x-slot>

    @if ($duplicate)
    <div class="mb-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-800 ring-1 ring-amber-200">
        {{ __('This customer already has an order with this order number') }}:
        <a href="{{ route('sales-orders.show', $duplicate) }}" class="font-semibold underline">{{ $duplicate->order_no }}</a>
        ({{ $duplicate->status_label }}). {{ __('Importing again creates a second order.') }}
    </div>
    @endif

    <!-- Customer -->
    <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 mb-4">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="font-bold text-brand-900">{{ __('Customer') }}</h3>
                <p class="text-xs text-slate-400">{{ __('On the sheet') }}: <span class="font-semibold text-slate-700">{{ $header['customer_name'] ?? '—' }}</span></p>
            </div>
            @if ($customer)
                <x-status-badge :label="__('Matched')" color="emerald" />
            @else
                <x-status-badge :label="__('Not matched')" color="amber" />
            @endif
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end">
            <div>
                <x-input-label for="party_id" :value="__('Customer for this order')" />
                <select id="party_id" name="party_id" form="import-form" required
                        class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                    <option value="">{{ __('— Pick a customer —') }}</option>
                    @foreach ($customers as $option)
                        <option value="{{ $option->id }}" @selected((int) old('party_id', $customer?->id) === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
                <x-input-error class="mt-2" :messages="$errors->get('party_id')" />
                @unless ($customer)
                    <p class="mt-1 text-xs text-slate-400">{{ __('Picking one here remembers this spelling for the next import.') }}</p>
                @endunless
            </div>
            @unless ($customer)
            <form method="POST" action="{{ route('sales-orders.import.customer', $token) }}">
                @csrf
                <button type="submit" class="rounded-lg border border-brand-200 bg-brand-50 px-4 py-2 text-sm font-semibold text-brand-800 hover:bg-brand-100">
                    {{ __('Create customer from this sheet') }}
                </button>
                <p class="mt-1 text-xs text-slate-400">
                    {{ collect([$header['customer_address'], $header['email'], $header['contact']])->filter()->implode(' · ') }}
                </p>
            </form>
            @endunless
        </div>
    </div>

    <!-- Products -->
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
        <div class="px-6 pt-5 pb-3">
            <h3 class="font-bold text-brand-900">{{ __('Products') }}</h3>
            <p class="text-xs text-slate-400">{{ __('Each item description on the sheet must point to a product.') }}</p>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-sm">
            <tbody class="divide-y divide-slate-100">
                @foreach ($products as $product)
                <tr>
                    <td class="px-6 py-3 align-top">
                        <span class="block font-semibold text-slate-800">{{ $product->description }}</span>
                        @if ($product->match)
                            <x-status-badge :label="__('Matched')" color="emerald" />
                        @else
                            <x-status-badge :label="__('Not matched')" color="amber" />
                        @endif
                    </td>
                    <td class="px-6 py-3 w-96 align-top">
                        <select name="products[{{ $product->key }}]" form="import-form" required
                                class="block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                            <option value="">{{ __('— Pick a product —') }}</option>
                            @foreach ($productOptions as $option)
                                <option value="{{ $option->id }}" @selected((int) old("products.{$product->key}", $product->match?->id) === $option->id)>{{ $option->name }} ({{ $option->sku }})</option>
                            @endforeach
                        </select>
                        <x-input-error class="mt-1" :messages="$errors->get('products.'.$product->key)" />
                    </td>
                    <td class="px-6 py-3 w-48 align-top text-right">
                        @unless ($product->match)
                        <form method="POST" action="{{ route('sales-orders.import.product', $token) }}">
                            @csrf
                            <input type="hidden" name="description" value="{{ $product->description }}">
                            <button type="submit" class="rounded-lg border border-brand-200 bg-brand-50 px-3 py-1.5 text-xs font-semibold text-brand-800 hover:bg-brand-100">
                                {{ __('Create product') }}
                            </button>
                        </form>
                        @endunless
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        <x-input-error class="px-6 pb-3" :messages="$errors->get('description')" />
    </div>

    <!-- Lines -->
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
        <div class="px-6 pt-5 pb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-bold text-brand-900">{{ __('Lines') }}</h3>
            @if ($warnings->isNotEmpty())
                <span class="text-xs font-semibold text-amber-700">{{ $warnings->count() }} {{ __('line(s) where the sheet total differs from quantity × price') }}</span>
            @endif
        </div>
        <div class="overflow-x-auto">
        <table class="w-full min-w-[1000px] text-sm">
            <thead>
                <tr class="border-y border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-4 py-3 font-semibold">#</th>
                    <th class="px-4 py-3 font-semibold">{{ __('Style') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('Item Description') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('Color') }}</th>
                    <th class="px-4 py-3 font-semibold text-right">{{ __('Qty (Cone)') }}</th>
                    <th class="px-4 py-3 font-semibold text-right">{{ __('Length (m)') }}</th>
                    <th class="px-4 py-3 font-semibold text-right">{{ __('Price (USD)') }}</th>
                    <th class="px-4 py-3 font-semibold text-right">{{ __('Total (USD)') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('Remarks') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($sheet['lines'] as $i => $line)
                <tr class="{{ $line['warning'] ? 'bg-amber-50' : 'hover:bg-slate-50' }}">
                    <td class="px-4 py-2 text-slate-400">{{ $i + 1 }}</td>
                    <td class="px-4 py-2 font-semibold text-slate-800">{{ $line['style'] }}</td>
                    <td class="px-4 py-2 text-slate-700">{{ $line['description'] }}</td>
                    <td class="px-4 py-2 text-slate-700">{{ $line['color'] }}</td>
                    <td class="px-4 py-2 text-right text-slate-800">{{ $qty($line['quantity']) }}</td>
                    <td class="px-4 py-2 text-right text-slate-600">{{ $line['length_per_unit'] !== null ? $qty($line['length_per_unit']) : '' }}</td>
                    <td class="px-4 py-2 text-right text-slate-600">{{ number_format($line['unit_price'], 4) }}</td>
                    <td class="px-4 py-2 text-right font-semibold text-slate-800">
                        {{ number_format(round($line['quantity'] * $line['unit_price'], 2), 2) }}
                        @if ($line['warning'])
                            <span class="block text-[11px] font-normal text-amber-700">{{ $line['warning'] }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-slate-600">{{ $line['remarks'] }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t border-slate-200 bg-slate-50">
                <tr>
                    <td colspan="7" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total') }}</td>
                    <td class="px-4 py-2 text-right text-base font-bold text-brand-900">USD {{ number_format($sheetTotal, 2) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>

    <!-- Order details + submit -->
    <form id="import-form" method="POST" action="{{ route('sales-orders.import.store', $token) }}"
          class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
        @csrf
        <h3 class="mb-4 font-bold text-brand-900">{{ __('Order Details') }}</h3>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <x-input-label for="buyer_name" :value="__('Buyer / Brand')" />
                <x-text-input id="buyer_name" name="buyer_name" type="text" class="mt-1 block w-full" :value="old('buyer_name', $header['buyer_name'])" />
                <x-input-error class="mt-2" :messages="$errors->get('buyer_name')" />
            </div>
            <div>
                <x-input-label for="buyer_reference" :value="__('Customer Order No.')" />
                <x-text-input id="buyer_reference" name="buyer_reference" type="text" class="mt-1 block w-full" :value="old('buyer_reference', $header['order_number'])" />
                <x-input-error class="mt-2" :messages="$errors->get('buyer_reference')" />
            </div>
            @if ($salesPeople->isNotEmpty())
            <div>
                <x-input-label for="sales_person_id" :value="__('Responsible Sales Person')" />
                <select id="sales_person_id" name="sales_person_id"
                        class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                    <option value="">{{ __('— None —') }}</option>
                    @foreach ($salesPeople as $person)
                        <option value="{{ $person->id }}" @selected((int) old('sales_person_id') === $person->id)>{{ $person->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div>
                <x-input-label for="order_date" :value="__('Order (Booking) Date')" />
                <x-text-input id="order_date" name="order_date" type="date" class="mt-1 block w-full" :value="old('order_date', $header['booking_date'] ?? now()->toDateString())" required />
                <x-input-error class="mt-2" :messages="$errors->get('order_date')" />
            </div>
            <div>
                <x-input-label for="delivery_start_date" :value="__('Delivery Start Date')" />
                <x-text-input id="delivery_start_date" name="delivery_start_date" type="date" class="mt-1 block w-full" :value="old('delivery_start_date', $header['delivery_start_date'])" />
                <x-input-error class="mt-2" :messages="$errors->get('delivery_start_date')" />
            </div>
            <div>
                <x-input-label for="delivery_date" :value="__('Delivery Close Date')" />
                <x-text-input id="delivery_date" name="delivery_date" type="date" class="mt-1 block w-full" :value="old('delivery_date', $header['delivery_close_date'])" />
                <x-input-error class="mt-2" :messages="$errors->get('delivery_date')" />
            </div>
        </div>

        <div class="mt-4">
            <x-input-label for="note" :value="__('Note')" />
            <textarea id="note" name="note" rows="3"
                      class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">{{ old('note', $note) }}</textarea>
            <x-input-error class="mt-2" :messages="$errors->get('note')" />
        </div>

        <div class="mt-6 flex items-center gap-3">
            <button type="submit"
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-accent-500 focus:ring-offset-2">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                {{ __('Create Sales Order') }}
            </button>
            <a href="{{ route('sales-orders.import') }}" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">{{ __('Upload a different file') }}</a>
        </div>
    </form>
</x-app-layout>
