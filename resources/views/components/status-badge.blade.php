@props([
    'label',
    'color' => 'slate',
    'size' => 'sm',
])

@php
    // Full class strings (not interpolated) so Tailwind's JIT sees them.
    $palette = match ($color) {
        'amber' => 'bg-amber-50 text-amber-600 ring-amber-200',
        'emerald' => 'bg-emerald-50 text-emerald-600 ring-emerald-200',
        'rose' => 'bg-rose-50 text-rose-600 ring-rose-200',
        'sky' => 'bg-sky-50 text-sky-600 ring-sky-200',
        'indigo' => 'bg-indigo-50 text-indigo-600 ring-indigo-200',
        'violet' => 'bg-violet-50 text-violet-600 ring-violet-200',
        'teal' => 'bg-teal-50 text-teal-600 ring-teal-200',
        default => 'bg-slate-100 text-slate-500 ring-slate-200',
    };
    $padding = $size === 'lg' ? 'px-3 py-1' : 'px-2.5 py-0.5';
@endphp

<span {{ $attributes->merge(['class' => "inline-flex whitespace-nowrap rounded-full {$padding} text-xs font-semibold ring-1 {$palette}"]) }}>{{ __($label) }}</span>
