<x-app-layout>
    <x-slot name="title">{{ __('Inventory Requisitions') }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ __('Inventory Requisitions') }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">{{ __('Requests to buy finished goods or raw materials, raised under a Proforma Invoice.') }}</p>
            </div>
            @can('requisitions.create')
            <a href="{{ route('purchase-requisitions.create') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ __('New Requisition') }}
            </a>
            @endcan
        </div>
    </x-slot>

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-6">
        <form method="GET" action="{{ route('purchase-requisitions.index') }}" class="flex flex-wrap items-end gap-4">
            <div class="w-56">
                <x-input-label for="proforma_invoice_id" :value="__('Proforma Invoice')" />
                <div class="mt-1">
                    <x-searchable-select name="proforma_invoice_id" :options="$invoices" :selected="$invoiceId"
                                          placeholder="{{ __('All PIs') }}" :auto-submit="true" />
                </div>
            </div>
            <div class="w-52">
                <x-input-label for="status" :value="__('Status')" />
                <select id="status" name="status" onchange="this.form.submit()"
                        class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach (\App\Models\PurchaseRequisition::STATUSES as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full min-w-[900px] text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('Requisition') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Proforma Invoice') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Needed At') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Required By') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Items') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Status') }}</th>
                    <th class="px-5 py-3 font-semibold"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($requisitions as $requisition)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $requisition->requisition_no }}</span>
                        <span class="block text-xs text-slate-400">{{ $requisition->request_date->format('d M, Y') }} · {{ $requisition->requestedBy->name ?? '—' }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">
                        {{ $requisition->proformaInvoice->pi_no }}
                        <span class="block text-xs text-slate-400">{{ $requisition->proformaInvoice->party->name }}</span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ $requisition->site->name }}</td>
                    <td class="px-5 py-3 text-slate-600">{{ $requisition->required_by?->format('d M, Y') ?? '—' }}</td>
                    <td class="px-5 py-3 text-right text-slate-600">{{ $requisition->items_count }}</td>
                    <td class="px-5 py-3">
                        <x-status-badge :label="$requisition->status_label" :color="\App\Models\PurchaseRequisition::STATUS_COLORS[$requisition->status] ?? 'slate'" />
                    </td>
                    <td class="px-5 py-3 text-right">
                        <a href="{{ route('purchase-requisitions.show', $requisition) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ __('View') }}</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-5 py-10 text-center text-slate-400">{{ __('No requisitions yet.') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>

        @if ($requisitions->hasPages())
        <div class="border-t border-slate-100 px-5 py-4">
            {{ $requisitions->links() }}
        </div>
        @endif
    </div>
</x-app-layout>
