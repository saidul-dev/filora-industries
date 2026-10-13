<x-app-layout>
    <x-slot name="title">{{ __('Proforma Invoices') }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('Proforma Invoices') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ __('The controlling document for procurement, production, LC, delivery and payment.') }}</p>
        </div>
    </x-slot>

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-6">
        <form method="GET" action="{{ route('proforma-invoices.index') }}" class="flex flex-wrap items-end gap-4">
            <div class="w-56">
                <x-input-label for="party_id" :value="__('Buyer')" />
                <div class="mt-1">
                    <x-searchable-select name="party_id" :options="$customers" :selected="$partyId"
                                          placeholder="{{ __('All buyers') }}" :auto-submit="true" />
                </div>
            </div>
            <div class="w-56">
                <x-input-label for="status" :value="__('Status')" />
                <select id="status" name="status" onchange="this.form.submit()"
                        class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach (\App\Models\ProformaInvoice::STATUSES as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-1 min-w-[200px]">
                <x-input-label for="q" :value="__('Search')" />
                <input type="search" id="q" name="q" value="{{ $q }}" placeholder="{{ __('PI no. or LC no.…') }}"
                       class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
            </div>
            <button type="submit" class="rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">{{ __('Filter') }}</button>
        </form>
    </div>

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full min-w-[980px] text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('PI') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Buyer') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Sales Order') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('LC') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Delivery By') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Total') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Status') }}</th>
                    <th class="px-5 py-3 font-semibold"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($invoices as $invoice)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $invoice->pi_no }}</span>
                        <span class="block text-xs text-slate-400">{{ $invoice->pi_date->format('d M, Y') }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ $invoice->party->name }}</td>
                    <td class="px-5 py-3 text-slate-600">{{ $invoice->salesOrder->order_no ?? '—' }}</td>
                    <td class="px-5 py-3 text-slate-600">
                        @if ($invoice->hasLc())
                            {{ $invoice->lc_no }}
                            <span class="block text-xs {{ $invoice->lc_expiry_date?->isPast() ? 'text-rose-500' : 'text-slate-400' }}">{{ __('Expires') }} {{ $invoice->lc_expiry_date?->format('d M, Y') }}</span>
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ $invoice->delivery_date?->format('d M, Y') ?? '—' }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($invoice->total_amount, 2) }}</td>
                    <td class="px-5 py-3">
                        <x-status-badge :label="$invoice->status_label" :color="\App\Models\ProformaInvoice::STATUS_COLORS[$invoice->status] ?? 'slate'" />
                    </td>
                    <td class="px-5 py-3 text-right">
                        <a href="{{ route('proforma-invoices.show', $invoice) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ __('View') }}</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-5 py-10 text-center text-slate-400">{{ __('No Proforma Invoices yet — confirm a Sales Order and generate one from it.') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>

        @if ($invoices->hasPages())
        <div class="border-t border-slate-100 px-5 py-4">
            {{ $invoices->links() }}
        </div>
        @endif
    </div>
</x-app-layout>
