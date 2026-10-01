{{-- Postęp przebiegu wyszukiwania (odświeżany co 5 s, dopóki przebieg trwa — wykonuje go tło, nie przeglądarka). --}}
@php
  $statusUrl = \App\Http\Controllers\Panel\DiscoveryController::runUrl($project->publicId, $progress['id']) . '/status';
@endphp
<div x-data="discoveryProgress(@js($statusUrl), @js($progress))"
  class="rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900" role="status">
  <div class="flex flex-wrap items-center gap-x-5 gap-y-1">
    <span class="font-semibold" x-text="status_label">{{ $progress['status_label'] }}</span>
    <span>Seedy: <span class="tabular-nums" x-text="seeds_done">{{ $progress['seeds_done'] }}</span> z <span class="tabular-nums" x-text="seeds_total">{{ $progress['seeds_total'] }}</span></span>
    <span>Nowe frazy: <span class="tabular-nums" x-text="candidates_new">{{ $progress['candidates_new'] }}</span></span>
    @if (array_key_exists('cost', $progress))
      <span>Koszt: <span class="tabular-nums" x-text="Number(cost).toFixed(4).replace('.', ',') + ' USD'">{{ \App\Panel\Format::usd($progress['cost'], 4) }}</span>
        <span class="text-brand-700">(maks. {{ \App\Panel\Format::usd($progress['estimated_cost'], 4) }})</span></span>
    @endif
  </div>
  <p class="mt-1 text-xs text-brand-800" x-show="blocked_by" x-cloak>
    Wstrzymane: <span x-text="blocked_by === 'paused' ? 'błąd konta DataForSEO (logowanie lub środki)' : (blocked_by === 'daily_limit' ? 'osiągnięto dzienny limit kosztów' : 'osiągnięto miesięczny limit kosztów')"></span> — przebieg wznowi się w ramach limitów.
  </p>
  <p class="mt-1 text-xs text-brand-800" x-show="active" @if (! $progress['active']) x-cloak @endif>Wyszukiwanie działa w tle — możesz opuścić tę stronę.</p>
</div>
