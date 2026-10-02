{{-- Postęp pomiaru pozycji (odświeżany co 5 s, dopóki trwa — zlecenia wysyła i wyniki odbiera tło, nie przeglądarka). --}}
@php
  $statusUrl = \App\Http\Controllers\Panel\PositionsController::runUrl($project->publicId, $progress['id']) . '/status';
@endphp
<div x-data="runProgress(@js($statusUrl), @js($progress))"
  class="rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900" role="status">
  <div class="flex flex-wrap items-center gap-x-5 gap-y-1">
    <span class="font-semibold">Pomiar pozycji: <span x-text="status_label">{{ $progress['status_label'] }}</span></span>
    <span>Frazy: <span class="tabular-nums" x-text="keywords_planned">{{ $progress['keywords_planned'] }}</span></span>
    <span>Odebrane wyniki: <span class="tabular-nums" x-text="tasks_completed">{{ $progress['tasks_completed'] }}</span></span>
    <span x-show="tasks_failed" @if ($progress['tasks_failed'] === 0) x-cloak @endif>Błędy: <span class="tabular-nums" x-text="tasks_failed">{{ $progress['tasks_failed'] }}</span></span>
    @if (array_key_exists('cost', $progress))
      <span>Koszt: <span class="tabular-nums" x-text="Number(cost).toFixed(4).replace('.', ',') + ' USD'">{{ \App\Panel\Format::usd($progress['cost'], 4) }}</span>
        <span class="text-brand-700">(maks. {{ \App\Panel\Format::usd($progress['estimated_cost'], 4) }})</span></span>
    @endif
  </div>
  <p class="mt-1 text-xs text-brand-800" x-show="active" @if (! $progress['active']) x-cloak @endif>
    Zlecenia (kolejka Standard) wysyła i wyniki odbiera przetwarzanie w tle — zwykle kilka do kilkudziesięciu minut. Możesz opuścić tę stronę.
  </p>
</div>
