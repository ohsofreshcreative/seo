{{-- Stan synchronizacji GSC projektu. Postęp odświeżany z /search-console/status, gdy synchronizacja trwa. --}}
@php
  $badge = [
    'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'queued' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
    'running' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
    'retrying' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
    'partial' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
    'failed' => 'bg-red-50 text-red-700 ring-red-600/20',
    'needs_reauth' => 'bg-red-50 text-red-700 ring-red-600/20',
  ];
  $datasetStatus = ['success' => 'aktualne', 'queued' => 'w kolejce', 'running' => 'w toku', 'retrying' => 'ponawianie', 'failed' => 'błąd', 'never' => 'brak danych'];
@endphp
@php
  $initial = [
    'active' => $sync->isActive(),
    'label' => $sync->label(),
    'lastSuccess' => \App\Panel\Format::datetime($sync->lastSuccessAt),
    'progress' => $sync->backfillProgress,
    'pending' => $sync->pendingJobs,
  ];
@endphp
<x-panel.card>
<div x-data="syncStatus(@js(\App\Panel\PanelUrl::project($project->publicId, 'search-console/status')), @js($initial))">
  <div class="flex flex-wrap items-start justify-between gap-4">
    <div>
      <h2 class="text-base font-semibold text-slate-900">Synchronizacja danych</h2>
      <p class="mt-1 text-sm text-slate-600">
        Dane pobierane w tle: najpierw najnowsze, potem historia (ok. 16 miesięcy). Codziennie odświeżane jest ostatnie 7 dni,
        bo Google dopracowuje dane po ich pierwszym pojawieniu.
      </p>
    </div>
    <span @class([
      'inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset',
      $badge[$sync->overall] ?? 'bg-slate-100 text-slate-600 ring-slate-500/20',
    ]) x-text="label">{{ $sync->label() }}</span>
  </div>

  <dl class="mt-4 grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
    <div>
      <dt class="text-slate-500">Dane GSC do</dt>
      <dd class="mt-1 font-medium text-slate-900">{{ \App\Panel\Format::date($sync->latestDataDate) }} <span class="text-xs font-normal text-slate-500">(data GSC, czas pacyficzny)</span></dd>
    </div>
    <div>
      <dt class="text-slate-500">Ostatnia udana synchronizacja</dt>
      <dd class="mt-1 font-medium text-slate-900" x-text="lastSuccess">{{ \App\Panel\Format::datetime($sync->lastSuccessAt) }}</dd>
    </div>
    <div>
      <dt class="text-slate-500">Ostatnia próba</dt>
      <dd class="mt-1 font-medium text-slate-900">{{ \App\Panel\Format::datetime($sync->lastAttemptAt) }}</dd>
    </div>
  </dl>

  <div class="mt-5">
    <div class="flex items-center justify-between text-sm">
      <span class="text-slate-600">Historia (backfill)</span>
      <span class="font-medium text-slate-900"><span x-text="progress">{{ $sync->backfillProgress }}</span>%</span>
    </div>
    <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="progress" aria-valuenow="{{ $sync->backfillProgress }}">
      <div class="h-2 rounded-full bg-brand-600 transition-all" :style="'width: ' + progress + '%'" style="width: {{ $sync->backfillProgress }}%"></div>
    </div>
    <p class="mt-2 text-xs text-slate-500">Zadania w kolejce: <span x-text="pending">{{ $sync->pendingJobs }}</span></p>
  </div>

  <div class="mt-5 overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead>
        <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
          <th class="py-2 pr-4 font-medium">Dane</th>
          <th class="py-2 pr-4 font-medium">Stan</th>
          <th class="py-2 pr-4 font-medium">Zakres (daty GSC)</th>
          <th class="py-2 pr-4 font-medium">Pokrycie</th>
          <th class="py-2 font-medium">Ostatni błąd</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        @foreach ($sync->datasets as $dataset)
          <tr>
            <td class="py-2 pr-4 text-slate-900">{{ $dataset['label'] }}</td>
            <td class="py-2 pr-4 text-slate-700">{{ $datasetStatus[$dataset['status']] ?? $dataset['status'] }}</td>
            <td class="whitespace-nowrap py-2 pr-4 text-slate-700">{{ \App\Panel\Format::date($dataset['oldest_date']) }} – {{ \App\Panel\Format::date($dataset['newest_date']) }}</td>
            <td class="py-2 pr-4 text-slate-700">{{ $dataset['progress'] }}%</td>
            <td class="py-2 text-slate-700">{{ $dataset['last_error'] ?? '—' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  @if ($sync->overall === 'needs_reauth')
    <div class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
      Synchronizacja zatrzymana: Google wymaga ponownej autoryzacji konta. Po ponownym połączeniu wznowi się automatycznie.
    </div>
  @endif

  @if ($canManage)
    <form method="post" action="{{ \App\Panel\PanelUrl::project($project->publicId, 'search-console/sync') }}" class="mt-6">
      <x-panel.nonce />
      <x-panel.button type="submit" variant="secondary">Synchronizuj teraz</x-panel.button>
      <p class="mt-2 text-xs text-slate-500">Odświeża najnowsze dane. Kolejne zlecenie możliwe po 5 minutach; trwająca synchronizacja nie jest dublowana.</p>
    </form>
  @endif

  @if ($sync->recentRuns !== [])
    <details class="mt-6">
      <summary class="cursor-pointer text-sm font-medium text-slate-700">Ostatnie zadania</summary>
      <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-xs">
          <thead>
            <tr class="text-left uppercase tracking-wide text-slate-500">
              <th class="py-2 pr-3 font-medium">Dane</th>
              <th class="py-2 pr-3 font-medium">Źródło</th>
              <th class="py-2 pr-3 font-medium">Okno</th>
              <th class="py-2 pr-3 font-medium">Stan</th>
              <th class="py-2 pr-3 font-medium">Wiersze</th>
              <th class="py-2 pr-3 font-medium">Próba</th>
              <th class="py-2 pr-3 font-medium">Błąd</th>
              <th class="py-2 font-medium">Zlecone</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 text-slate-700">
            @foreach ($sync->recentRuns as $run)
              <tr>
                <td class="py-1.5 pr-3">{{ $run->dataset->label() }}</td>
                <td class="py-1.5 pr-3">{{ $run->trigger->label() }}</td>
                <td class="whitespace-nowrap py-1.5 pr-3">{{ \App\Panel\Format::date($run->range->start) }} – {{ \App\Panel\Format::date($run->range->end) }}</td>
                <td class="py-1.5 pr-3">{{ $run->status->label() }}</td>
                <td class="py-1.5 pr-3">{{ \App\Panel\Format::number($run->rowsWritten) }}</td>
                <td class="py-1.5 pr-3">{{ $run->attempt }}</td>
                <td class="py-1.5 pr-3">{{ $run->errorCode ?? '—' }}</td>
                <td class="whitespace-nowrap py-1.5">{{ \App\Panel\Format::datetime($run->queuedAt) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </details>
  @endif
</div>
</x-panel.card>
