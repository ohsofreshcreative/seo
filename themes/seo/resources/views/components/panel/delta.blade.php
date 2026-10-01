{{-- Zmiana względem poprzedniego okresu: dodatnia = poprawa (dla pozycji: poprzednia − obecna), z symbolem kierunku. --}}
@props(['value', 'format' => 'number', 'decimals' => 0])
@php
  $formatted = match ($format) {
    'points' => \App\Panel\Format::points($value, $decimals),
    'percent' => $value === null ? '—' : \App\Panel\Format::signed($value, $decimals) . "\u{00A0}%",
    'position' => \App\Panel\Format::signed($value, 1),
    default => \App\Panel\Format::signed($value, $decimals),
  };
  // Kierunek z wartości po zaokrągleniu do wyświetlanej precyzji (bez strzałki przy „+0,0”).
  $scaled = $value === null ? 0.0 : (float) $value * ($format === 'points' ? 100 : 1);
  $direction = round($scaled, $format === 'position' ? 1 : $decimals) <=> 0;
@endphp
<span {{ $attributes->class([
  'inline-flex items-center gap-0.5 whitespace-nowrap tabular-nums',
  'text-emerald-700' => $direction > 0,
  'text-red-700' => $direction < 0,
  'text-slate-500' => $direction === 0,
]) }}>
  @if ($direction > 0)<span aria-hidden="true">↑</span><span class="sr-only">wzrost</span>@elseif ($direction < 0)<span aria-hidden="true">↓</span><span class="sr-only">spadek</span>@endif
  {{ $formatted }}
</span>
