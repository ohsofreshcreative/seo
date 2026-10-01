{{-- Pewność sygnału (osobno od priorytetu): Niska / Średnia / Wysoka. --}}
@props(['level'])
@php
  $level = $level instanceof \OsfSeo\Opportunities\Confidence ? $level : (\OsfSeo\Opportunities\Confidence::tryFromValue($level) ?? \OsfSeo\Opportunities\Confidence::Low);
  $colors = [
    'High' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'Medium' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
    'Low' => 'bg-slate-100 text-slate-600 ring-slate-500/20',
  ][$level->name];
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1 whitespace-nowrap rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset', $colors]) }}>
  <span aria-hidden="true">{{ str_repeat('●', $level->value) . str_repeat('○', 3 - $level->value) }}</span>
  Pewność: {{ mb_strtolower($level->label()) }}
</span>
