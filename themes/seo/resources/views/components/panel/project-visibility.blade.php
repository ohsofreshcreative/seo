{{-- Widoczność projektu dla luki: wartość i źródło (pomiar SERP → średnia pozycja GSC → punkt odniesienia Labs). --}}
@props(['value', 'source' => null, 'position' => null, 'sporadic' => false])
@php
  $value = $value instanceof \OsfSeo\Gap\ProjectVisibility ? $value : (\OsfSeo\Gap\ProjectVisibility::tryFrom((string) $value) ?? \OsfSeo\Gap\ProjectVisibility::Unknown);
  $colors = [
    'none' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'low' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
    'visible' => 'bg-slate-100 text-slate-600 ring-slate-500/20',
    'unknown' => 'bg-white text-slate-500 ring-slate-300',
  ][$value->value];
  $sources = [
    'serp' => ['SERP', 'Nasz pomiar pozycji SERP (DataForSEO)'],
    'gsc' => ['śr. GSC', 'Średnia pozycja z Google Search Console (nie dokładna pozycja w Google)'],
    'labs' => ['Labs', 'Punkt odniesienia: baza DataForSEO Labs (migawka, nie nasz pomiar)'],
  ];
  [$sourceLabel, $sourceTitle] = $sources[(string) $source] ?? [null, null];
  $position = $position === null || $position === '' ? null : (float) $position;
@endphp
<span {{ $attributes->class(['inline-flex flex-wrap items-center gap-1']) }}>
  <span class="inline-flex items-center whitespace-nowrap rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $colors }}">{{ $value->label() }}{{ $sporadic ? ' (sporadycznie)' : '' }}</span>
  @if ($sourceLabel !== null)
    <span class="whitespace-nowrap text-xs text-slate-500" title="{{ $sourceTitle }}">{{ $sourceLabel }}@if ($position !== null) {{ $source === 'gsc' ? \App\Panel\Format::position($position) : '#' . \App\Panel\Format::number($position) }}@endif</span>
  @endif
</span>
