@extends('panel.layouts.app', ['active' => 'keywords'])

@section('title', 'Frazy · ' . $project->name)

@php
  use App\Panel\Format;
  use App\Panel\PanelUrl;

  $base = PanelUrl::project($project->publicId, 'keywords');
  $url = fn (array $changes = []) => $base . (($query = http_build_query($filters->with($changes)->toQuery())) !== '' ? '?' . $query : '');
  $sortUrl = function (string $sort) use ($filters, $url) {
    $direction = $filters->sort === $sort
      ? ($filters->direction === 'asc' ? 'desc' : 'asc')
      : (in_array($sort, ['position', 'keyword'], true) ? 'asc' : 'desc');

    return $url(['sort' => $sort, 'direction' => $direction, 'page' => 1]);
  };
  $sortMark = fn (string $sort) => $filters->sort === $sort ? ($filters->direction === 'asc' ? ' ▲' : ' ▼') : '';
  $columns = [
    ['keyword', 'Fraza', 'text-left'],
    ['clicks', 'Kliknięcia', 'text-right'],
    ['clicks_change', 'Δ', 'text-right'],
    ['impressions', 'Wyświetlenia', 'text-right'],
    ['impressions_change', 'Δ', 'text-right'],
    ['ctr', 'CTR', 'text-right'],
    [null, 'Δ CTR', 'text-right'],
    ['position', 'Średnia pozycja (GSC)', 'text-right'],
    ['position_change', 'Zmiana pozycji', 'text-right'],
  ];
  // Dane rynkowe (DataForSEO) — dodatkowe kolumny, gdy rynek projektu jest obsługiwany. Brak danych = „—”, nie 0.
  $market = $page->market;
  if ($market !== null) {
    $columns[] = ['volume', 'Wolumen', 'text-right'];
    $columns[] = ['difficulty', 'Trudność SEO', 'text-right'];
  }
  $columns[] = [null, 'Strona docelowa', 'text-left'];
  $unknown = fn ($metrics, bool $fetched) => $metrics === null ? 'Brak danych rynkowych' : ($fetched ? 'Brak danych u dostawcy' : 'Jeszcze nie pobrano');
  $difficultyClass = fn (?int $kd) => match (true) {
    $kd === null => 'text-slate-400',
    $kd < 30 => 'text-emerald-700',
    $kd < 70 => 'text-amber-700',
    default => 'text-red-700',
  };
@endphp

