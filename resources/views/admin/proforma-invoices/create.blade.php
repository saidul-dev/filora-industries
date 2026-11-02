<x-app-layout>
    <x-slot name="title">{{ __('New Proforma Invoice') }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ __('New Proforma Invoice') }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">{{ __('Combine one or more confirmed Sales Orders of a customer into one PI, and set the final prices.') }}</p>
            </div>
            <a href="{{ route('proforma-invoices.index') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                {{ __('Back') }}
            </a>
        </div>
    </x-slot>

    @php
        $field = 'block w-full rounded-lg border-slate-300 py-2 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500';
    @endphp

    <!-- Customer -->
    <div class="rounded-2xl bg-white px-6 py-5 shadow-sm ring-1 ring-slate-200 mb-4">
        <form method="GET" action="{{ route('proforma-invoices.create') }}" class="flex flex-wrap items-end gap-4">
            <div class="w-full sm:w-80">
                <x-input-label for="party_id" :value="__('Customer')" />
                <div class="mt-1">
                    <x-searchable-select name="party_id" :options="$customers" :selected="$partyId"
                                          placeholder="{{ __('Select a customer…') }}" :auto-submit="true" />
                </div>
            </div>
            <p class="pb-2 text-xs text-slate-400">{{ __('Only customers with confirmed Sales Orders waiting for a PI are listed.') }}</p>
        </form>
    </div>

    @if (! $partyId)
        <div class="rounded-2xl border-2 border-dashed border-slate-200 px-6 py-12 text-center">
            <p class="text-sm font-semibold text-slate-600">{{ __('Pick a customer to see their confirmed Sales Orders') }}</p>
            <p class="text-xs text-slate-400">{{ $customers->isEmpty() ? __('No confirmed Sales Orders are waiting for a PI right now — confirm an order first.') : __('You can then tick several orders to put them on one PI.') }}</p>
        </div>
    @elseif ($orders->isEmpty())
        <div class="rounded-2xl border-2 border-dashed border-slate-200 px-6 py-12 text-center">
            <p class="text-sm font-semibold text-slate-600">{{ __('This customer has no confirmed Sales Orders waiting for a PI') }}</p>
            <p class="text-xs text-slate-400">{{ __('Confirm their pending orders first, or pick another customer.') }}</p>
        </div>
    @else
    <div x-data="piBuilder(@js([
            'orders' => $orders,
            'selected' => $selected,
            'prices' => old('prices', []),
            'discount' => old('discount'),
            'deliveryDate' => old('delivery_date'),
        ]))">
        <form method="POST" action="{{ route('proforma-invoices.store') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="party_id" value="{{ $partyId }}">

            <!-- Orders -->
            <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <div class="flex flex-wrap items-end justify-between gap-4 px-6 pt-5 pb-4">
                    <div>
                        <h3 class="font-bold text-brand-900">{{ __('Sales Orders') }}</h3>
                        <p class="text-xs text-slate-400">{{ __('Tick every order this PI should cover.') }}</p>
                    </div>
                    <button type="button" @click="toggleAll()" class="text-xs font-semibold text-accent-600 hover:text-accent-700"
                            x-text="selected.length === orders.length ? @js(__('Clear all')) : @js(__('Select all'))"></button>
                </div>

                <div class="grid grid-cols-1 gap-3 px-6 pb-6 md:grid-cols-2 xl:grid-cols-3">
                    <template x-for="order in orders" :key="order.id">
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-colors"
                               :class="selected.includes(order.id) ? 'border-accent-500 bg-cyan-50/60 ring-1 ring-accent-500' : 'border-slate-200 hover:border-slate-300'">
                            <input type="checkbox" name="sales_order_ids[]" :value="order.id" x-model.number="selected"
                                   class="mt-0.5 rounded border-slate-300 text-brand-800 focus:ring-accent-500">
                            <span class="min-w-0 flex-1 text-sm">
                                <span class="flex items-center justify-between gap-2">
                                    <span class="font-semibold text-slate-800" x-text="order.order_no"></span>
                                    <span class="text-xs text-slate-400" x-text="order.order_date"></span>
                                </span>
                                <span class="block truncate text-xs text-slate-500" x-text="order.reference || '—'"></span>
                                <span class="mt-2 flex flex-wrap gap-1.5 text-[11px]">
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-600"
                                          x-text="order.lines.length + ' ' + (order.lines.length === 1 ? @js(__('line')) : @js(__('lines')))"></span>
                                    <template x-if="order.lines.some((l) => l.unit_price === '')">
                                        <span class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-700"
                                              x-text="order.lines.filter((l) => l.unit_price === '').length + ' ' + @js(__('without price'))"></span>
                                    </template>
                                </span>
                            </span>
                        </label>
                    </template>
                </div>
                <x-input-error class="px-6 pb-4" :messages="$errors->get('sales_order_ids')" />
            </div>

            <!-- Lines & prices -->
            <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <div class="flex flex-wrap items-end justify-between gap-4 px-6 pt-5 pb-4">
                    <div>
                        <h3 class="font-bold text-brand-900">{{ __('Lines & Prices') }}</h3>
                        <p class="text-xs text-slate-400">{{ __('Prices come from the order where it has one — change any of them here. The Sales Order itself is left as it was. Goods already delivered against an order are carried onto the PI when it is issued.') }}</p>
                    </div>
                    <span x-show="unpriced" x-cloak class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700"
                          x-text="unpriced + ' ' + (unpriced === 1 ? @js(__('line still needs a price')) : @js(__('lines still need a price')))"></span>
                </div>

                <div x-show="! chosen.length" class="px-6 pb-6">
                    <div class="rounded-xl border-2 border-dashed border-slate-200 px-6 py-10 text-center">
                        <p class="text-sm font-semibold text-slate-600">{{ __('No orders ticked yet') }}</p>
                        <p class="text-xs text-slate-400">{{ __('Tick one or more Sales Orders above — their lines show up here to price.') }}</p>
                    </div>
                </div>

                <div x-show="chosen.length" x-cloak>
                    <div class="overflow-x-auto border-y border-slate-100">
                    <table class="w-full min-w-[900px] text-sm">
                        <thead>
                            <tr class="bg-slate-50 text-left text-xs font-semibold text-slate-500">
                                <th class="py-2.5 pl-6 pr-2">{{ __('Product') }}</th>
                                <th class="w-32 px-2 py-2.5">{{ __('Style') }}</th>
                                <th class="w-28 px-2 py-2.5">{{ __('Color') }}</th>
                                <th class="w-28 px-2 py-2.5 text-right">{{ __('Quantity') }}</th>
                                <th class="w-36 px-2 py-2.5">{{ __('Unit price (USD)') }}</th>
                                <th class="w-32 py-2.5 pl-2 pr-6 text-right">{{ __('Subtotal') }}</th>
                            </tr>
                        </thead>
                        <template x-for="order in chosen" :key="order.id">
                            <tbody class="divide-y divide-slate-100 border-b border-slate-100">
                                <tr class="bg-slate-50/60">
                                    <td colspan="6" class="py-2 pl-6 pr-6 text-xs">
                                        <span class="font-semibold text-brand-900" x-text="order.order_no"></span>
                                        <span class="text-slate-400" x-show="order.reference" x-text="'— ' + order.reference"></span>
                                    </td>
                                </tr>
                                <template x-for="line in order.lines" :key="line.line_id">
                                    <tr class="hover:bg-slate-50/70">
                                        <td class="py-2.5 pl-6 pr-2">
                                            <span class="font-semibold text-slate-800" x-text="line.name"></span>
                                        </td>
                                        <td class="px-2 py-2.5 text-slate-600" x-text="line.style || '—'"></td>
                                        <td class="px-2 py-2.5 text-slate-600" x-text="line.color || '—'"></td>
                                        <td class="px-2 py-2.5 text-right tabular-nums text-slate-700">
                                            <span x-text="line.quantity + ' ' + (line.unit || '')"></span>
                                            <span x-show="line.delivered > 0" class="block text-xs text-teal-600" x-text="line.delivered + ' ' + @js(__('already delivered'))"></span>
                                        </td>
                                        <td class="px-2 py-2">
                                            <input type="number" step="0.0001" min="0" required :name="'prices[' + line.line_id + ']'" x-model="line.unit_price"
                                                   class="block w-full rounded-md py-1.5 text-sm focus:border-accent-500 focus:ring-accent-500"
                                                   :class="line.unit_price === '' ? 'border-amber-400 bg-amber-50/50' : 'border-slate-300'"
                                                   placeholder="{{ __('Required') }}">
                                        </td>
                                        <td class="py-2.5 pl-2 pr-6 text-right font-semibold tabular-nums text-slate-800"
                                            x-text="line.unit_price === '' ? '—' : (Math.round((Number(line.quantity) || 0) * (Number(line.unit_price) || 0) * 100) / 100).toFixed(2)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </template>
                    </table>
                    </div>

                    <div class="flex justify-end px-6 py-5">
                        <dl class="w-full max-w-xs space-y-2 text-sm">
                            <div class="flex items-center justify-between">
                                <dt class="text-slate-500">{{ __('Subtotal') }} <span class="text-xs text-slate-400" x-text="'(' + lineCount + ' ' + (lineCount === 1 ? @js(__('line')) : @js(__('lines'))) + ')'"></span></dt>
                                <dd class="font-semibold tabular-nums text-slate-800" x-text="subtotal.toFixed(2)"></dd>
                            </div>
                            <div class="flex items-center justify-between gap-4">
                                <dt><label for="discount" class="text-slate-500">{{ __('Discount') }}</label></dt>
                                <dd>
                                    <input id="discount" type="number" step="0.01" min="0" name="discount" x-model="discount" @input="discountTouched = true"
                                           class="w-32 rounded-md border-slate-300 py-1.5 text-right text-sm tabular-nums focus:border-accent-500 focus:ring-accent-500" placeholder="0.00">
                                </dd>
                            </div>
                            <div class="flex items-baseline justify-between border-t border-slate-200 pt-3">
                                <dt class="font-semibold text-brand-900">{{ __('Total') }}</dt>
                                <dd class="text-lg font-bold tabular-nums text-brand-900" x-text="'USD ' + total.toFixed(2)"></dd>
                            </div>
                        </dl>
                    </div>
                </div>

                @if ($errors->has('prices.*') || $errors->has('discount'))
                <div class="border-t border-slate-100 px-6 py-3">
                    <x-input-error class="mb-1" :messages="$errors->get('discount')" />
                    @foreach ($errors->get('prices.*') as $messages)
                        <x-input-error class="mb-1" :messages="$messages" />
                    @endforeach
                </div>
                @endif
            </div>

            <!-- PI details -->
            <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <div class="px-6 pt-5 pb-4">
                    <h3 class="font-bold text-brand-900">{{ __('PI Details') }}</h3>
                    <p class="text-xs text-slate-400">{{ __('Delivery date follows the latest of the ticked orders unless you change it.') }}</p>
                </div>
                <div class="grid grid-cols-1 gap-4 px-6 pb-5 md:grid-cols-3">
                    <div>
                        <x-input-label for="pi_date" :value="__('PI Date')" />
                        <input id="pi_date" name="pi_date" type="date" class="mt-1 {{ $field }}" required
                               value="{{ old('pi_date', now()->toDateString()) }}">
                        <x-input-error class="mt-2" :messages="$errors->get('pi_date')" />
                    </div>
                    <div>
                        <x-input-label for="valid_until" :value="__('Valid Until (optional)')" />
                        <input id="valid_until" name="valid_until" type="date" class="mt-1 {{ $field }}" value="{{ old('valid_until') }}">
                        <x-input-error class="mt-2" :messages="$errors->get('valid_until')" />
                    </div>
                    <div>
                        <x-input-label for="delivery_date" :value="__('Delivery Date (optional)')" />
                        <input id="delivery_date" name="delivery_date" type="date" class="mt-1 {{ $field }}"
                               x-model="deliveryDate" @input="deliveryTouched = true">
                        <x-input-error class="mt-2" :messages="$errors->get('delivery_date')" />
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-4 px-6 pb-5 md:grid-cols-2">
                    <div>
                        <x-input-label for="terms" :value="__('Terms & Conditions (printed on the PI)')" />
                        <textarea id="terms" name="terms" rows="3" class="mt-1 {{ $field }}">{{ old('terms', $orders->pluck('terms')->filter()->first()) }}</textarea>
                        <x-input-error class="mt-2" :messages="$errors->get('terms')" />
                    </div>
                    <div>
                        <x-input-label for="note" :value="__('Internal Note (optional)')" />
                        <textarea id="note" name="note" rows="3" class="mt-1 {{ $field }}">{{ old('note') }}</textarea>
                        <x-input-error class="mt-2" :messages="$errors->get('note')" />
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3 rounded-b-2xl border-t border-slate-100 bg-slate-50/60 px-6 py-4">
                    <p class="mr-auto text-xs text-slate-400"
                       x-text="! chosen.length ? @js(__('Tick at least one Sales Order to continue.')) : (unpriced ? @js(__('Every line needs a price before the PI can be created.')) : @js(__('The PI is created as a draft — you can still edit it before issuing.')))"></p>
                    <a href="{{ route('proforma-invoices.index') }}"
                       class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">{{ __('Cancel') }}</a>
                    <button type="submit" :disabled="! chosen.length || unpriced > 0"
                            class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-accent-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                        <span x-text="chosen.length > 1 ? @js(__('Create PI from')) + ' ' + chosen.length + ' ' + @js(__('orders')) : @js(__('Create Proforma Invoice'))"></span>
                    </button>
                </div>
            </div>
        </form>
    </div>
    @endif
</x-app-layout>
