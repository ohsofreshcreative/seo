{{-- Zmiana Pozycji SERP względem poprzedniego porównywalnego pomiaru (ta sama lokalizacja, język, urządzenie i głębokość). --}}
@props(['type' => null, 'value' => null, 'top10' => null, 'depth' => null])
@php
  $label = \OsfSeo\Serp\RankChange::label($type, $value, (int) ($depth ?? \OsfSeo\Serp\SerpConfig::DEFAULT_DEPTH));
  $up = in_array($type, ['up', 'entered'], true);
  $down = in_array($type, ['down', 'left'], true);
@endphp
<span {{ $attributes->class([
  'inline-flex flex-col whitespace-nowrap',
  'text-emerald-700' => $up,
  'text-red-700' => $down,
  'text-slate-500' => ! $up && ! $down,
]) }} @if ($type === 'incomparable') title="Poprzedni pomiar miał inne ustawienia (urządzenie lub głębokość) — zmiany nie da się porównać" @endif>
  <span class="tabular-nums">@if ($up)<span aria-hidden="true">↑</span><span class="sr-only">wzrost</span>@elseif ($down)<span aria-hidden="true">↓</span><span class="sr-only">spadek</span>@endif {{ $label }}</span>
  @if ($top10 === 'entered')
    <span class="text-xs text-emerald-700">weszła do TOP10</span>
  @elseif ($top10 === 'left')
    <span class="text-xs text-red-700">wypadła z TOP10</span>
  @endif
</span>
