{{-- Podstawa twierdzenia w raporcie AI: fakt (dane pomiarowe), wniosek (z dowodów), hipoteza (do sprawdzenia) + pewność. --}}
@props(['basis' => null, 'confidence' => null])
@php
  $tone = [
    'fact' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'inference' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
    'evidence' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
    'hypothesis' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
  ][$basis] ?? 'bg-slate-100 text-slate-600 ring-slate-500/20';
@endphp
@if ($basis !== null)
  <span {{ $attributes->class(['inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset', $tone]) }} title="{{ \OsfSeo\Ai\Workspace\ReportLabels::BASIS_HINT[$basis] ?? '' }}">{{ \OsfSeo\Ai\Workspace\ReportLabels::basis($basis) }}</span>
@endif
@if ($confidence !== null)
  <span class="text-xs text-slate-500">{{ $confidence }}</span>
@endif
