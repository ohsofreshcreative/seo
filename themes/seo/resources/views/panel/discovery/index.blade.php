@extends('panel.layouts.app', ['active' => 'discovery'])

@section('title', 'Nowe frazy · ' . $project->name)

@php
  use App\Http\Controllers\Panel\DiscoveryController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Discovery\CandidateRow;
  use OsfSeo\Discovery\CandidateStatus;
  use OsfSeo\Discovery\DiscoveredKeyword;
  use OsfSeo\Discovery\DiscoveryRun;
  use OsfSeo\Discovery\Visibility;
  use OsfSeo\Opportunities\Text;

  $base = PanelUrl::project($project->publicId, 'discovery');
  $url = fn (array $changes = []) => $base . (($query = http_build_query($filters->toQuery($changes + ['page' => '']))) !== '' ? '?' . $query : '');
  $pageUrl = fn (int $number) => $base . (($query = http_build_query($filters->withPage($number)->toQuery())) !== '' ? '?' . $query : '');
  $sortUrl = function (string $sort) use ($filters, $url): string {
    $ascending = in_array($sort, \OsfSeo\Discovery\CandidateFilters::ASCENDING, true);
    $direction = $filters->sort === $sort ? ($filters->direction === 'asc' ? 'desc' : 'asc') : ($ascending ? 'asc' : 'desc');

    return $url(['sort' => $sort, 'dir' => $direction]);
  };
  $summary = $status['summary'];
  $active = $status['active'];
  $returnQuery = http_build_query($filters->toQuery());
@endphp

