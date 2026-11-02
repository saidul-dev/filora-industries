<x-app-layout>
    <x-slot name="title">{{ __('New Delivery') }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('New Delivery') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ __('Deliver finished goods against a Proforma Invoice, or straight against a confirmed Sales Order — from stock at the Factory or the Office, in as many lots as needed.') }}</p>
        </div>
    </x-slot>

    @php
        $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 4), '0'), '.') ?: '0';
        $byOrder = $source === 'order';
        $tab = 'inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold';
    @endphp

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-6">
        <div class="mb-4 inline-flex gap-1 rounded-xl bg-slate-100 p-1">
            <a href="{{ route('deliveries.create') }}"
               class="{{ $tab }} {{ $byOrder ? 'text-slate-500 hover:text-slate-700' : 'bg-white text-brand-900 shadow-sm' }}">{{ __('Against a Proforma Invoice') }}</a>
            <a href="{{ route('deliveries.create', ['source' => 'order']) }}"
               class="{{ $tab }} {{ $byOrder ? 'bg-white text-brand-900 shadow-sm' : 'text-slate-500 hover:text-slate-700' }}">{{ __('Against a Sales Order') }}</a>
        </div>

        <form method="GET" action="{{ route('deliveries.create') }}" class="flex flex-wrap items-end gap-4">
            <input type="hidden" name="source" value="{{ $source }}">
            <div class="w-96 max-w-full">
                @if ($byOrder)
                    <x-input-label for="sales_order_id" :value="__('Sales Order')" />
                    <div class="mt-1">
                        <x-searchable-select name="sales_order_id" :options="$orders" :selected="$order?->id"
                                              placeholder="{{ __('Select a confirmed order…') }}" :auto-submit="true" />
                    </div>
                @else
                    <x-input-label for="proforma_invoice_id" :value="__('Proforma Invoice')" />
                    <div class="mt-1">
                        <x-searchable-select name="proforma_invoice_id" :options="$invoices" :selected="$invoice?->id"
                                              placeholder="{{ __('Select an open PI…') }}" :auto-submit="true" />
                    </div>
                @endif
            </div>
            @if ($document)
            <div class="w-56">
                <x-input-label for="site_id" :value="__('Deliver From')" />
                <div class="mt-1">
                    <x-searchable-select name="site_id" :options="$sites" :selected="$site?->id" :auto-submit="true" />
                </div>
            </div>
            @endif
        </form>
        @if ($byOrder)
            <p class="mt-3 text-xs text-slate-400">{{ __('Only confirmed orders not yet on a Proforma Invoice are listed. A PI raised later picks these challans up.') }}</p>
        @endif
    </div>

    @if (! $document)
        <div class="rounded-2xl bg-white p-10 text-center text-slate-400 shadow-sm ring-1 ring-slate-200">
            {{ $byOrder ? __('Pick a Sales Order above to see what is still to be delivered.') : __('Pick a Proforma Invoice above to see what is still to be delivered.') }}
        </div>
    @elseif ($lines->isEmpty())
        <div class="rounded-2xl bg-white p-10 text-center text-slate-400 shadow-sm ring-1 ring-slate-200">
            {{ __('Everything on :pi has already been delivered.', ['pi' => $invoice->pi_no ?? $order->order_no]) }}
        </div>
    @else
        <form method="POST" action="{{ route('deliveries.store') }}">
            @csrf
            @if ($byOrder)
                <input type="hidden" name="sales_order_id" value="{{ $order->id }}">
            @else
                <input type="hidden" name="proforma_invoice_id" value="{{ $invoice->id }}">
            @endif
            <input type="hidden" name="site_id" value="{{ $site->id }}">

            <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
                <div class="px-6 pt-5 pb-3">
                    <h3 class="font-bold text-brand-900">{{ $invoice->pi_no ?? $order->order_no }} — {{ $document->party->name }}</h3>
                    <p class="text-xs text-slate-400">
                        {{ __('Delivering from :site. A line can be left at 0 and delivered later.', ['site' => $site->name]) }}
                        @if ($byOrder) {{ __('Prices come from the order; a line without one needs a price to be delivered.') }} @endif
                    </p>
                </div>
                <div class="overflow-x-auto">
                <table class="w-full min-w-[820px] text-sm">
                    <thead>
                        <tr class="border-y border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <th class="px-5 py-3 font-semibold">{{ __('Product') }}</th>
                            <th class="px-5 py-3 font-semibold text-right">{{ $byOrder ? __('Ordered') : __('PI Qty') }}</th>
                            <th class="px-5 py-3 font-semibold text-right">{{ __('Remaining') }}</th>
                            <th class="px-5 py-3 font-semibold text-right">{{ __('In Stock Here') }}</th>
                            @if ($byOrder)
                            <th class="px-5 py-3 font-semibold w-40">{{ __('Unit Price (USD)') }}</th>
                            @endif
                            <th class="px-5 py-3 font-semibold w-44">{{ __('Deliver Now') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($lines as $line)
                        @php $max = min($line->remaining, max($line->available, 0)); @endphp
                        <tr class="hover:bg-slate-50 align-top">
                            <td class="px-5 py-3">
                                <span class="block font-semibold text-slate-800">{{ $line->item->label() }}</span>
                                <span class="block text-xs text-slate-400">{{ $line->item->productVariant->sku ?? $line->item->product->sku }}</span>
                                @if ($line->item->lineDetails())
                                    <span class="block text-xs text-slate-600">{{ $line->item->lineDetails() }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $qty($line->item->quantity) }} {{ $line->item->product->stockUnit->short_name ?? '' }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-amber-600">{{ $qty($line->remaining) }}</td>
                            <td class="px-5 py-3 text-right font-semibold {{ $line->available >= $line->remaining ? 'text-emerald-600' : 'text-rose-600' }}">{{ $qty($line->available) }}</td>
                            @if ($byOrder)
                            <td class="px-5 py-3">
                                <input type="number" step="0.0001" min="0" name="items[{{ $line->item->id }}][unit_price]"
                                       value="{{ old("items.{$line->item->id}.unit_price", $line->item->unit_price !== null ? (float) $line->item->unit_price : '') }}"
                                       placeholder="{{ __('Required') }}" @disabled($max <= 0)
                                       class="w-32 rounded-lg text-sm focus:border-accent-500 focus:ring-accent-500 disabled:bg-slate-100 {{ $line->item->unit_price === null ? 'border-amber-400 bg-amber-50/50' : 'border-slate-300' }}">
                                <x-input-error class="mt-1" :messages="$errors->get('items.'.$line->item->id.'.unit_price')" />
                            </td>
                            @endif
                            <td class="px-5 py-3">
                                <input type="number" step="0.0001" min="0" max="{{ $max }}" name="items[{{ $line->item->id }}][quantity]"
                                       value="{{ old("items.{$line->item->id}.quantity", $max) }}" @disabled($max <= 0)
                                       class="w-36 rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500 disabled:bg-slate-100">
                                <x-input-error class="mt-1" :messages="$errors->get('items.'.$line->item->id.'.quantity')" />
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
            <x-input-error class="mb-4" :messages="$errors->get('items')" />
            <x-input-error class="mb-4" :messages="$errors->get('proforma_invoice_id')" />
            <x-input-error class="mb-4" :messages="$errors->get('sales_order_id')" />

            <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
                    <div>
                        <x-input-label for="delivery_date" :value="__('Delivery Date')" />
                        <x-text-input id="delivery_date" name="delivery_date" type="date" class="mt-1 block w-full" :value="old('delivery_date', now()->toDateString())" required />
                        <x-input-error class="mt-2" :messages="$errors->get('delivery_date')" />
                    </div>
                    <div>
                        <x-input-label for="usd_rate" :value="__('USD Rate (BDT per 1 USD)')" />
                        <x-text-input id="usd_rate" name="usd_rate" type="number" step="0.0001" min="0.0001" class="mt-1 block w-full"
                                      :value="old('usd_rate', $usdRate)" required />
                        <p class="mt-1 text-xs text-slate-400">{{ __('Prices are in USD; the ledger is posted in BDT at this rate.') }}</p>
                        <x-input-error class="mt-2" :messages="$errors->get('usd_rate')" />
                    </div>
                    <div>
                        <x-input-label for="vehicle_no" :value="__('Vehicle No. (optional)')" />
                        <x-text-input id="vehicle_no" name="vehicle_no" type="text" class="mt-1 block w-full" :value="old('vehicle_no')" />
                    </div>
                    <div>
                        <x-input-label for="receiver_name" :value="__('Received By (optional)')" />
                        <x-text-input id="receiver_name" name="receiver_name" type="text" class="mt-1 block w-full" :value="old('receiver_name')" />
                    </div>
                </div>
                <div class="mb-5 max-w-2xl">
                    <x-input-label for="note" :value="__('Note (optional)')" />
                    <textarea id="note" name="note" rows="2"
                              class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">{{ old('note') }}</textarea>
                </div>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>
                    {{ __('Post Delivery') }}
                </button>
            </div>
        </form>
    @endif
</x-app-layout>
