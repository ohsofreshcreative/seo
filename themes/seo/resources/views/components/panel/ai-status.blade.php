{{-- Status analizy AI (bez kodów technicznych): gotowa, w kolejce / w trakcie, nieudana, niepewna, odrzucona. --}}
@props(['status', 'decision' => null])
@php
  $tone = match (true) {
    $status === 'succeeded' && $decision === 'rejected' => 'bg-slate-100 text-slate-600 ring-slate-500/20',
    $status === 'succeeded' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    in_array($status, ['queued', 'reserved', 'running'], true) => 'bg-sky-50 text-sky-700 ring-sky-600/20',
    $status === 'uncertain' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
    default => 'bg-red-50 text-red-700 ring-red-600/20',
  };
@endphp
<span {{ $attributes->class(['inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $tone]) }}>{{ \OsfSeo\Ai\Workspace\ReportLabels::status((string) $status, $decision) }}</span>
