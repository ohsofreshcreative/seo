{{-- Stan strony docelowej (D57). „Brak znanej strony docelowej” nie oznacza, że strona nie istnieje. --}}
@props(['state', 'manual' => false])
@php
  $value = $state instanceof \OsfSeo\Strategy\Target\TargetState ? $state : \OsfSeo\Strategy\Target\TargetState::tryFrom((string) $state);
  $colors = [
    'confirmed' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'probable' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
    'conflict' => 'bg-rose-50 text-rose-700 ring-rose-600/20',
    'none' => 'bg-violet-50 text-violet-700 ring-violet-600/20',
    'unknown' => 'bg-slate-100 text-slate-600 ring-slate-500/20',
  ][$value?->value ?? ''] ?? 'bg-slate-100 text-slate-600 ring-slate-500/20';
  $titles = [
    'none' => 'W dostępnych danych (GSC, SERP, Labs) nie ma odpowiedniej strony — to nie dowód, że strona nie istnieje',
    'unknown' => 'Dane nie wskazują jednoznacznie strony projektu',
    'conflict' => 'Kilka stron projektu z mocnymi wskazaniami',
  ];
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1 whitespace-nowrap rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset', $colors]) }} @isset($titles[$value?->value ?? '']) title="{{ $titles[$value->value] }}" @endisset>{{ $value?->label() ?? '—' }}@if ($manual)<span class="font-normal opacity-80">(ręcznie)</span>@endif</span>
