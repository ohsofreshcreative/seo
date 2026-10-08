{{-- Działanie tematu Strategii (D58). „Kandydat na nową stronę” — nigdy „Utwórz brakującą stronę”. --}}
@props(['action'])
@php
  $value = $action instanceof \OsfSeo\Strategy\Decision\StrategyAction ? $action : \OsfSeo\Strategy\Decision\StrategyAction::tryFrom((string) $action);
  $colors = [
    'consolidate' => 'bg-rose-50 text-rose-700 ring-rose-600/20',
    'recover' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
    'optimize' => 'bg-brand-50 text-brand-700 ring-brand-500/30',
    'create' => 'bg-violet-50 text-violet-700 ring-violet-600/20',
    'monitor' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'investigate' => 'bg-slate-100 text-slate-700 ring-slate-500/20',
  ][$value?->value ?? ''] ?? 'bg-slate-100 text-slate-600 ring-slate-500/20';
@endphp
<span {{ $attributes->class(['inline-flex items-center whitespace-nowrap rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset', $colors]) }}>{{ $value?->label() ?? '—' }}</span>