@section('content')
  <x-panel.page-header title="Frazy" :description="$project->name . ' · dane z Google Search Console'" />

  @if (! $page->hasData())
    <x-panel.empty-state title="Brak danych fraz"
      description="Frazy pojawią się po pierwszej synchronizacji z Google Search Console (najpierw najnowsze dni, potem historia).">
      <x-panel.button :href="PanelUrl::project($project->publicId, 'search-console')">Przejdź do Search Console</x-panel.button>
    </x-panel.empty-state>
  @else
    @php($period = $page->period)
    <div class="mb-4 flex flex-col gap-3 text-sm text-slate-600 lg:flex-row lg:items-center lg:justify-between">
      <p>
        Okres: <span class="font-medium text-slate-900">{{ Format::date($period->current->start) }} – {{ Format::date($period->current->end) }}</span>
        ({{ $period->days }} dni do ostatniej dostępnej daty GSC), porównanie z {{ Format::date($period->previous->start) }} – {{ Format::date($period->previous->end) }}.
      </p>
      <nav class="flex gap-1" aria-label="Długość okresu">
        @foreach (\OsfSeo\Analytics\Period::ALLOWED_DAYS as $option)
          <a href="{{ $url(['days' => $option, 'page' => 1]) }}" @class([
            'rounded-md px-3 py-1.5 font-medium',
            'bg-brand-700 text-white' => $period->days === $option,
            'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50' => $period->days !== $option,
          ]) @if ($period->days === $option) aria-current="true" @endif>{{ $option }} dni</a>
        @endforeach
      </nav>
    </div>

    <form method="get" action="{{ $base }}" class="mb-6 grid grid-cols-2 gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-6">
      <input type="hidden" name="days" value="{{ $period->days }}">
      <input type="hidden" name="sort" value="{{ $filters->sort }}">
      <input type="hidden" name="dir" value="{{ $filters->direction }}">
      <label class="col-span-2 block text-sm">
        <span class="text-slate-600">Fraza zawiera</span>
        <input type="search" name="q" value="{{ $filters->search }}" maxlength="100" class="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
      </label>
      <label class="block text-sm">
        <span class="text-slate-600">Pozycja od</span>
        <input type="number" name="pos_min" value="{{ $filters->positionMin }}" min="1" max="1000" step="0.1" class="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
      </label>
      <label class="block text-sm">
        <span class="text-slate-600">Pozycja do</span>
        <input type="number" name="pos_max" value="{{ $filters->positionMax }}" min="1" max="1000" step="0.1" class="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
      </label>
      <label class="block text-sm">
        <span class="text-slate-600">Min. wyświetleń</span>
        <input type="number" name="min_impr" value="{{ $filters->minImpressions }}" min="1" class="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
      </label>
      @if ($market !== null)
        <label class="block text-sm">
          <span class="text-slate-600">Min. wolumen</span>
          <input type="number" name="min_volume" value="{{ $filters->minVolume }}" min="0" class="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
        </label>
        <label class="block text-sm">
          <span class="text-slate-600">Maks. trudność SEO</span>
          <input type="number" name="max_kd" value="{{ $filters->maxDifficulty }}" min="0" max="100" class="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
        </label>
      @endif
      <label class="block text-sm">
        <span class="text-slate-600">Zmiana pozycji</span>
        <select name="movement" class="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
          <option value="">Wszystkie</option>
          <option value="gains" @selected($filters->movement === 'gains')>Wzrosty</option>
          <option value="losses" @selected($filters->movement === 'losses')>Spadki</option>
        </select>
      </label>
      <div class="col-span-2 flex items-end gap-2 md:col-span-6">
        <x-panel.button type="submit">Filtruj</x-panel.button>
        <x-panel.button variant="secondary" :href="$base . ($period->days !== 28 ? '?days=' . $period->days : '')">Wyczyść</x-panel.button>
        <span class="ml-auto text-sm text-slate-600">{{ Format::number($page->total) }} {{ $page->total === 1 ? 'fraza' : 'fraz' }}</span>
      </div>
    </form>

    @if ($filters->movement !== null)
      <p class="mb-3 text-xs text-slate-500">
        Wzrosty i spadki: frazy z co najmniej {{ $moversMinImpressions }} wyświetleniami w obu okresach (bez szumu fraz z pojedynczymi wyświetleniami).
      </p>
    @endif

    @if ($page->rows === [])
      <x-panel.empty-state title="Brak fraz dla wybranych filtrów" description="Zmień filtry albo okres." />
    @else
      <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50">
            <tr>
              @foreach ($columns as [$sort, $title, $align])
                <th scope="col" class="{{ $align }} whitespace-nowrap px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-600">
                  @if ($sort)
                    <a href="{{ $sortUrl($sort) }}" class="hover:text-slate-900" @if ($filters->sort === $sort) aria-sort="{{ $filters->direction === 'asc' ? 'ascending' : 'descending' }}" @endif>{{ $title }}{{ $sortMark($sort) }}</a>
                  @else
                    {{ $title }}
                  @endif
                </th>
              @endforeach
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach ($page->rows as $row)
              <tr class="hover:bg-slate-50">
                <td class="min-w-48 max-w-xs px-3 py-2 text-slate-900">
                  <span class="break-words">{{ $row->keyword }}</span>
                  @if ($row->isNew())
                    <span class="ml-1 inline-flex rounded bg-sky-50 px-1.5 py-0.5 text-xs font-medium text-sky-700">nowa</span>
                  @endif
                  @if ($market !== null && $row->market !== null)
                    @php($metrics = $row->market)
                    <details class="mt-1 text-xs text-slate-600">
                      <summary class="cursor-pointer select-none text-brand-600 hover:underline">Dane rynkowe</summary>
                      <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                        <dt class="text-slate-500">CPC</dt>
                        <dd class="tabular-nums">{{ Format::usd($metrics->cpc) }}</dd>
                        <dt class="text-slate-500">Konkurencja Ads</dt>
                        <dd>{{ Format::adsCompetition($metrics->competitionLevel) }}@if ($metrics->competitionIndex !== null) ({{ $metrics->competitionIndex }}/100)@endif</dd>
                        <dt class="text-slate-500">Rynek</dt>
                        <dd>{{ $market->label() }}</dd>
                        <dt class="text-slate-500">Aktualizacja</dt>
                        <dd>{{ Format::datetime($metrics->updatedAt()) }}</dd>
                      </dl>
                      @if ($metrics->monthly !== [])
                        @php($peak = max(1, ...array_map(fn ($m) => (int) ($m['search_volume'] ?? 0), $metrics->monthly)))
                        <div class="mt-2 flex h-10 items-end gap-0.5" role="img" aria-label="Wolumen w kolejnych miesiącach">
                          @foreach ($metrics->monthly as $month)
                            <span @class(['w-2 rounded-sm', 'bg-brand-500' => $month['search_volume'] !== null, 'bg-slate-200' => $month['search_volume'] === null])
                              style="height: {{ $month['search_volume'] === null ? 8 : max(4, (int) round($month['search_volume'] / $peak * 100)) }}%"
                              title="{{ Format::month($month['month']) }}: {{ Format::number($month['search_volume']) }}"></span>
                          @endforeach
                        </div>
                        <p class="mt-0.5 text-slate-400">{{ Format::month($metrics->monthly[0]['month']) }} – {{ Format::month($metrics->monthly[count($metrics->monthly) - 1]['month']) }}, szczyt {{ Format::number($peak) }}</p>
                      @endif
                    </details>
                  @endif
                </td>
                <td class="px-3 py-2 text-right tabular-nums">{{ Format::number($row->clicks) }}</td>
                <td class="px-3 py-2 text-right"><x-panel.delta :value="$row->clicksChange()" /></td>
                <td class="px-3 py-2 text-right tabular-nums">{{ Format::number($row->impressions) }}</td>
                <td class="px-3 py-2 text-right"><x-panel.delta :value="$row->impressionsChange()" /></td>
                <td class="px-3 py-2 text-right tabular-nums">{{ Format::percent($row->ctr()) }}</td>
                <td class="px-3 py-2 text-right"><x-panel.delta :value="$row->ctrChange()" format="points" :decimals="1" /></td>
                <td class="px-3 py-2 text-right tabular-nums">{{ Format::position($row->position()) }}</td>
                <td class="px-3 py-2 text-right">
                  @if ($row->positionChange() === null)
                    <span class="text-slate-400">—</span>
                  @else
                    <x-panel.delta :value="$row->positionChange()" format="position" />
                    <span class="block text-xs text-slate-400">{{ Format::position($row->previousPosition()) }} → {{ Format::position($row->position()) }}</span>
                  @endif
                </td>
                @if ($market !== null)
                  <td class="px-3 py-2 text-right tabular-nums">
                    @if ($row->market?->searchVolume !== null)
                      {{ Format::number($row->market->searchVolume) }}
                    @else
                      <span class="text-slate-400" title="{{ $unknown($row->market, (bool) $row->market?->volumeFetched()) }}">—</span>
                    @endif
                  </td>
                  <td class="px-3 py-2 text-right tabular-nums">
                    @if ($row->market?->keywordDifficulty !== null)
                      <span class="{{ $difficultyClass($row->market->keywordDifficulty) }}">{{ $row->market->keywordDifficulty }}</span>
                    @else
                      <span class="text-slate-400" title="{{ $unknown($row->market, (bool) $row->market?->difficultyFetched()) }}">—</span>
                    @endif
                  </td>
                @endif
                <td class="max-w-xs px-3 py-2">
                  @if ($row->primaryPage && preg_match('#^https?://#i', $row->primaryPage))
                    <a href="{{ $row->primaryPage }}" target="_blank" rel="noopener noreferrer" class="break-all text-brand-600 hover:underline">{{ \OsfSeo\Gsc\Dictionary::path($row->primaryPage) }}</a>
                  @else
                    <span class="text-slate-400">—</span>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      @if ($page->pages() > 1)
        @php($current = $filters->page)
        <nav class="mt-4 flex items-center justify-between text-sm" aria-label="Strony">
          <span class="text-slate-600">Strona {{ $current }} z {{ $page->pages() }}</span>
          <div class="flex gap-2">
            @if ($current > 1)
              <x-panel.button variant="secondary" :href="$url(['page' => $current - 1])">Poprzednia</x-panel.button>
            @endif
            @if ($current < $page->pages())
              <x-panel.button variant="secondary" :href="$url(['page' => $current + 1])">Następna</x-panel.button>
            @endif
          </div>
        </nav>
      @endif
    @endif

    <div class="mt-6 space-y-1 text-xs text-slate-500">
      <p><strong class="font-medium text-slate-600">Średnia pozycja (GSC)</strong> to średnia pozycja z wyświetleń w okresie (ważona wyświetleniami) — nie jest to dokładna pozycja w wynikach Google.</p>
      <p><strong class="font-medium text-slate-600">Zmiana pozycji</strong> = pozycja poprzednia − obecna: wartość dodatnia (↑) oznacza poprawę, np. 15 → 7 = +8. CTR = kliknięcia / wyświetlenia w okresie.</p>
      <p>Google nie pokazuje zapytań zanonimizowanych, dlatego suma kliknięć fraz bywa mniejsza niż suma kliknięć projektu — to oczekiwane. Daty GSC są w czasie pacyficznym.</p>
      @if ($market !== null)
        <p><strong class="font-medium text-slate-600">Wolumen</strong> — średnia miesięczna liczba wyszukiwań na rynku {{ $market->label() }} (Google Ads przez DataForSEO). <strong class="font-medium text-slate-600">Trudność SEO</strong> — szacunek DataForSEO 0–100 trudności wejścia do organicznego TOP 10 (to nie konkurencja Ads). „—” = brak danych, nie 0. Dane rynkowe: <a href="{{ PanelUrl::project($project->publicId, 'market-data') }}" class="text-brand-600 hover:underline">Dane rynkowe</a>.</p>
      @endif
    </div>
  @endif
@endsection
