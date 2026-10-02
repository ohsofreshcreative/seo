{{-- Postęp importu Luk SEO (odświeżany co 5 s, dopóki trwa — strony wyników pobiera tło, nie przeglądarka). --}}
@php
  $statusUrl = \App\Http\Controllers\Panel\GapsController::runUrl($project->publicId, $progress['id']) . '/status';
@endphp
<div x-data="runProgress(@js($statusUrl), @js($progress))"
  class="rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900" role="status">
  <div class="flex flex-wrap items-center gap-x-5 gap-y-1">
    <span class="font-semibold">Import fraz konkurentów: <span x-text="status_label">{{ $progress['status_label'] }}</span></span>
    <span>Domeny: <span class="tabular-nums" x-text="targets_done">{{ $progress['targets_done'] }}</span> z <span class="tabular-nums" x-text="targets_planned">{{ $progress['targets_planned'] }}</span></span>
    <span>Strony wyników: <span class="tabular-nums" x-text="requests_done">{{ $progress['requests_done'] }}</span> (maks. <span class="tabular-nums" x-text="requests_planned">{{ $progress['requests_planned'] }}</span>)</span>
    <span>Frazy: <span class="tabular-nums" x-text="Number(rows_received).toLocaleString('pl-PL')">{{ \App\Panel\Format::number($progress['rows_received']) }}</span></span>
    @if (array_key_exists('cost', $progress))
      <span>Koszt: <span class="tabular-nums" x-text="Number(cost).toFixed(4).replace('.', ',') + ' USD'">{{ \App\Panel\Format::usd($progress['cost'], 4) }}</span>
        <span class="text-brand-700">(maks. {{ \App\Panel\Format::usd($progress['estimated_cost'], 4) }})</span></span>
    @endif
  </div>
  <p class="mt-1 text-xs text-brand-800" x-show="status === 'paused'" @if ($progress['status'] !== 'paused') x-cloak @endif>
    Wstrzymany przez wspólny limit kosztów DataForSEO albo pauzę konta — pobrane strony zostają, import wznowi się sam, gdy limit na to pozwoli.
  </p>
  <p class="mt-1 text-xs text-brand-800" x-show="active" @if (! $progress['active']) x-cloak @endif>Import działa w tle — możesz opuścić tę stronę.</p>
</div>
