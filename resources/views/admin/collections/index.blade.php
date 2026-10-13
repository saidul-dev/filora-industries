<x-app-layout>
    <x-slot name="title">{{ __('Collections') }}</x-slot>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ __('Collections') }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">{{ __('Payments received from customers, settling Accounts Receivable') }}</p>
            </div>
            @can('accounts.create')
            <a href="{{ route('collections.create') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ __('New Collection') }}
            </a>
            @endcan
        </div>
    </x-slot>

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-6">
        <form method="GET" action="{{ route('collections.index') }}" class="flex flex-nowrap items-end gap-4 overflow-x-auto">
            <div class="w-64 shrink-0">
                <x-input-label for="party_id" :value="__('Customer')" />
                <div class="mt-1">
                    <x-searchable-select name="party_id" :options="$customers" :selected="$partyId"
                                          placeholder="{{ __('All customers') }}" :auto-submit="true" />
                </div>
            </div>
        </form>
    </div>

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('Collection') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Customer') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Received In') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Date') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Amount') }}</th>
                    <th class="px-5 py-3 font-semibold"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($collections as $collection)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3 font-semibold text-slate-800">{{ $collection->collection_no }}</td>
                    <td class="px-5 py-3 text-slate-600">
                        {{ $collection->party->name }}
                        @if ($collection->proformaInvoice)<span class="block text-xs text-slate-400">{{ __('Against') }} {{ $collection->proformaInvoice->pi_no }}</span>@endif
                    </td>
                    <td class="px-5 py-3 text-slate-600">{{ $collection->account->name }}</td>
                    <td class="px-5 py-3 text-slate-600">{{ $collection->collection_date->format('d M, Y') }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($collection->amount, 2) }}</td>
                    <td class="px-5 py-3 text-right">
                        <a href="{{ route('collections.show', $collection) }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ __('View') }}</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-5 py-10 text-center text-slate-400">{{ __('No collections yet.') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>

        @if ($collections->hasPages())
        <div class="border-t border-slate-100 px-5 py-4">
            {{ $collections->links() }}
        </div>
        @endif
    </div>
</x-app-layout>
