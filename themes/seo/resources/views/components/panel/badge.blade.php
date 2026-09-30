@props(['status'])
@php
  $value = $status instanceof \OsfSeo\Projects\ProjectStatus ? $status : \OsfSeo\Projects\ProjectStatus::tryFrom((string) $status);
  $colors = [
    'active' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'paused' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
    'archived' => 'bg-slate-100 text-slate-600 ring-slate-500/20',
  ][$value?->value ?? ''] ?? 'bg-slate-100 text-slate-600 ring-slate-500/20';
@endphp
<span {{ $attributes->class(['inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset', $colors]) }}>{{ $value?->label() ?? $status }}</span>
