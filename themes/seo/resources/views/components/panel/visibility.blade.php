{{-- Obecna widoczność frazy w GSC (średnia pozycja GSC — nie dokładny ranking SERP). --}}
@props(['value'])
@php
  $value = $value instanceof \OsfSeo\Discovery\Visibility ? $value : \OsfSeo\Discovery\Visibility::tryFrom((string) $value);
  $colors = [
    'none' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'low' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
    'visible' => 'bg-slate-100 text-slate-600 ring-slate-500/20',
    'unknown' => 'bg-white text-slate-500 ring-slate-300',
  ][$value?->value ?? ''] ?? 'bg-slate-100 text-slate-600 ring-slate-500/20';
@endphp
<span {{ $attributes->class(['inline-flex items-center whitespace-nowrap rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset', $colors]) }}>{{ $value?->label() ?? '—' }}</span>
