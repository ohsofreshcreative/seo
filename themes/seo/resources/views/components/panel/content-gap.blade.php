{{-- Luka treści grupy fraz — heurystyka do sprawdzenia, nie diagnoza. --}}
@props(['value'])
@php
  $value = $value instanceof \OsfSeo\Gap\ContentGap ? $value : \OsfSeo\Gap\ContentGap::tryFrom((string) $value);
  $colors = [
    'new_page' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'improve' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
    'unclear' => 'bg-white text-slate-500 ring-slate-300',
    'covered' => 'bg-slate-100 text-slate-600 ring-slate-500/20',
  ][$value?->value ?? ''] ?? 'bg-white text-slate-500 ring-slate-300';
@endphp
<span {{ $attributes->class(['inline-flex items-center whitespace-nowrap rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset', $colors]) }}>{{ $value?->label() ?? '—' }}</span>
