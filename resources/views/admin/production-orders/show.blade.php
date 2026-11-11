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
                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Material Cost') }}</span>
                <span class="block font-semibold text-slate-800">{{ number_format($order->material_cost, 2) }}</span>
                @if ($single && $order->totalQuantity() > 0)
                <span class="block text-xs text-slate-500">{{ __('per unit') }} {{ number_format((float) $order->material_cost / ($order->totalProduced() ?: $order->totalQuantity()), 2) }}</span>
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
                    <td class="px-5 py-3 text-right text-slate-600">{{ $base > 0 ? number_format((float) $item->material_cost / $base, 2) : '—' }}</td>
                </tr>
                @endforeach
            </tbody>
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
