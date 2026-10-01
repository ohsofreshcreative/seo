{{-- Priorytet szansy 0–100 (jak bardzo warto to sprawdzić — nie prognoza wzrostu). --}}
@props(['value', 'size' => 'md'])
@php
  $value = (int) $value;
  $colors = match (true) {
    $value >= 70 => 'bg-brand-700 text-white ring-brand-700',
    $value >= 50 => 'bg-brand-100 text-brand-800 ring-brand-200',
    $value >= 30 => 'bg-slate-100 text-slate-800 ring-slate-300',
    default => 'bg-white text-slate-600 ring-slate-300',
  };
@endphp
<span {{ $attributes->class([
  'inline-flex flex-col items-center justify-center rounded-lg font-semibold tabular-nums ring-1 ring-inset',
  'h-14 w-14 text-xl' => $size === 'md',
  'h-9 w-9 text-sm' => $size === 'sm',
  $colors,
]) }} title="Priorytet {{ $value }}/100">
  {{ $value }}
  <span class="sr-only">/100 — priorytet</span>
</span>
