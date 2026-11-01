@props([
    'name',
    'options',
    'selected' => null,
    'placeholder' => 'Select…',
    'autoSubmit' => false,
    'extra' => [],
])

@php
    $items = collect($options)->map(function ($o) use ($extra) {
        $item = ['id' => (string) $o->id, 'name' => $o->name];

        foreach ($extra as $key) {
            $item[$key] = data_get($o, $key);
        }

        return $item;
    })->values();
    $selectedId = $selected !== null ? (string) $selected : '';
    $selectedItem = $items->firstWhere('id', $selectedId);
@endphp

<div x-data="{
        open: false,
        query: '',
        top: 0,
        left: 0,
        width: 0,
        selectedId: @js($selectedId),
        selectedLabel: @js($selectedItem['name'] ?? $placeholder),
        options: @js($items),
        get filtered() {
            if (! this.query) return this.options;
            const q = this.query.toLowerCase();
            return this.options.filter((o) => o.name.toLowerCase().includes(q));
        },
        toggle() {
            this.open = ! this.open;
            if (this.open) {
                const r = $refs.trigger.getBoundingClientRect();
                this.top = r.bottom + window.scrollY + 4;
                this.left = r.left + window.scrollX;
                this.width = r.width;
                this.$nextTick(() => $refs.search.focus());
            }
        },
        select(opt) {
            this.selectedId = opt ? opt.id : '';
            this.selectedLabel = opt ? opt.name : @js($placeholder);
            this.open = false;
            this.query = '';
            this.$dispatch('option-selected-{{ $name }}', opt);
            @if ($autoSubmit)
            this.$nextTick(() => this.$refs.hiddenInput.form.submit());
            @endif
        },
        add(opt) {
            this.options = [...this.options, opt].sort((a, b) => a.name.localeCompare(b.name));
            this.select(opt);
        },
    }" {{ $attributes->merge(['class' => 'relative']) }} @keydown.escape="open = false"
    @searchable-select-add.window="$event.detail.name === @js($name) && add($event.detail.option)">
    <input type="hidden" name="{{ $name }}" x-ref="hiddenInput" :value="selectedId">

    <button type="button" x-ref="trigger" @click="toggle()"
            class="flex w-full items-center justify-between gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-left text-sm focus:border-accent-500 focus:outline-none focus:ring-1 focus:ring-accent-500">
        <span x-text="selectedLabel" class="truncate" :class="! selectedId && 'text-slate-400'"></span>
        <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
    </button>

    <template x-teleport="body">
        <div x-show="open" @click.outside="open = false" x-transition
             :style="`top: ${top}px; left: ${left}px; width: ${width}px;`"
             class="fixed z-50 rounded-xl bg-white py-1.5 shadow-lg ring-1 ring-slate-200" style="display: none;">
            <div class="px-2 pb-1.5">
                <input x-ref="search" type="text" x-model="query" placeholder="{{ __('Search…') }}"
                       class="w-full rounded-lg border-slate-200 text-sm focus:border-accent-500 focus:ring-accent-500">
            </div>
            <ul class="max-h-56 overflow-y-auto">
                <li @click="select(null)" class="cursor-pointer px-4 py-1.5 text-sm text-slate-500 hover:bg-slate-50">{{ $placeholder }}</li>
                <template x-for="opt in filtered" :key="opt.id">
                    <li @click="select(opt)"
                        class="cursor-pointer px-4 py-1.5 text-sm hover:bg-slate-50"
                        :class="opt.id === selectedId ? 'text-accent-700 font-semibold' : 'text-slate-700'"
                        x-text="opt.name"></li>
                </template>
                <li x-show="filtered.length === 0" class="px-4 py-1.5 text-sm text-slate-400">{{ __('No matches') }}</li>
            </ul>
        </div>
    </template>
</div>
