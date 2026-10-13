<x-app-layout>
    <x-slot name="title">{{ __('Receive Goods') }} — {{ $purchase->purchase_no }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('Receive Goods') }} — {{ $purchase->purchase_no }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ __('Received quantities are added to stock at the receiving location immediately. Leave a line at 0 to receive it later.') }}</p>
        </div>
    </x-slot>

    @php $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 4), '0'), '.') ?: '0'; @endphp

    <form method="POST" action="{{ route('purchases.receive.store', $purchase) }}">
        @csrf

        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 mb-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <x-input-label for="site_id" :value="__('Received At (Factory / Office)')" />
                    <select id="site_id" name="site_id" required
                            class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}" @selected(old('site_id', $purchase->site_id) == $site->id)>{{ $site->name }} ({{ $site->type }})</option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-2" :messages="$errors->get('site_id')" />
                </div>
                <div>
                    <x-input-label for="received_date" :value="__('Received Date')" />
                    <x-text-input id="received_date" name="received_date" type="date" class="mt-1 block w-full"
                                  :value="old('received_date', now()->toDateString())" required />
                    <x-input-error class="mt-2" :messages="$errors->get('received_date')" />
                </div>
                @if ($purchase->isOneTimeVendor())
                <div>
                    <x-input-label for="paid_from_account_id" :value="__('Paid From (one-time vendor)')" />
                    <select id="paid_from_account_id" name="paid_from_account_id" required
                            class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                        <option value="">{{ __('Select cash/bank account…') }}</option>
                        @foreach ($cashBankAccounts as $account)
                            <option value="{{ $account->id }}" @selected(old('paid_from_account_id') == $account->id)>{{ $account->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-2" :messages="$errors->get('paid_from_account_id')" />
                </div>
                @else
                <div class="text-sm">
                    <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Billed To') }}</span>
                    <span class="mt-2 block text-slate-700">{{ __('Accounts Payable — :supplier', ['supplier' => $purchase->vendorName()]) }}</span>
                </div>
                @endif
            </div>
        </div>

        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
            <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] text-sm">
                <thead>
                    <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3 font-semibold">{{ __('Item') }}</th>
                        <th class="px-5 py-3 font-semibold text-right">{{ __('Ordered') }}</th>
                        <th class="px-5 py-3 font-semibold text-right">{{ __('Already Received') }}</th>
                        <th class="px-5 py-3 font-semibold text-right">{{ __('Unit Cost') }}</th>
                        <th class="px-5 py-3 font-semibold w-44">{{ __('Receive Now') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($purchase->items as $item)
                    @continue($item->remaining() <= 0)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-3">
                            <span class="block font-semibold text-slate-800">{{ $item->label() }}</span>
                            <span class="block text-xs text-slate-400">{{ $item->productVariant->sku ?? $item->product->sku }}</span>
                        </td>
                        <td class="px-5 py-3 text-right text-slate-700">{{ $qty($item->quantity) }} {{ $item->product->stockUnit->short_name ?? '' }}</td>
                        <td class="px-5 py-3 text-right text-slate-600">{{ $qty($item->received_quantity) }}</td>
                        <td class="px-5 py-3 text-right text-slate-600">{{ number_format($item->unit_cost, 2) }}</td>
                        <td class="px-5 py-3">
                            <input type="number" step="0.0001" min="0" max="{{ $item->remaining() }}" name="items[{{ $item->id }}][quantity]"
                                   value="{{ old("items.{$item->id}.quantity", $item->remaining()) }}"
                                   class="w-36 rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                            <x-input-error class="mt-1" :messages="$errors->get('items.'.$item->id.'.quantity')" />
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
        <x-input-error class="mb-4" :messages="$errors->get('items')" />

        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <div class="mb-5 max-w-2xl">
                <x-input-label for="note" :value="__('Note (optional)')" />
                <textarea id="note" name="note" rows="2" placeholder="{{ __('Challan no., condition on arrival…') }}"
                          class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">{{ old('note') }}</textarea>
            </div>
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    {{ __('Post Goods Receipt') }}
                </button>
                <a href="{{ route('purchases.show', $purchase) }}" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">{{ __('Cancel') }}</a>
            </div>
        </div>
    </form>
</x-app-layout>
