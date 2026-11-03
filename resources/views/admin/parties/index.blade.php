<x-app-layout>
    <x-slot name="title">{{ __('Parties') }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ __('Parties') }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">{{ __('Customers and suppliers your business deals with') }}</p>
            </div>
            @can('parties.create')
            <a href="{{ route('parties.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus:ring-2 focus:ring-accent-500 focus:ring-offset-2">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ __('Add Party') }}
            </a>
            @endcan
        </div>
    </x-slot>

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 mb-6">
        <form method="GET" action="{{ route('parties.index') }}" class="flex flex-nowrap items-end gap-4 overflow-x-auto">
            <div class="w-64 shrink-0">
                <x-input-label :value="__('Role')" />
                <div class="mt-1 flex h-[38px] w-full items-stretch rounded-lg border border-slate-300 bg-slate-50 p-0.5 text-sm">
                    <a href="{{ request()->fullUrlWithQuery(['role' => null, 'page' => null]) }}"
                       class="flex flex-1 items-center justify-center whitespace-nowrap rounded-md px-2.5 text-center font-medium transition-colors {{ ! $role ? 'bg-brand-800 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800' }}">
                        {{ __('All') }}
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['role' => 'customer', 'page' => null]) }}"
                       class="flex flex-1 items-center justify-center whitespace-nowrap rounded-md px-2.5 text-center font-medium transition-colors {{ $role === 'customer' ? 'bg-brand-800 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800' }}">
                        {{ __('Customers') }}
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['role' => 'supplier', 'page' => null]) }}"
                       class="flex flex-1 items-center justify-center whitespace-nowrap rounded-md px-2.5 text-center font-medium transition-colors {{ $role === 'supplier' ? 'bg-brand-800 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800' }}">
                        {{ __('Suppliers') }}
                    </a>
                </div>
            </div>
            <div class="flex-1 min-w-[200px]">
                <x-input-label for="q" :value="__('Search')" />
                <input type="search" id="q" name="q" value="{{ request('q') }}" placeholder="{{ __('Name, phone or email…') }}"
                       class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
            </div>
            <button type="submit" class="shrink-0 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">{{ __('Filter') }}</button>
        </form>
    </div>

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full min-w-[860px] text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('Name') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Contact') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Role') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Balance') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Status') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($parties as $party)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $party->name }}</span>
                        <span class="block text-xs text-slate-400">
                            {{ $party->is_company ? __('Company') : __('Individual') }}
                            @if ($party->is_company && $party->contact_person)
                                · {{ $party->contact_person }}
                            @endif
                        </span>
                    </td>
                    <td class="px-5 py-3 text-slate-600">
                        <span class="block">{{ $party->phone }}</span>
                        @if ($party->email)
                            <span class="block text-xs text-slate-400">{{ $party->email }}</span>
                        @endif
                    </td>
                    <td class="px-5 py-3">
                        <div class="flex flex-wrap gap-1">
                            @if ($party->is_customer)
                                <span class="inline-flex rounded-full bg-accent-50 px-2.5 py-0.5 text-xs font-semibold text-accent-700 ring-1 ring-accent-200">{{ __('Customer') }}</span>
                            @endif
                            @if ($party->is_supplier)
                                <span class="inline-flex rounded-full bg-violet-50 px-2.5 py-0.5 text-xs font-semibold text-violet-700 ring-1 ring-violet-200">{{ __('Supplier') }}</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-5 py-3 text-right">
                        @php
                            $balance = $balances[$party->id] ?? ['receivable' => 0.0, 'payable' => 0.0];
                            // Receivable: they owe us (due) or paid ahead (advance).
                            // Payable: we owe them (payable) or paid ahead (advance paid).
                            $lines = collect([
                                $balance['receivable'] > 0 ? [$balance['receivable'], __('Receivable'), 'text-rose-600'] : null,
                                $balance['receivable'] < 0 ? [-$balance['receivable'], __('Advance received'), 'text-emerald-600'] : null,
                                $balance['payable'] > 0 ? [$balance['payable'], __('Payable'), 'text-amber-600'] : null,
                                $balance['payable'] < 0 ? [-$balance['payable'], __('Advance paid'), 'text-emerald-600'] : null,
                            ])->filter();
                        @endphp
                        @forelse ($lines as [$amount, $label, $color])
                            <span class="block font-semibold tabular-nums {{ $color }}">{{ number_format($amount, 2) }}</span>
                            <span class="block text-xs text-slate-400 {{ $loop->last ? '' : 'mb-1' }}">{{ $label }}</span>
                        @empty
                            <span class="font-semibold tabular-nums text-slate-400">0.00</span>
                            <span class="block text-xs text-slate-400">{{ __('Settled') }}</span>
                        @endforelse
                    </td>
                    <td class="px-5 py-3">
                        @can('parties.edit')
                        <form method="POST" action="{{ route('parties.toggle-status', $party) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit"
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1
                                    {{ $party->status ? 'bg-emerald-50 text-emerald-600 ring-emerald-200' : 'bg-slate-100 text-slate-500 ring-slate-200' }}">
                                {{ $party->status ? __('Active') : __('Inactive') }}
                            </button>
                        </form>
                        @else
                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1
                            {{ $party->status ? 'bg-emerald-50 text-emerald-600 ring-emerald-200' : 'bg-slate-100 text-slate-500 ring-slate-200' }}">
                            {{ $party->status ? __('Active') : __('Inactive') }}
                        </span>
                        @endcan
                    </td>
                    <td class="px-5 py-3">
                        <div class="flex items-center justify-end gap-1">
                            @can('parties.view')
                            <a href="{{ route('parties.ledger', $party) }}" title="{{ __('Click to view this party\'s ledger') }}"
                               class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-slate-500 ring-1 ring-slate-200 hover:bg-brand-50 hover:text-brand-700 hover:ring-brand-200">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                                {{ __('Ledger') }}
                            </a>
                            @endcan
                            @can('parties.edit')
                            <a href="{{ route('parties.edit', $party) }}" title="{{ __('Edit') }}"
                               class="rounded-lg p-2 text-slate-400 hover:bg-brand-50 hover:text-brand-700">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/></svg>
                            </a>
                            @endcan
                            @can('parties.delete')
                            <form method="POST" action="{{ route('parties.destroy', $party) }}"
                                  onsubmit="return confirm('{{ __('Delete party :name? This cannot be undone.', ['name' => $party->name]) }}');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" title="{{ __('Delete') }}"
                                        class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                </button>
                            </form>
                            @endcan
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-5 py-10 text-center text-slate-400">{{ __('No parties yet — add your first customer or supplier.') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>

        @if ($parties->hasPages())
        <div class="border-t border-slate-100 px-5 py-4">
            {{ $parties->links() }}
        </div>
        @endif
    </div>
</x-app-layout>