@section('content')
  <x-panel.page-header title="Nowe frazy"
    :description="$project->name . ' · frazy z rynku, które strona może jeszcze pokryć — wyszukiwanie DataForSEO, porównane z Google Search Console'">
    @if ($canManage)
      <x-slot:actions>
        <x-panel.button :href="$base . '/new'">Znajdź nowe frazy</x-panel.button>
      </x-slot:actions>
    @endif
  </x-panel.page-header>

  @if ($status['market'] === null)
    <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      Rynek projektu ({{ strtoupper($project->country) }} / {{ $project->language }}) nie jest obsługiwany przez dostawcę — zmień kraj i język w ustawieniach projektu.
    </div>
  @elseif (! $status['configured'])
    <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      DataForSEO nie jest skonfigurowane — wyszukiwanie nowych fraz jest niedostępne. Zapisane frazy i praca nad nimi działają dalej.
    </div>
  @endif

  @if ($active !== null)
    <div class="mb-6">
      @include('panel.discovery.partials.progress', ['progress' => $active])
      <p class="mt-1 text-xs"><a href="{{ DiscoveryController::runUrl($project->publicId, $active['id']) }}" class="text-brand-600 hover:underline">Szczegóły przebiegu</a></p>
    </div>
  @endif

  @if ($summary !== null)
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
      @foreach ([
        ['gap', 'Do sprawdzenia', $summary['visibility']['none'] + $summary['visibility']['low'] + $summary['visibility']['unknown'], 'brak lub słaba widoczność w GSC'],
        ['none', 'Brak widoczności', $summary['visibility']['none'], 'strona nie pojawia się w GSC'],
        ['low', 'Słaba widoczność', $summary['visibility']['low'], 'poza TOP 10 albo sporadycznie'],
        ['visible', 'Już widoczne', $summary['visibility']['visible'], 'TOP 10 średniej pozycji GSC'],
      ] as [$value, $label, $count, $hint])
        <a href="{{ $url(['visibility' => $value === 'gap' ? '' : $value]) }}" @class([
          'rounded-lg border bg-white p-4 shadow-sm hover:border-brand-500',
          'border-brand-500 ring-1 ring-brand-500' => $filters->visibility === $value,
          'border-slate-200' => $filters->visibility !== $value,
        ])>
          <p class="text-xs font-medium text-slate-500">{{ $label }}</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ Format::number($count) }}</p>
          <p class="text-xs text-slate-500">{{ $hint }}</p>
        </a>
      @endforeach
    </div>
  @endif

  <form method="get" action="{{ $base }}" class="mt-6 grid grid-cols-2 gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-4 xl:grid-cols-8">
    <div class="col-span-2">
      <label for="f-q" class="block text-xs font-medium text-slate-600">Szukaj frazy</label>
      <input id="f-q" name="q" type="search" value="{{ $filters->q }}" placeholder="np. sklep internetowy" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
    </div>
    <div>
      <label for="f-status" class="block text-xs font-medium text-slate-600">Status</label>
      <select id="f-status" name="status" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <option value="open" @selected($filters->status === 'open')>Do decyzji</option>
        <option value="all" @selected($filters->status === 'all')>Wszystkie</option>
        @foreach (CandidateStatus::cases() as $option)
          <option value="{{ $option->value }}" @selected($filters->status === $option->value)>{{ $option->label() }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label for="f-visibility" class="block text-xs font-medium text-slate-600">Widoczność GSC</label>
      <select id="f-visibility" name="visibility" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <option value="gap" @selected($filters->visibility === 'gap')>Do sprawdzenia</option>
        <option value="all" @selected($filters->visibility === 'all')>Wszystkie</option>
        @foreach (Visibility::cases() as $option)
          <option value="{{ $option->value }}" @selected($filters->visibility === $option->value)>{{ $option->label() }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label for="f-volume" class="block text-xs font-medium text-slate-600">Min. wolumen</label>
      <input id="f-volume" name="min_volume" type="number" min="0" value="{{ $filters->minVolume }}" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
    </div>
    <div>
      <label for="f-kd" class="block text-xs font-medium text-slate-600">Maks. trudność SEO</label>
      <input id="f-kd" name="max_kd" type="number" min="0" max="100" value="{{ $filters->maxDifficulty }}" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
    </div>
    <div>
      <label for="f-priority" class="block text-xs font-medium text-slate-600">Min. priorytet</label>
      <input id="f-priority" name="min_priority" type="number" min="0" max="100" value="{{ $filters->minPriority }}" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
    </div>
    <div>
      <label for="f-intent" class="block text-xs font-medium text-slate-600">Intencja</label>
      <select id="f-intent" name="intent" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <option value="">Dowolna</option>
        @foreach (DiscoveredKeyword::INTENTS as $intent)
          <option value="{{ $intent }}" @selected($filters->intent === $intent)>{{ CandidateRow::intentLabel($intent) }}</option>
        @endforeach
      </select>
    </div>
    <div class="col-span-2 flex flex-wrap items-end gap-3 md:col-span-4 xl:col-span-8">
      <input type="hidden" name="sort" value="{{ $filters->sort === 'priority' ? '' : $filters->sort }}">
      <x-panel.button type="submit">Filtruj</x-panel.button>
      <x-panel.button variant="secondary" :href="$base">Wyczyść</x-panel.button>
      @if (($summary['excluded'] ?? 0) > 0 || $filters->excluded)
        <label class="ml-auto inline-flex items-center gap-2 text-sm text-slate-600">
          <input type="checkbox" name="excluded" value="1" @checked($filters->excluded) class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
          Pokaż wykluczone ({{ Format::number($summary['excluded'] ?? 0) }})
        </label>
      @endif
    </div>
  </form>

  <div class="mt-6">
    @if ($page === null || $page->total === 0)
      <x-panel.empty-state :title="($summary['total'] ?? 0) === 0 ? 'Brak znalezionych fraz' : 'Brak fraz dla wybranych filtrów'"
        :description="($summary['total'] ?? 0) === 0
          ? 'Uruchom wyszukiwanie z frazami startowymi (seedami), np. nazwami usług. Przed wysłaniem czegokolwiek zobaczysz liczbę żądań i maksymalny koszt.'
          : 'Zmień filtry — np. widoczność „Wszystkie” albo status „Wszystkie”.'" />
    @else
      <form method="post" action="{{ $base }}/bulk" x-data="{ selected: [] }">
        <x-panel.nonce />
        <input type="hidden" name="return" value="{{ $returnQuery }}">
        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
          <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <tr>
                @if ($canManage || $canTrack)
                  <th class="w-8 px-3 py-2"><span class="sr-only">Zaznacz</span></th>
                @endif
                @foreach ([
                  ['keyword', 'Fraza', 'text-left'],
                  ['volume', 'Wolumen', 'text-right'],
                  ['difficulty', 'Trudność SEO', 'text-right'],
                  ['priority', 'Priorytet', 'text-center'],
                ] as [$sort, $label, $align])
                  <th class="px-3 py-2 {{ $align }}">
                    <a href="{{ $sortUrl($sort) }}" class="hover:text-slate-900">{{ $label }}@if ($filters->sort === $sort) {{ $filters->direction === 'asc' ? '↑' : '↓' }}@endif</a>
                  </th>
                @endforeach
                <th class="px-3 py-2">Widoczność GSC</th>
                <th class="px-3 py-2 text-right">
                  <a href="{{ $sortUrl('position') }}" class="hover:text-slate-900" title="Średnia pozycja z Google Search Console (nie dokładna pozycja w Google)">Śr. pozycja (GSC)@if ($filters->sort === 'position') {{ $filters->direction === 'asc' ? '↑' : '↓' }}@endif</a>
                </th>
                <th class="px-3 py-2">Źródło</th>
                <th class="px-3 py-2">
                  <a href="{{ $sortUrl('discovered') }}" class="hover:text-slate-900" title="Sortuj wg daty znalezienia">Status {{ $filters->sort === 'discovered' ? ($filters->direction === 'asc' ? '↑' : '↓') : '' }}</a>
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              @foreach ($page->rows as $row)
                <tr class="hover:bg-slate-50">
                  @if ($canManage || $canTrack)
                    <td class="px-3 py-2"><input type="checkbox" name="ids[]" value="{{ $row->publicId }}" x-model="selected" aria-label="Zaznacz {{ $row->keyword }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"></td>
                  @endif
                  <td class="min-w-48 max-w-xs px-3 py-2">
                    <a href="{{ DiscoveryController::candidateUrl($project->publicId, $row->publicId) }}" class="break-words font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $row->keyword }}</a>
                    @if ($row->intent !== null)
                      <span class="ml-1 inline-flex rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600" title="Intencja wyszukiwania według DataForSEO">{{ CandidateRow::intentLabel($row->intent) }}</span>
                    @endif
                    @if ($row->excluded)
                      <span class="ml-1 inline-flex rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-800">wykluczona</span>
                    @endif
                  </td>
                  <td class="px-3 py-2 text-right tabular-nums" title="{{ $row->market->volumeFetched() ? 'Średnia miesięczna liczba wyszukiwań' : 'Brak danych' }}">{{ Format::number($row->market->searchVolume) }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ Format::number($row->market->keywordDifficulty) }}</td>
                  <td class="px-3 py-2 text-center">
                    @if ($row->priority !== null)
                      <x-panel.score :value="$row->priority" size="sm" />
                    @else
                      <span class="text-slate-400" title="Priorytet zostanie przeliczony w tle">—</span>
                    @endif
                  </td>
                  <td class="px-3 py-2"><x-panel.visibility :value="$row->visibility" /></td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ Format::position($row->gscPosition) }}</td>
                  <td class="px-3 py-2 text-xs text-slate-600">{{ $row->seedsCount }} {{ Text::plural($row->seedsCount, 'seed', 'seedy', 'seedów') }}</td>
                  <td class="px-3 py-2"><x-panel.candidate-status :status="$row->status" /></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>

        @if ($canManage || $canTrack)
          <div class="mt-3 flex flex-wrap items-center gap-2 text-sm" x-show="selected.length > 0" x-cloak>
            <span class="text-slate-600">Zaznaczone: <span class="font-medium" x-text="selected.length"></span></span>
            @if ($canManage)
              <label for="bulk-status" class="sr-only">Nowy status</label>
              <select id="bulk-status" name="status" class="rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                @foreach (CandidateStatus::cases() as $option)
                  <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
              </select>
              <x-panel.button type="submit" variant="secondary">Zmień status</x-panel.button>
            @endif
            @if ($canTrack)
              {{-- Ten sam wybór trafia do modułu Pozycje (dodanie do monitorowania — bez kosztów i bez żądań do API). --}}
              <input type="hidden" name="source" value="discovery">
              <input type="hidden" name="back" value="{{ $pageUrl($filters->page) }}">
              <x-panel.button type="submit" variant="secondary" :formaction="PanelUrl::project($project->publicId, 'positions/keywords')">Monitoruj pozycję</x-panel.button>
            @endif
          </div>
        @endif
      </form>

      @if ($page->pages() > 1)
        <nav class="mt-4 flex items-center justify-between text-sm" aria-label="Strony">
          <span class="text-slate-600">{{ Format::number($page->total) }} {{ Text::plural($page->total, 'fraza', 'frazy', 'fraz') }} · strona {{ $filters->page }} z {{ $page->pages() }}</span>
          <div class="flex gap-2">
            @if ($filters->page > 1)
              <x-panel.button variant="secondary" :href="$pageUrl($filters->page - 1)">Poprzednia</x-panel.button>
            @endif
            @if ($filters->page < $page->pages())
              <x-panel.button variant="secondary" :href="$pageUrl($filters->page + 1)">Następna</x-panel.button>
            @endif
          </div>
        </nav>
      @else
        <p class="mt-3 text-sm text-slate-600">{{ Format::number($page->total) }} {{ Text::plural($page->total, 'fraza', 'frazy', 'fraz') }}</p>
      @endif
    @endif
  </div>

  <div class="mt-8 grid gap-6 lg:grid-cols-2">
    @if ($recent !== [])
      <x-panel.card>
        <h2 class="text-base font-semibold text-slate-900">Ostatnie wyszukiwania</h2>
        <ul class="mt-3 divide-y divide-slate-100 text-sm">
          @foreach ($recent as $run)
            <li class="flex items-center justify-between gap-3 py-2">
              <a href="{{ DiscoveryController::runUrl($project->publicId, $run->publicId) }}" class="text-brand-600 hover:underline">
                {{ Format::datetime($run->createdAt) }} · {{ $run->method->label() }} · {{ $run->seedsCount }} {{ Text::plural($run->seedsCount, 'seed', 'seedy', 'seedów') }}
              </a>
              <span @class(['text-xs', 'text-amber-700' => in_array($run->status, [DiscoveryRun::FAILED, DiscoveryRun::PARTIAL], true), 'text-slate-500' => ! in_array($run->status, [DiscoveryRun::FAILED, DiscoveryRun::PARTIAL], true)])>
                {{ $run->statusLabel() }} · {{ Format::number($run->candidatesNew) }} {{ Text::plural($run->candidatesNew, 'nowa fraza', 'nowe frazy', 'nowych fraz') }}
              </span>
            </li>
          @endforeach
        </ul>
      </x-panel.card>
    @endif

    @if ($canManage)
      <x-panel.card>
        <h2 class="text-base font-semibold text-slate-900">Wykluczone słowa</h2>
        <p class="mt-1 text-xs text-slate-500">Frazy zawierające te słowa nie trafiają na listę (np. „praca”, „torrent”). Całe słowa; <code>*</code> na końcu = dowolna końcówka („darmow*” → darmowe, darmowy). Zmiana działa od razu, także dla zapisanych fraz — bez kosztów.</p>
        <form method="post" action="{{ $base }}/exclusions" class="mt-3 space-y-3">
          <x-panel.nonce />
          <label for="excluded-terms" class="sr-only">Wykluczone słowa (po jednym w wierszu)</label>
          <textarea id="excluded-terms" name="excluded_terms" rows="4" placeholder="praca&#10;darmow*" class="block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">{{ $exclusions }}</textarea>
          <x-panel.button type="submit" variant="secondary">Zapisz wykluczenia</x-panel.button>
        </form>
      </x-panel.card>
    @endif
  </div>

  <div class="mt-8 space-y-1 text-xs text-slate-500">
    <p><strong class="font-medium text-slate-600">Priorytet</strong> (0–100) mówi, jak bardzo warto przyjrzeć się frazie: popyt (wolumen, skala logarytmiczna) + osiągalność (trudność SEO) + trafność (powiązanie z seedem, liczba seedów) + luka w GSC + niewielki sygnał komercyjny (CPC). To nie jest prognoza ruchu ani wartość biznesowa.</p>
    <p><strong class="font-medium text-slate-600">Widoczność GSC</strong> — z ostatnich {{ $status['settings']['window_days'] }} dni Google Search Console: „Już widoczna” = średnia pozycja (GSC) ≤ {{ Format::number($status['settings']['visible_position']) }} przy istotnej liczbie wyświetleń; średnia pozycja GSC to nie dokładna pozycja w Google.</p>
    <p><strong class="font-medium text-slate-600">Wolumen</strong>, <strong class="font-medium text-slate-600">Trudność SEO</strong> i intencja pochodzą z DataForSEO. „—” oznacza brak danych (nie 0).</p>
  </div>
@endsection
