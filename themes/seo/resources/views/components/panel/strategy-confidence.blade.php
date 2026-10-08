{{-- Pewność decyzji tematu Strategii (osobno od priorytetu): Niska / Średnia / Wysoka. Brak — „—”. --}}
@props(['level' => null, 'points' => null, 'compact' => false])
@php
  $dots = ['low' => 1, 'medium' => 2, 'high' => 3][$level ?? ''] ?? null;
  $colors = [
    'high' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'medium' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
    'low' => 'bg-slate-100 text-slate-600 ring-slate-500/20',
  ][$level ?? ''] ?? 'bg-white text-slate-500 ring-slate-300';
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1 whitespace-nowrap rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset', $colors]) }} @if ($points !== null) title="{{ $points }}/100 pkt pewności" @endif>
  @if ($dots !== null)<span aria-hidden="true">{{ str_repeat('●', $dots) . str_repeat('○', 3 - $dots) }}</span>@endif
  {{ $compact ? '' : 'Pewność:' }} {{ \App\Panel\StrategyLabels::level($level) }}
</span>
