<x-app-layout>
    <x-slot name="title">{{ $order->production_no }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ $order->production_no }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">{{ __('Production Order — :product', ['product' => $order->label()]) }}</p>
            </div>
            <a href="{{ route('production-orders.index') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                {{ __('Back') }}
            </a>
        </div>
    </x-slot>

    @php
        $user = auth()->user();
        $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 4), '0'), '.') ?: '0';
        $single = $order->items->count() === 1;
        $unit = $single ? ($order->items->first()->product->stockUnit->short_name ?? '') : '';
        $completeErrors = collect($errors->keys())->contains(fn ($k) => str_starts_with($k, 'produced'));
        $steps = ['not_started', 'in_progress', 'completed'];
        $currentStep = array_search($order->status, $steps, true);
    @endphp

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6 mb-4" x-data="{ completing: {{ $completeErrors ? 'true' : 'false' }} }">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="text-sm">
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ $single ? __('Product') : __('Products') }}</span>
                <span class="font-semibold text-slate-800">{{ $single ? $order->label() : __(':count lines', ['count' => $order->items->count()]) }}</span>
            </div>
            <x-status-badge size="lg" :label="$order->status_label" :color="\App\Models\ProductionOrder::STATUS_COLORS[$order->status] ?? 'slate'" />
        </div>

        @if ($order->status !== 'cancelled')
        <!-- Status progression -->
        <ol class="mt-5 flex items-center gap-2 text-xs font-semibold">
            @foreach ($steps as $i => $step)
            <li class="flex items-center gap-2">
                <span class="grid h-6 w-6 place-items-center rounded-full {{ $currentStep !== false && $i <= $currentStep ? 'bg-brand-800 text-white' : 'bg-slate-100 text-slate-400' }}">{{ $i + 1 }}</span>
                <span class="{{ $currentStep !== false && $i <= $currentStep ? 'text-brand-900' : 'text-slate-400' }}">{{ __(\App\Models\ProductionOrder::STATUSES[$step]) }}</span>
                @if (! $loop->last)<span class="mx-1 h-px w-8 bg-slate-200"></span>@endif
            </li>
            @endforeach
        </ol>
        @endif

        <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ $order->proformaInvoice ? __('Proforma Invoice') : __('Sales Order') }}</span>
                @can('sales.view')
                <a href="{{ $order->sourceUrl() }}" class="font-semibold text-accent-600 hover:text-accent-800">{{ $order->sourceNo() }}</a>
                @else
                <span class="block text-slate-700">{{ $order->sourceNo() }}</span>
                @endcan
                <span class="block text-xs text-slate-400">{{ $order->buyer()?->name }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Production Site') }}</span>
                <span class="block text-slate-700">{{ $order->site->name }}</span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Quantity') }}</span>
                <span class="block font-semibold text-slate-800">{{ $qty($order->totalQuantity()) }} {{ $unit }}</span>
                @if ($order->status === 'completed')
                <span class="block text-xs text-slate-500">{{ __('Produced') }}: {{ $qty($order->totalProduced()) }} {{ $unit }}</span>
                @endif
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Production Cost') }}</span>
                <span class="block font-semibold text-slate-800">{{ number_format($order->totalCost(), 2) }}</span>
                <span class="block text-xs text-slate-500">{{ __('Materials') }} {{ number_format($order->material_cost, 2) }} · {{ __('Third-party') }} {{ number_format($order->service_cost, 2) }}</span>
                @if ($single && $order->totalQuantity() > 0)
                <span class="block text-xs text-slate-500">{{ __('per unit') }} {{ number_format($order->totalCost() / ($order->totalProduced() ?: $order->totalQuantity()), 2) }}</span>
                @endif
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Planned') }}</span>
                <span class="block text-slate-700">{{ $order->start_date?->format('d M, Y') ?? '—' }} → <span class="{{ $order->isOpen() && $order->due_date?->isPast() ? 'font-semibold text-rose-600' : '' }}">{{ $order->due_date?->format('d M, Y') ?? '—' }}</span></span>
            </div>
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Issued') }}</span>
                <span class="block text-slate-700">{{ $order->created_at->format('d M, Y') }} {{ __('by') }} {{ $order->creator->name ?? '—' }}</span>
            </div>
            @if ($order->started_at)
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Started') }}</span>
                <span class="block text-slate-700">{{ $order->started_at->format('d M, Y H:i') }}</span>
            </div>
            @endif
            @if ($order->completed_at)
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Completed') }}</span>
                <span class="block text-slate-700">{{ $order->completed_at->format('d M, Y H:i') }} {{ __('by') }} {{ $order->completedBy->name ?? '—' }}</span>
            </div>
            @endif
            @if ($order->note)
            <div class="sm:col-span-2">
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Note') }}</span>
                <span class="block whitespace-pre-line text-slate-700">{{ $order->note }}</span>
            </div>
            @endif
        </div>

        @if ($order->isOpen())
        <div class="mt-5 flex flex-wrap gap-3 border-t border-slate-100 pt-5">
            @if ($order->status === 'not_started' && $user->can('production.edit'))
            <form method="POST" action="{{ route('production-orders.start', $order) }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-100">
                    {{ __('Start Production') }}
                </button>
            </form>
            @endif

            @can('production.approve')
            <button type="button" @click="completing = ! completing"
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                {{ __('Complete & Receive Into Stock') }}
            </button>
            @endcan

            @can('production.delete')
            <form method="POST" action="{{ route('production-orders.cancel', $order) }}" class="ml-auto"
                  onsubmit="return confirm('{{ __('Cancel :no? Its raw materials will be returned to stock.', ['no' => $order->production_no]) }}');">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-100">
                    {{ __('Cancel Order') }}
                </button>
            </form>
            @endcan
        </div>

        @can('production.approve')
        <form method="POST" action="{{ route('production-orders.complete', $order) }}" x-show="completing" x-cloak class="mt-4 max-w-3xl rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
            @csrf
            <p class="mb-3 text-xs font-semibold text-slate-500">{{ __('Quantity produced per line — received into :site stock.', ['site' => $order->site->name]) }}</p>
            <div class="space-y-2">
                @foreach ($order->items as $item)
                <div class="flex flex-wrap items-center gap-3">
                    <label for="produced_{{ $item->id }}" class="min-w-0 flex-1 text-sm">
                        <span class="block font-semibold text-slate-800">{{ $item->label() }}</span>
                        <span class="block text-xs text-slate-500">{{ __('Planned') }} {{ $qty($item->quantity) }} {{ $item->product->stockUnit->short_name ?? '' }}{{ $item->lineDetails() ? ' · '.$item->lineDetails() : '' }}</span>
                    </label>
                    <x-text-input id="produced_{{ $item->id }}" name="produced[{{ $item->id }}]" type="number" step="0.0001" min="0" max="{{ (float) $item->quantity }}"
                                  class="block w-40 text-right" :value="old('produced.'.$item->id, (float) $item->quantity)" required />
                </div>
                <x-input-error :messages="$errors->get('produced.'.$item->id)" />
                @endforeach
            </div>
            <x-input-error class="mt-2" :messages="array_merge($errors->get('produced'), $errors->get('produced_quantity'))" />
            <button type="submit" class="mt-4 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                {{ __('Receive into :site', ['site' => $order->site->name]) }}
            </button>
        </form>
        @endcan
        @endif
    </div>

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4">
        <div class="px-6 pt-5 pb-3">
            <h3 class="font-bold text-brand-900">{{ __('Products') }}</h3>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-sm">
            <thead>
                <tr class="border-y border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('Product') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Quantity') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Produced') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Material Cost') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Third-Party') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Cost / Unit') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($order->items as $item)
                @php
                    $itemUnit = $item->product->stockUnit->short_name ?? '';
                    $base = (float) ($item->produced_quantity ?: $item->quantity);
                @endphp
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $item->label() }}</span>
                        @if ($item->lineDetails())<span class="block text-xs text-slate-500">{{ $item->lineDetails() }}</span>@endif
                    </td>
                    <td class="px-5 py-3 text-right text-slate-700">{{ $qty($item->quantity) }} {{ $itemUnit }}</td>
                    <td class="px-5 py-3 text-right {{ $item->produced_quantity !== null ? 'font-semibold text-emerald-600' : 'text-slate-400' }}">{{ $item->produced_quantity !== null ? $qty($item->produced_quantity).' '.$itemUnit : '—' }}</td>
                    <td class="px-5 py-3 text-right text-slate-700">{{ number_format($item->material_cost, 2) }}</td>
                    <td class="px-5 py-3 text-right {{ (float) $item->service_cost > 0 ? 'text-slate-700' : 'text-slate-400' }}">{{ number_format($item->service_cost, 2) }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ $base > 0 ? number_format($item->totalCost() / $base, 2) : '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>

    @php
        $canEditServices = $order->isOpen() && $user->can('production.edit');
        $serviceErrors = $errors->getBag('service');
    @endphp
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden mb-4"
         x-data="{
            adding: {{ $serviceErrors->any() ? 'true' : 'false' }},
            quantity: @js(old('quantity')),
            rate: @js(old('rate')),
            amount: @js(old('amount')),
            calc() { if (this.quantity && this.rate) this.amount = (Number(this.quantity) * Number(this.rate)).toFixed(2); },
         }">
        <div class="flex flex-wrap items-end justify-between gap-4 px-6 pt-5 pb-3">
            <div>
                <h3 class="font-bold text-brand-900">{{ __('Third-Party Work') }}</h3>
                <p class="text-xs text-slate-400">{{ __('Dyeing, printing, embroidery and other work done outside. Each entry is posted to the supplier\'s account and added to the finished goods\' cost.') }}</p>
            </div>
            @if ($canEditServices)
            <button type="button" @click="adding = ! adding"
                    class="inline-flex items-center gap-2 rounded-lg border border-brand-200 bg-white px-4 py-2 text-sm font-semibold text-brand-800 hover:bg-brand-50">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ __('Add Third-Party Work') }}
            </button>
            @endif
        </div>

        @if ($canEditServices)
        <form method="POST" action="{{ route('production-orders.services.store', $order) }}" x-show="adding" x-cloak
              class="mx-6 mb-4 rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
            @csrf
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <div class="flex items-center justify-between gap-2">
                        <x-input-label for="party_id" :value="__('Supplier / Vendor')" />
                        <x-quick-add-party role="supplier" />
                    </div>
                    <div class="mt-1">
                        <x-searchable-select name="party_id" :options="$suppliers" :selected="old('party_id')" placeholder="{{ __('Select a supplier…') }}" />
                    </div>
                    <x-input-error class="mt-1" :messages="$serviceErrors->get('party_id')" />
                </div>
                <div>
                    <x-input-label for="service" :value="__('Work Done')" />
                    <x-text-input id="service" name="service" list="service-suggestions" class="mt-1 block w-full" :value="old('service')" placeholder="{{ __('e.g. Dyeing') }}" required />
                    <datalist id="service-suggestions">
                        @foreach (\App\Models\ProductionOrderService::COMMON_SERVICES as $suggestion)
                        <option value="{{ $suggestion }}">
                        @endforeach
                    </datalist>
                    <x-input-error class="mt-1" :messages="$serviceErrors->get('service')" />
                </div>
                <div>
                    <x-input-label for="production_order_item_id" :value="__('For')" />
                    <select id="production_order_item_id" name="production_order_item_id"
                            class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                        <option value="">{{ $single ? $order->label() : __('Whole order (split by quantity)') }}</option>
                        @unless ($single)
                        @foreach ($order->items as $item)
                        <option value="{{ $item->id }}" @selected((string) old('production_order_item_id') === (string) $item->id)>{{ $item->label() }}{{ $item->lineDetails() ? ' · '.$item->lineDetails() : '' }}</option>
                        @endforeach
                        @endunless
                    </select>
                    <x-input-error class="mt-1" :messages="$serviceErrors->get('production_order_item_id')" />
                </div>
                <div>
                    <x-input-label for="service_date" :value="__('Date')" />
                    <x-text-input id="service_date" name="service_date" type="date" class="mt-1 block w-full" :value="old('service_date', now()->toDateString())" required />
                    <x-input-error class="mt-1" :messages="$serviceErrors->get('service_date')" />
                </div>
                <div>
                    <x-input-label for="service_quantity" :value="__('Quantity (optional)')" />
                    <input id="service_quantity" name="quantity" type="number" step="0.0001" min="0" x-model="quantity" @input="calc()"
                           class="mt-1 block w-full rounded-lg border-slate-300 text-right text-sm focus:border-accent-500 focus:ring-accent-500">
                    <x-input-error class="mt-1" :messages="$serviceErrors->get('quantity')" />
                </div>
                <div>
                    <x-input-label for="service_rate" :value="__('Rate (optional)')" />
                    <input id="service_rate" name="rate" type="number" step="0.0001" min="0" x-model="rate" @input="calc()"
                           class="mt-1 block w-full rounded-lg border-slate-300 text-right text-sm focus:border-accent-500 focus:ring-accent-500">
                    <x-input-error class="mt-1" :messages="$serviceErrors->get('rate')" />
                </div>
                <div>
                    <x-input-label for="service_amount" :value="__('Amount')" />
                    <input id="service_amount" name="amount" type="number" step="0.01" min="0.01" x-model="amount" required
                           class="mt-1 block w-full rounded-lg border-slate-300 text-right text-sm font-semibold focus:border-accent-500 focus:ring-accent-500">
                    <x-input-error class="mt-1" :messages="$serviceErrors->get('amount')" />
                </div>
                <div>
                    <x-input-label for="reference_no" :value="__('Bill / Challan No. (optional)')" />
                    <x-text-input id="reference_no" name="reference_no" class="mt-1 block w-full" :value="old('reference_no')" />
                    <x-input-error class="mt-1" :messages="$serviceErrors->get('reference_no')" />
                </div>
                <div class="md:col-span-2 xl:col-span-4">
                    <x-input-label for="service_note" :value="__('Note (optional)')" />
                    <x-text-input id="service_note" name="note" class="mt-1 block w-full" :value="old('note')" />
                </div>
            </div>
            <div class="mt-4 flex items-center gap-3">
                <button type="submit" class="rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">{{ __('Save & Post to Supplier') }}</button>
                <button type="button" @click="adding = false" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100">{{ __('Cancel') }}</button>
            </div>
        </form>
        @endif

        <div class="overflow-x-auto">
        <table class="w-full min-w-[860px] text-sm">
            <thead>
                <tr class="whitespace-nowrap border-y border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('Date') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Work Done') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Supplier') }}</th>
                    @unless ($single)<th class="px-5 py-3 font-semibold">{{ __('For') }}</th>@endunless
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Qty × Rate') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Amount') }}</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($order->services->sortBy('service_date') as $service)
                <tr class="hover:bg-slate-50">
                    <td class="whitespace-nowrap px-5 py-3 text-slate-600">{{ $service->service_date->format('d M, Y') }}</td>
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $service->service }}</span>
                        @if ($service->reference_no || $service->note)
                        <span class="block text-xs text-slate-500">{{ collect([$service->reference_no ? __('Bill').' '.$service->reference_no : null, $service->note])->filter()->implode(' · ') }}</span>
                        @endif
                    </td>
                    <td class="px-5 py-3 text-slate-700">
                        @can('parties.view')
                        <a href="{{ route('parties.ledger', $service->party) }}" class="hover:text-accent-700">{{ $service->party->name }}</a>
                        @else
                        {{ $service->party->name }}
                        @endcan
                    </td>
                    @unless ($single)
                    <td class="px-5 py-3 text-slate-600">{{ $service->item ? $service->item->label() : __('Whole order') }}</td>
                    @endunless
                    <td class="whitespace-nowrap px-5 py-3 text-right text-slate-600">
                        {{ $service->quantity !== null && $service->rate !== null ? $qty($service->quantity).' × '.number_format($service->rate, 2) : '—' }}
                    </td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($service->amount, 2) }}</td>
                    <td class="px-5 py-3 text-right">
                        @if ($canEditServices)
                        <form method="POST" action="{{ route('production-orders.services.destroy', [$order, $service]) }}"
                              onsubmit="return confirm('{{ __('Remove this entry? It will be reversed from the supplier\'s account.') }}');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs font-semibold text-rose-600 hover:text-rose-800">{{ __('Remove') }}</button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $single ? 6 : 7 }}" class="px-5 py-6 text-center text-sm text-slate-400">{{ __('No third-party work recorded.') }}</td>
                </tr>
                @endforelse
            </tbody>
            @if ($order->services->isNotEmpty())
            <tfoot class="border-t border-slate-200 bg-slate-50">
                <tr>
                    <td colspan="{{ $single ? 4 : 5 }}" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total Third-Party Cost') }}</td>
                    <td class="px-5 py-2 text-right text-base font-bold text-brand-900">{{ number_format($order->service_cost, 2) }}</td>
                    <td></td>
                </tr>
            </tfoot>
            @endif
        </table>
        </div>
    </div>

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="px-6 pt-5 pb-3">
            <h3 class="font-bold text-brand-900">{{ __('Raw Materials Consumed') }}</h3>
            <p class="text-xs text-slate-400">
                @if ($order->status === 'cancelled')
                    {{ __('Returned to stock when the order was cancelled.') }}
                @else
                    {{ __('Taken out of :site stock when the order was issued.', ['site' => $order->site->name]) }}
                @endif
            </p>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-sm">
            <thead>
                <tr class="border-y border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('Material') }}</th>
                    @unless ($single)<th class="px-5 py-3 font-semibold">{{ __('For') }}</th>@endunless
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Per Unit') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Consumed') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Unit Cost') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Cost') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($order->materials as $material)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <span class="block font-semibold text-slate-800">{{ $material->material->name }}</span>
                        <span class="block text-xs text-slate-400">{{ $material->material->sku }}</span>
                    </td>
                    @unless ($single)<td class="px-5 py-3 text-slate-600">{{ $material->item?->label() ?? '—' }}</td>@endunless
                    <td class="px-5 py-3 text-right text-slate-600">{{ $qty($material->quantity_per_unit) }}</td>
                    <td class="px-5 py-3 text-right text-slate-700">{{ $qty($material->quantity) }} {{ $material->material->stockUnit->short_name ?? '' }}</td>
                    <td class="px-5 py-3 text-right text-slate-600">{{ number_format($material->unit_cost, 2) }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format((float) $material->quantity * (float) $material->unit_cost, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t border-slate-200 bg-slate-50">
                <tr>
                    <td colspan="{{ $single ? 4 : 5 }}" class="px-5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total Material Cost') }}</td>
                    <td class="px-5 py-2 text-right text-base font-bold text-brand-900">{{ number_format($order->material_cost, 2) }}</td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</x-app-layout>
