{{-- Kafel wskaźnika: etykieta, wartość, zmiana względem poprzedniego okresu. --}}
@props(['label', 'value', 'previous' => null, 'hint' => null])
<div {{ $attributes->class(['rounded-lg border border-slate-200 bg-white p-5 shadow-sm']) }}>
  <p class="text-sm font-medium text-slate-500">{{ $label }}</p>
  <p class="mt-2 text-2xl font-semibold tabular-nums tracking-tight text-slate-900">{{ $value }}</p>
  <div class="mt-1 flex flex-wrap items-baseline gap-x-2 text-sm">
    {{ $slot }}
    @if ($previous !== null)
      <span class="text-xs text-slate-500">poprzednio {{ $previous }}</span>
    @endif
  </div>
  @if ($hint)
    <p class="mt-2 text-xs text-slate-500">{{ $hint }}</p>
  @endif
</div>
