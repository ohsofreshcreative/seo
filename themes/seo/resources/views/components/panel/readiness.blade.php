{{-- Gotowość danych do analizy AI: gotowe / częściowo gotowe / brakuje danych / niedostępne. --}}
@props(['state'])
@php
  $tone = [
    'ready' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'partial' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
    'insufficient' => 'bg-red-50 text-red-700 ring-red-600/20',
  ][$state] ?? 'bg-slate-100 text-slate-600 ring-slate-500/20';
@endphp
<span {{ $attributes->class(['inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $tone]) }}>{{ \OsfSeo\Ai\Workspace\ReportLabels::readiness($state) }}</span>
