{{-- Typ luki frazy: najlepszy konkurent (pozycja w bazie DataForSEO Labs) vs widoczność projektu. --}}
@props(['value'])
@php
  $value = $value instanceof \OsfSeo\Gap\GapType ? $value : \OsfSeo\Gap\GapType::tryFrom((string) $value);
  $colors = [
    'missing' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'weak' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
    'unknown' => 'bg-white text-slate-500 ring-slate-300',
    'competitive' => 'bg-slate-100 text-slate-600 ring-slate-500/20',
    'stronger' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
  ][$value?->value ?? ''] ?? 'bg-slate-100 text-slate-600 ring-slate-500/20';
@endphp
<span {{ $attributes->class(['inline-flex items-center whitespace-nowrap rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset', $colors]) }}>{{ $value?->label() ?? '—' }}</span>
