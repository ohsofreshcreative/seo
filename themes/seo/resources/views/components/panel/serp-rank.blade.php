{{-- Pozycja SERP z ostatniego pomiaru (rank_group wyniku organicznego) — nie średnia pozycja GSC. --}}
@props(['rank' => null, 'found' => null, 'depth' => null, 'featured' => false])
@php($depth = $depth ?? \OsfSeo\Serp\SerpConfig::DEFAULT_DEPTH)
@if ($found === null)
  <span {{ $attributes->class(['text-slate-400']) }} title="Jeszcze nie sprawdzono">—</span>
@elseif ($rank === null)
  <span {{ $attributes->class(['whitespace-nowrap text-xs text-slate-500']) }} title="Domeny projektu nie ma w sprawdzonych wynikach (TOP{{ $depth }})">Poza TOP{{ $depth }}</span>
@else
  <span {{ $attributes->class([
    'inline-flex items-center gap-1 whitespace-nowrap font-semibold tabular-nums',
    'text-emerald-700' => $rank <= 3,
    'text-slate-900' => $rank > 3 && $rank <= 10,
    'text-slate-700' => $rank > 10,
  ]) }}>#{{ $rank }}@if ($featured)<span class="rounded bg-sky-50 px-1 text-xs font-medium text-sky-700" title="Projekt ma też wyróżniony fragment (pokazywany osobno, nie jako #1)">wyróżniony fragment</span>@endif</span>
@endif
