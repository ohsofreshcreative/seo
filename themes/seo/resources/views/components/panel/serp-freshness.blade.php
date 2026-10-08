{{-- Świeżość pomiaru SERP (D55): ≤ 30 dni aktualny, 31–90 nieaktualny (bez Pozycji SERP projektu), > 90 wygasły. --}}
@props(['freshness' => null, 'checkedAt' => null])
@php
  $colors = [
    'fresh' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'stale' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
    'expired' => 'bg-slate-100 text-slate-500 ring-slate-500/20',
  ][$freshness ?? ''] ?? 'bg-white text-slate-500 ring-slate-300';
  $label = [
    'fresh' => 'aktualny',
    'stale' => 'nieaktualny',
    'expired' => 'wygasły',
  ][$freshness ?? ''] ?? 'brak pomiaru';
@endphp
<span {{ $attributes->class(['inline-flex items-center whitespace-nowrap rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset', $colors]) }} title="{{ \OsfSeo\Strategy\Serp\SerpFreshness::label($freshness) }}@if ($checkedAt) · pomiar {{ \App\Panel\Format::datetime($checkedAt) }}@endif">{{ $label }}</span>
