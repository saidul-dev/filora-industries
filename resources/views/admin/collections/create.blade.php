<x-app-layout>
    <x-slot name="title">{{ __('New Collection') }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('New Collection') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ __('Collect from a customer — this reduces their Accounts Receivable balance and increases the receiving account\'s balance.') }}</p>
        </div>
    </x-slot>

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-6">
        <form method="GET" action="{{ route('collections.create') }}" class="flex flex-nowrap items-end gap-4 overflow-x-auto">
            <div class="w-64 shrink-0">
                <x-input-label for="party_id" :value="__('Customer')" />
                <div class="mt-1">
                    <x-searchable-select name="party_id" :options="$customers" :selected="$party?->id"
                                          placeholder="{{ __('Select a customer…') }}" :auto-submit="true" />
                </div>
            </div>
        </form>
    </div>

    @if (! $party)
        <div class="rounded-2xl bg-white p-14 text-center shadow-sm ring-1 ring-slate-200">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-brand-50 text-brand-700">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-9-8.25h19.5v9.75a1.5 1.5 0 0 1-1.5 1.5h-16.5a1.5 1.5 0 0 1-1.5-1.5V6.75a1.5 1.5 0 0 1 1.5-1.5Z"/>
                </svg>
            </div>
            <p class="mt-4 text-sm font-semibold text-slate-700">{{ __('No customer selected') }}</p>
            <p class="mt-1 text-sm text-slate-400">{{ __('Pick a customer above to record a collection.') }}</p>
        </div>
    @else
        @php
            $initials = collect(explode(' ', trim($party->name)))
                ->filter()
                ->take(2)
                ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))
                ->implode('');
        @endphp

        <form method="POST" action="{{ route('collections.store') }}" id="collectionForm">
            @csrf
            <input type="hidden" name="party_id" value="{{ $party->id }}">

            <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-6 items-start">
                <div class="order-2 lg:order-1 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 space-y-5">
                    <div>
                        <x-input-label for="ledger_account_id" :value="__('Receive Into')" />
                        <div class="mt-1">
                            <x-searchable-select name="ledger_account_id" :options="$accounts" :selected="old('ledger_account_id')"
                                                  placeholder="{{ __('Select a cash/bank account…') }}" />
                        </div>
                        <x-input-error class="mt-2" :messages="$errors->get('ledger_account_id')" />
                    </div>

                    @if ($invoices->isNotEmpty())
                    <div>
                        <x-input-label for="proforma_invoice_id" :value="__('Against Proforma Invoice (optional)')" />
                        <div class="mt-1">
                            <x-searchable-select name="proforma_invoice_id" :options="$invoices" :selected="old('proforma_invoice_id', $selectedInvoice?->id)"
                                                  placeholder="{{ __('Not tied to a PI') }}" />
                        </div>
                        <p class="mt-1 text-[11px] text-slate-400">{{ __('LC proceeds, advances or balance payments for a specific PI.') }}</p>
                        <x-input-error class="mt-2" :messages="$errors->get('proforma_invoice_id')" />
                    </div>
                    @endif

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="amount" :value="__('Amount')" />
                            <x-text-input id="amount" name="amount" type="number" step="0.01" min="0.01" class="mt-1 block w-full"
                                          :value="old('amount', $selectedInvoice && $selectedInvoice->dueAmount() > 0 ? $selectedInvoice->dueAmount() : null)" required />
                            <x-input-error class="mt-2" :messages="$errors->get('amount')" />
                        </div>
                        <div>
                            <x-input-label for="collection_date" :value="__('Collection Date')" />
                            <x-text-input id="collection_date" name="collection_date" type="date" class="mt-1 block w-full"
                                          :value="old('collection_date', now()->toDateString())" required />
                            <x-input-error class="mt-2" :messages="$errors->get('collection_date')" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="reference_no" :value="__('Reference No. (optional)')" />
                        <x-text-input id="reference_no" name="reference_no" type="text" class="mt-1 block w-full"
                                      :value="old('reference_no')" placeholder="{{ __('Cheque no. / transaction ID') }}" />
                        <x-input-error class="mt-2" :messages="$errors->get('reference_no')" />
                    </div>

                    <div>
                        <x-input-label for="note" :value="__('Note (optional)')" />
                        <textarea id="note" name="note" rows="2"
                                  class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">{{ old('note') }}</textarea>
                        <x-input-error class="mt-2" :messages="$errors->get('note')" />
                    </div>

                    <div class="pt-1 lg:hidden">
                        <x-primary-button class="w-full justify-center !text-sm !py-2.5">{{ __('Record Collection') }}</x-primary-button>
                    </div>
                </div>

                <div class="order-1 lg:order-2 lg:sticky lg:top-6 rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden">
                    <div class="p-5">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-800 text-sm font-bold text-white">
                                {{ $initials ?: '?' }}
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-slate-800">{{ $party->name }}</p>
                                <p class="text-xs text-slate-400">{{ __('Customer') }}</p>
                            </div>
                        </div>

                        <div class="mt-5 rounded-xl bg-slate-50 p-4 ring-1 ring-slate-100">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Current Receivable') }}</p>
                            <p class="mt-1 text-2xl font-bold {{ $receivable > 0 ? 'text-amber-600' : 'text-slate-800' }}">
                                {{ number_format($receivable, 2) }}
                            </p>
                            @if ($receivable <= 0)
                            <p class="mt-1 text-xs text-slate-400">{{ __('Nothing currently due — a collection here records an advance.') }}</p>
                            @endif
                        </div>

                        @if ($receivable > 0)
                        <div class="mt-4 flex gap-2" id="quickAmounts">
                            <button type="button" data-quick-amount="0.5"
                                    class="flex-1 rounded-lg border border-slate-200 py-1.5 text-xs font-semibold text-slate-600 hover:border-brand-300 hover:text-brand-800">50%</button>
                            <button type="button" data-quick-amount="1"
                                    class="flex-1 rounded-lg border border-brand-200 bg-brand-50 py-1.5 text-xs font-semibold text-brand-800 hover:bg-brand-100">{{ __('Full') }}</button>
                        </div>
                        @endif

                        <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-4 text-sm">
                            <span class="text-slate-500">{{ __('Balance after') }}</span>
                            <span id="balanceAfterPreview" class="font-bold text-slate-800">{{ number_format($receivable, 2) }}</span>
                        </div>
                    </div>

                    <div class="hidden lg:block border-t border-slate-100 p-5">
                        <x-primary-button class="w-full justify-center !text-sm !py-2.5">{{ __('Record Collection') }}</x-primary-button>
                    </div>
                </div>
            </div>
        </form>

        <script>
            (function () {
                var receivable = {{ (float) $receivable }};
                var amountInput = document.getElementById('amount');
                var preview = document.getElementById('balanceAfterPreview');

                function formatAmount(n) {
                    return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);
                }

                function updatePreview() {
                    var amount = parseFloat(amountInput.value) || 0;
                    var after = receivable - amount;
                    preview.textContent = formatAmount(after);
                    preview.classList.toggle('text-emerald-600', after <= 0 && amount > 0);
                    preview.classList.toggle('text-amber-600', after > 0);
                    preview.classList.toggle('text-slate-800', amount === 0);
                }

                amountInput.addEventListener('input', updatePreview);

                document.querySelectorAll('[data-quick-amount]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var fraction = parseFloat(btn.getAttribute('data-quick-amount'));
                        var value = receivable * fraction;
                        amountInput.value = value > 0 ? value.toFixed(2) : '';
                        updatePreview();
                    });
                });

                updatePreview();
            })();
        </script>
    @endif
</x-app-layout>
