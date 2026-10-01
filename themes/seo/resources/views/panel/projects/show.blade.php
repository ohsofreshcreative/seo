@extends('panel.layouts.app', ['active' => 'overview'])

@section('title', $project->name)

@php
  use App\Panel\Format;
  use App\Panel\PanelUrl;
@endphp

@section('content')
  <x-panel.page-header :title="$project->name" :description="$project->domain">
    <x-slot:actions>
      <x-panel.badge :status="$project->status" />
      @if ($canManageProjects)
        <x-panel.button variant="secondary" :href="PanelUrl::project($project->publicId, 'edit')">Edytuj</x-panel.button>
      @endif
    </x-slot:actions>
  </x-panel.page-header>

  @if (! $project->connectionId)
    <x-panel.empty-state title="Brak danych z Google Search Console"
      description="Połącz projekt z Google Search Console, aby automatycznie pobrać frazy, kliknięcia, wyświetlenia, CTR i średnią pozycję (GSC).">
      <x-panel.button :href="PanelUrl::project($project->publicId, 'search-console')">Przejdź do Search Console</x-panel.button>
    </x-panel.empty-state>
  @elseif (! $project->gscProperty)
    <x-panel.empty-state title="Nie wybrano property Search Console"
      description="Projekt jest połączony z kontem Google. Wybierz property, z której mają być pobierane dane.">
      <x-panel.button :href="PanelUrl::project($project->publicId, 'search-console')">Wybierz property</x-panel.button>
    </x-panel.empty-state>
  @elseif (! $overview)
    <x-panel.empty-state title="Dane w przygotowaniu"
      :description="'Synchronizacja z Google Search Console: ' . mb_strtolower($sync->label()) . '. Najpierw pobierane są najnowsze dane, potem historia — dashboard pojawi się po pierwszym imporcie.'">
      <x-panel.button variant="secondary" :href="PanelUrl::project($project->publicId, 'search-console')">Stan synchronizacji</x-panel.button>
    </x-panel.empty-state>
  @else
    @php($period = $overview->period)
    <div class="mb-6 flex flex-col gap-3 text-sm text-slate-600 lg:flex-row lg:items-center lg:justify-between">
      <p>
        Ostatnie {{ $period->days }} dni dostępnych danych:
        <span class="font-medium text-slate-900">{{ Format::date($period->current->start) }} – {{ Format::date($period->current->end) }}</span>
        vs {{ Format::date($period->previous->start) }} – {{ Format::date($period->previous->end) }}
        <span class="text-xs text-slate-500">(daty GSC, czas pacyficzny)</span>
      </p>
      <div class="flex flex-wrap items-center gap-3">
        <a href="{{ PanelUrl::project($project->publicId, 'search-console') }}" class="text-xs text-slate-500 hover:text-slate-800">
          Synchronizacja: {{ $sync->label() }}@if ($sync->backfillProgress < 100) · historia {{ $sync->backfillProgress }}%@endif
        </a>
        <nav class="flex gap-1" aria-label="Długość okresu">
          @foreach (\OsfSeo\Analytics\Period::ALLOWED_DAYS as $option)
            <a href="{{ PanelUrl::project($project->publicId) . ($option !== 28 ? '?days=' . $option : '') }}" @class([
              'rounded-md px-3 py-1.5 font-medium',
              'bg-brand-700 text-white' => $period->days === $option,
              'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50' => $period->days !== $option,
            ]) @if ($period->days === $option) aria-current="true" @endif>{{ $option }} dni</a>
          @endforeach
        </nav>
      </div>
    </div>

    {{-- KPI: sumy witryny z GSC (z zapytaniami zanonimizowanymi), nie suma fraz. --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <x-panel.stat label="Kliknięcia" :value="Format::number($overview->current['clicks'])" :previous="Format::number($overview->previous['clicks'])">
        <x-panel.delta :value="\OsfSeo\Analytics\Metrics::percentChange($overview->previous['clicks'], $overview->current['clicks'])" format="percent" :decimals="1" />
      </x-panel.stat>
      <x-panel.stat label="Wyświetlenia" :value="Format::number($overview->current['impressions'])" :previous="Format::number($overview->previous['impressions'])">
        <x-panel.delta :value="\OsfSeo\Analytics\Metrics::percentChange($overview->previous['impressions'], $overview->current['impressions'])" format="percent" :decimals="1" />
      </x-panel.stat>
      <x-panel.stat label="CTR" :value="Format::percent($overview->ctr())" :previous="Format::percent($overview->previousCtr())">
        <x-panel.delta :value="$overview->ctr() !== null && $overview->previousCtr() !== null ? $overview->ctr() - $overview->previousCtr() : null" format="points" :decimals="2" />
      </x-panel.stat>
      <x-panel.stat label="Średnia pozycja (GSC)" :value="Format::position($overview->position())" :previous="Format::position($overview->previousPosition())"
        hint="Średnia z wyświetleń, nie dokładna pozycja w Google. ↑ = poprawa.">
        <x-panel.delta :value="$overview->positionChange()" format="position" />
      </x-panel.stat>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
      <x-panel.card class="xl:col-span-2">
        <div x-data="trafficChart">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-semibold text-slate-900">Ruch z Google dzień po dniu</h2>
            <div class="flex gap-1 text-sm" role="group" aria-label="Metryka wykresu">
              <button type="button" @click="show('clicks')" :class="metric === 'clicks' ? 'bg-brand-700 text-white' : 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300'"
                :aria-pressed="(metric === 'clicks').toString()" class="rounded-md px-3 py-1.5 font-medium">Kliknięcia</button>
              <button type="button" @click="show('impressions')" :class="metric === 'impressions' ? 'bg-brand-700 text-white' : 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300'"
                :aria-pressed="(metric === 'impressions').toString()" class="rounded-md px-3 py-1.5 font-medium">Wyświetlenia</button>
            </div>
          </div>
          <div class="mt-3 flex flex-wrap gap-4 text-xs text-slate-600" aria-hidden="true">
            <span class="inline-flex items-center gap-1.5"><span class="inline-block h-0.5 w-5 rounded bg-brand-600"></span>Bieżący okres</span>
            <span class="inline-flex items-center gap-1.5"><span class="inline-block w-5 border-t-2 border-dashed border-slate-400"></span>Poprzedni okres</span>
          </div>
          <div class="relative mt-3 h-64">
            <canvas x-ref="canvas" data-chart="{{ json_encode($overview->series) }}" role="img" aria-label="Wykres dziennych kliknięć lub wyświetleń: bieżący i poprzedni okres"></canvas>
          </div>
          <details class="mt-3 text-sm">
            <summary class="cursor-pointer text-slate-600">Dane wykresu (tabela)</summary>
            <div class="mt-2 max-h-64 overflow-y-auto">
              <table class="min-w-full text-xs">
                <thead><tr class="text-left text-slate-500"><th class="py-1 pr-3">Data</th><th class="py-1 pr-3 text-right">Kliknięcia</th><th class="py-1 pr-3 text-right">Wyświetlenia</th><th class="py-1 pr-3">Poprzedni okres</th><th class="py-1 pr-3 text-right">Kliknięcia</th><th class="py-1 text-right">Wyświetlenia</th></tr></thead>
                <tbody class="divide-y divide-slate-100 tabular-nums text-slate-700">
                  @foreach ($overview->series['dates'] as $i => $date)
                    <tr>
                      <td class="py-1 pr-3">{{ Format::date($date) }}</td>
                      <td class="py-1 pr-3 text-right">{{ Format::number($overview->series['clicks'][$i]) }}</td>
                      <td class="py-1 pr-3 text-right">{{ Format::number($overview->series['impressions'][$i]) }}</td>
                      <td class="py-1 pr-3">{{ Format::date($overview->series['previous_dates'][$i]) }}</td>
                      <td class="py-1 pr-3 text-right">{{ Format::number($overview->series['previous_clicks'][$i]) }}</td>
                      <td class="py-1 text-right">{{ Format::number($overview->series['previous_impressions'][$i]) }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </details>
        </div>
      </x-panel.card>

      <x-panel.card>
        <h2 class="text-base font-semibold text-slate-900">Widoczność fraz</h2>
        <p class="mt-1 text-xs text-slate-500">
          Liczba fraz, których <strong class="font-medium">średnia pozycja (GSC)</strong> w okresie mieści się w progu.
          Progi skumulowane (TOP 10 zawiera TOP 3). To nie jest dokładny ranking w wynikach Google.
        </p>
        <dl class="mt-4 divide-y divide-slate-100">
          @foreach (\OsfSeo\Analytics\Visibility::BUCKETS as $bucket)
            <div class="flex items-center justify-between py-2 text-sm">
              <dt class="text-slate-600">TOP {{ $bucket }} <span class="text-xs text-slate-400">(≤ {{ $bucket }})</span></dt>
              <dd class="flex items-baseline gap-3">
                <span class="font-semibold tabular-nums text-slate-900">{{ Format::number($overview->visibility->current[$bucket]) }}</span>
                <x-panel.delta :value="$overview->visibility->change($bucket)" class="w-14 justify-end text-xs" />
              </dd>
            </div>
          @endforeach
          <div class="flex items-center justify-between py-2 text-sm">
            <dt class="text-slate-600">Wszystkie frazy z wyświetleniami</dt>
            <dd class="flex items-baseline gap-3">
              <span class="font-semibold tabular-nums text-slate-900">{{ Format::number($overview->visibility->keywordsCurrent) }}</span>
              <x-panel.delta :value="$overview->visibility->keywordsCurrent - $overview->visibility->keywordsPrevious" class="w-14 justify-end text-xs" />
            </dd>
          </div>
        </dl>
      </x-panel.card>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
      @foreach ([['Największe wzrosty', $overview->gains, 'gains', 'Brak fraz z poprawą średniej pozycji w tym okresie.'], ['Największe spadki', $overview->losses, 'losses', 'Brak fraz ze spadkiem średniej pozycji w tym okresie.']] as [$title, $rows, $movement, $empty])
        <x-panel.card>
          <div class="flex items-center justify-between gap-3">
            <h2 class="text-base font-semibold text-slate-900">{{ $title }}</h2>
            <a href="{{ PanelUrl::project($project->publicId, 'keywords') . '?' . http_build_query(['days' => $period->days, 'movement' => $movement, 'sort' => 'position_change', 'dir' => $movement === 'gains' ? 'desc' : 'asc']) }}" class="text-sm text-brand-600 hover:underline">Wszystkie</a>
          </div>
          <p class="mt-1 text-xs text-slate-500">Średnia pozycja (GSC), frazy z min. {{ $overview->moversMinImpressions }} wyświetleniami w obu okresach.</p>
          @if ($rows === [])
            <p class="mt-4 text-sm text-slate-500">{{ $empty }}</p>
          @else
            <table class="mt-3 min-w-full text-sm">
              <thead><tr class="text-left text-xs uppercase tracking-wide text-slate-500"><th class="py-1.5 pr-3 font-medium">Fraza</th><th class="py-1.5 pr-3 text-right font-medium">Pozycja</th><th class="py-1.5 pr-3 text-right font-medium">Zmiana</th><th class="py-1.5 text-right font-medium">Wyświetlenia</th></tr></thead>
              <tbody class="divide-y divide-slate-100">
                @foreach ($rows as $row)
                  <tr>
                    <td class="max-w-[14rem] break-words py-1.5 pr-3 text-slate-900">{{ $row->keyword }}</td>
                    <td class="whitespace-nowrap py-1.5 pr-3 text-right tabular-nums text-slate-600">{{ Format::position($row->previousPosition()) }} → {{ Format::position($row->position()) }}</td>
                    <td class="py-1.5 pr-3 text-right"><x-panel.delta :value="$row->positionChange()" format="position" /></td>
                    <td class="py-1.5 text-right tabular-nums text-slate-600">{{ Format::number($row->impressions) }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          @endif
        </x-panel.card>
      @endforeach
    </div>

    <p class="mt-6 text-xs text-slate-500">
      @if ($overview->visibleQueryClicksShare() !== null)
        Frazy widoczne w GSC odpowiadają za {{ Format::percent($overview->visibleQueryClicksShare(), 0) }} kliknięć projektu w tym okresie.
      @endif
      Google nie udostępnia zapytań zanonimizowanych, dlatego suma kliknięć fraz bywa mniejsza niż suma projektu — kafelki powyżej pokazują pełne sumy z GSC.
      <a href="{{ PanelUrl::project($project->publicId, 'keywords') . ($period->days !== 28 ? '?days=' . $period->days : '') }}" class="text-brand-600 hover:underline">Wszystkie frazy →</a>
    </p>
  @endif

  <x-panel.card class="mt-8">
    <h2 class="text-base font-semibold text-slate-900">Informacje</h2>
    <dl class="mt-4 grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
      <div><dt class="text-slate-500">Domena</dt><dd class="mt-1 font-medium text-slate-900">{{ $project->domain }}</dd></div>
      <div><dt class="text-slate-500">Kraj / język</dt><dd class="mt-1 font-medium text-slate-900">{{ strtoupper($project->country) }} / {{ $project->language }}</dd></div>
      <div><dt class="text-slate-500">Property GSC</dt><dd class="mt-1 break-all font-medium text-slate-900">{{ $project->gscProperty ?? '—' }}</dd></div>
    </dl>
  </x-panel.card>
@endsection
