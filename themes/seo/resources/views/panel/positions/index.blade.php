@extends('panel.layouts.app', ['active' => 'positions'])

@section('title', 'Pozycje · ' . $project->name)

@php
  use App\Http\Controllers\Panel\CompetitorsController;
  use App\Http\Controllers\Panel\PositionsController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Opportunities\Text;
  use OsfSeo\Serp\SerpRun;

  $base = PanelUrl::project($project->publicId, 'positions');
  $url = fn (array $changes = []) => $base . (($query = http_build_query($filters->toQuery($changes + ['page' => '']))) !== '' ? '?' . $query : '');
  $pageUrl = fn (int $number) => $base . (($query = http_build_query($filters->toQuery(['page' => $number > 1 ? $number : '']))) !== '' ? '?' . $query : '');
  $sortUrl = function (string $sort) use ($filters, $url): string {
    $ascending = in_array($sort, ['rank', 'keyword', 'difficulty'], true);
    $direction = $filters->sort === $sort ? ($filters->direction === 'asc' ? 'desc' : 'asc') : ($ascending ? 'asc' : 'desc');

    return $url(['sort' => $sort, 'dir' => $direction]);
  };
  $sortMark = fn (string $sort) => $filters->sort === $sort ? ($filters->direction === 'asc' ? ' ▲' : ' ▼') : '';
  $pages = max(1, (int) ceil($total / $filters->perPage));
  $tracked = (int) ($summary['tracked'] ?? 0);
  $cards = [
    ['band', 'top3', 'TOP 3', $summary['top3'] ?? 0, 'pozycja SERP 1–3'],
    ['band', 'top10', 'TOP 10', $summary['top10'] ?? 0, 'pozycja SERP 1–10'],
    ['change', 'up', 'Wzrosty', $summary['improved'] ?? 0, 'względem poprzedniego pomiaru'],
    ['change', 'down', 'Spadki', $summary['declined'] ?? 0, 'względem poprzedniego pomiaru'],
    ['change', 'top10_entered', 'Weszły do TOP 10', $summary['top10_entered'] ?? 0, 'w ostatnim pomiarze'],
    ['change', 'top10_left', 'Wypadły z TOP 10', $summary['top10_left'] ?? 0, 'w ostatnim pomiarze'],
    ['band', 'out', 'Poza sprawdzonym TOP', $summary['outside'] ?? 0, 'domeny projektu nie ma w wynikach'],
    ['band', 'unchecked', 'Jeszcze niesprawdzone', $summary['unchecked'] ?? 0, 'czekają na pierwszy pomiar'],
  ];
  $bands = ['all' => 'Wszystkie', 'top3' => 'TOP 3', 'top10' => 'TOP 10', 'top20' => 'TOP 20', 'top50' => 'TOP 50', 'found' => 'W wynikach', 'out' => 'Poza sprawdzonym TOP', 'unchecked' => 'Niesprawdzone'];
  $changes = ['all' => 'Wszystkie', 'up' => 'Wzrosty', 'down' => 'Spadki', 'entered' => 'Weszły do TOP', 'left' => 'Wypadły z TOP', 'top10_entered' => 'Weszły do TOP 10', 'top10_left' => 'Wypadły z TOP 10', 'new' => 'Pierwszy pomiar', 'out' => 'Nadal poza TOP'];
  $sources = ['manual' => 'dodana ręcznie', 'gsc' => 'z fraz GSC', 'discovery' => 'z Nowych fraz', 'gap' => 'z Luk SEO'];
  $listUrl = $url();
@endphp

@section('content')
  <x-panel.page-header title="Pozycje"
    :description="$project->name . ' · Pozycja SERP z pomiarów Google (DataForSEO) dla monitorowanych fraz — osobno od średniej pozycji z Search Console'">
    @if ($canManage)
      <x-slot:actions>
        <x-panel.button variant="secondary" :href="$base . '/add'">Dodaj frazy</x-panel.button>
        <x-panel.button variant="secondary" :href="$base . '/settings'">Ustawienia i koszt</x-panel.button>
        @if ($tracked > 0)
          <x-panel.button :href="PositionsController::checkUrl($project->publicId)">Sprawdź pozycje teraz</x-panel.button>
        @endif
      </x-slot:actions>
    @endif
  </x-panel.page-header>

  @if ($market === null)
    <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      Rynek projektu ({{ strtoupper($project->country) }} / {{ $project->language }}) nie jest obsługiwany — zmień kraj i język w ustawieniach projektu.
    </div>
  @elseif (! $configured)
    <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      DataForSEO nie jest skonfigurowane — nowe pomiary są niedostępne. Zapisane pomiary i historia są widoczne dalej.
    </div>
  @elseif ($canManage && ! $settings->enabled && $tracked > 0)
    <div class="mb-6 rounded-md border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
      Automatyczne pomiary są wyłączone (bez kosztów). Włącz harmonogram w <a href="{{ $base }}/settings" class="text-brand-600 hover:underline">ustawieniach</a> albo użyj „Sprawdź pozycje teraz”.
    </div>
  @endif

  @if ($active !== null)
    <div class="mb-6">
      @include('panel.positions.partials.progress', ['progress' => $active])
      <p class="mt-1 text-xs"><a href="{{ PositionsController::runUrl($project->publicId, $active['id']) }}" class="text-brand-600 hover:underline">Szczegóły pomiaru</a></p>
    </div>
  @endif

  @if ($tracked === 0)
    <x-panel.empty-state title="Brak monitorowanych fraz"
      description="Dodaj frazy ręcznie albo zaznacz je na listach „Frazy” i „Nowe frazy” (przycisk „Monitoruj pozycję”). Dodanie frazy nic nie kosztuje — koszt pojawia się dopiero przy pomiarze, zawsze po podglądzie.">
      @if ($canManage)
        <x-panel.button :href="$base . '/add'">Dodaj frazy</x-panel.button>
        <x-panel.button variant="secondary" :href="PanelUrl::project($project->publicId, 'keywords')">Przejdź do fraz GSC</x-panel.button>
      @endif
    </x-panel.empty-state>
  @else
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
      @foreach ($cards as [$filter, $value, $label, $count, $hint])
        @php($selected = ($filter === 'band' ? $filters->band : $filters->change) === $value)
        <a href="{{ $url([$filter => $selected ? '' : $value]) }}" @class([
          'rounded-lg border bg-white p-4 shadow-sm hover:border-brand-500',
          'border-brand-500 ring-1 ring-brand-500' => $selected,
          'border-slate-200' => ! $selected,
        ])>
          <p class="text-xs font-medium text-slate-500">{{ $label }}</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ Format::number($count) }}</p>
          <p class="text-xs text-slate-500">{{ $hint }}</p>
        </a>
      @endforeach
    </div>
    <p class="mt-2 text-xs text-slate-500">
      Monitorowane frazy: {{ Format::number($tracked) }}@if ($canManage) (zalecany limit {{ Format::number($maxKeywords) }})@endif ·
      ostatni pomiar: {{ Format::datetime($summary['last_checked'] ?? null) }} ·
      harmonogram: {{ $settings->enabled ? $settings->frequency->label() . ', ' . $settings->device->label() . ', TOP' . $settings->depth : 'wyłączony' }}
    </p>

    <form method="get" action="{{ $base }}" class="mt-6 grid grid-cols-2 gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-6">
      <div class="col-span-2">
        <label for="f-q" class="block text-xs font-medium text-slate-600">Szukaj frazy</label>
        <input id="f-q" name="q" type="search" value="{{ $filters->q }}" maxlength="100" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
      </div>
      <div>
        <label for="f-band" class="block text-xs font-medium text-slate-600">Pozycja SERP</label>
        <select id="f-band" name="band" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
          @foreach ($bands as $value => $label)
            <option value="{{ $value === 'all' ? '' : $value }}" @selected($filters->band === $value)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label for="f-change" class="block text-xs font-medium text-slate-600">Zmiana</label>
        <select id="f-change" name="change" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
          @foreach ($changes as $value => $label)
            <option value="{{ $value === 'all' ? '' : $value }}" @selected($filters->change === $value)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-span-2 flex items-end gap-2">
        <input type="hidden" name="sort" value="{{ $filters->sort === 'rank' ? '' : $filters->sort }}">
        <input type="hidden" name="dir" value="{{ $filters->direction }}">
        <x-panel.button type="submit">Filtruj</x-panel.button>
        <x-panel.button variant="secondary" :href="$base">Wyczyść</x-panel.button>
      </div>
    </form>

    <div class="mt-6">
      @if ($rows === [])
        <x-panel.empty-state title="Brak fraz dla wybranych filtrów" description="Zmień filtry." />
      @else
        <form method="post" action="{{ $base }}/remove" x-data="{ selected: [], checkUrl: @js(PositionsController::checkUrl($project->publicId)) }"
          @submit="if (! confirm('Zakończyć monitorowanie zaznaczonych fraz? Historia pomiarów zostanie zachowana.')) { $event.preventDefault(); }">
          <x-panel.nonce />
          <input type="hidden" name="back" value="{{ $listUrl }}">
          <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
              <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-600">
                <tr>
                  @if ($canManage)
                    <th class="w-8 px-3 py-2.5"><span class="sr-only">Zaznacz</span></th>
                  @endif
                  <th class="px-3 py-2.5 text-left"><a href="{{ $sortUrl('keyword') }}" class="hover:text-slate-900">Fraza{{ $sortMark('keyword') }}</a></th>
                  <th class="px-3 py-2.5 text-right"><a href="{{ $sortUrl('volume') }}" class="hover:text-slate-900">Wolumen{{ $sortMark('volume') }}</a></th>
                  <th class="px-3 py-2.5 text-right"><a href="{{ $sortUrl('difficulty') }}" class="hover:text-slate-900" title="Trudność SEO (DataForSEO)">KD{{ $sortMark('difficulty') }}</a></th>
                  <th class="whitespace-nowrap px-3 py-2.5 text-right"><a href="{{ $sortUrl('rank') }}" class="hover:text-slate-900" title="Pozycja w wynikach organicznych Google z ostatniego pomiaru">Pozycja SERP{{ $sortMark('rank') }}</a></th>
                  <th class="px-3 py-2.5 text-right"><a href="{{ $sortUrl('change') }}" class="hover:text-slate-900">Zmiana{{ $sortMark('change') }}</a></th>
                  <th class="px-3 py-2.5 text-left">URL</th>
                  <th class="whitespace-nowrap px-3 py-2.5 text-right" title="Średnia pozycja z Google Search Console (ostatnie {{ \OsfSeo\Serp\SerpConfig::GSC_DAYS }} dni) — inna metryka niż Pozycja SERP">Średnia pozycja (GSC)</th>
                  @if ($competitors !== [])
                    <th class="px-3 py-2.5 text-left">Konkurenci</th>
                  @endif
                  <th class="whitespace-nowrap px-3 py-2.5 text-left"><a href="{{ $sortUrl('checked') }}" class="hover:text-slate-900">Ostatni pomiar{{ $sortMark('checked') }}</a></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                @foreach ($rows as $row)
                  <tr class="hover:bg-slate-50">
                    @if ($canManage)
                      <td class="px-3 py-2"><input type="checkbox" name="ids[]" value="{{ $row->publicId }}" x-model="selected" aria-label="Zaznacz {{ $row->keyword }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"></td>
                    @endif
                    <td class="min-w-48 max-w-xs px-3 py-2">
                      <a href="{{ PositionsController::keywordUrl($project->publicId, $row->publicId) }}" class="break-words font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $row->keyword }}</a>
                      <span class="block text-xs text-slate-400">{{ $sources[$row->source] ?? $row->source }}</span>
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums" @if ($row->searchVolume === null) title="Brak danych rynkowych (fraza dodana bez wzbogacania)" @endif>{{ Format::number($row->searchVolume) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ Format::number($row->difficulty) }}</td>
                    <td class="px-3 py-2 text-right"><x-panel.serp-rank :rank="$row->rank" :found="$row->found" :depth="$row->depth" :featured="$row->featured" /></td>
                    <td class="px-3 py-2 text-right"><x-panel.rank-change :type="$row->changeType" :value="$row->changeValue" :top10="$row->top10Change" :depth="$row->depth" class="items-end" /></td>
                    <td class="max-w-xs px-3 py-2">
                      @if ($row->url !== null && preg_match('#^https?://#i', $row->url))
                        <a href="{{ $row->url }}" target="_blank" rel="noopener noreferrer" class="break-all text-brand-600 hover:underline">{{ preg_replace('#^https?://(www\.)?#i', '', $row->url) }}</a>
                      @else
                        <span class="text-slate-400">—</span>
                      @endif
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums" title="{{ $row->gscPosition === null ? 'Brak danych GSC dla tej frazy' : Format::number($row->gscImpressions) . ' wyświetleń w GSC' }}">{{ Format::position($row->gscPosition) }}</td>
                    @if ($competitors !== [])
                      <td class="px-3 py-2 text-xs">
                        <ul class="space-y-0.5">
                          @foreach ($competitors as $competitor)
                            @php($found = $row->competitors[$competitor->publicId] ?? null)
                            <li class="flex justify-between gap-3 whitespace-nowrap">
                              <span class="max-w-32 truncate text-slate-600" title="{{ $competitor->domain }}">{{ $competitor->name }}</span>
                              <span class="tabular-nums {{ $found === null ? 'text-slate-400' : 'font-medium text-slate-800' }}">{{ $found === null ? ($row->checked() ? '—' : '') : '#' . $found['best'] }}</span>
                            </li>
                          @endforeach
                        </ul>
                      </td>
                    @endif
                    <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-600">{{ Format::datetime($row->lastCheckedAt) }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>

          @if ($canManage)
            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm" x-show="selected.length > 0" x-cloak>
              <span class="text-slate-600">Zaznaczone: <span class="font-medium" x-text="selected.length"></span></span>
              <x-panel.button variant="secondary" x-on:click="window.location = checkUrl + '?' + selected.map((id) => 'ids%5B%5D=' + encodeURIComponent(id)).join('&')">Sprawdź zaznaczone</x-panel.button>
              <x-panel.button type="submit" variant="danger">Zakończ monitorowanie</x-panel.button>
            </div>
          @endif
        </form>

        <nav class="mt-4 flex items-center justify-between text-sm" aria-label="Strony">
          <span class="text-slate-600">{{ Format::number($total) }} {{ Text::plural($total, 'fraza', 'frazy', 'fraz') }}@if ($pages > 1) · strona {{ $filters->page }} z {{ $pages }}@endif</span>
          @if ($pages > 1)
            <div class="flex gap-2">
              @if ($filters->page > 1)
                <x-panel.button variant="secondary" :href="$pageUrl($filters->page - 1)">Poprzednia</x-panel.button>
              @endif
              @if ($filters->page < $pages)
                <x-panel.button variant="secondary" :href="$pageUrl($filters->page + 1)">Następna</x-panel.button>
              @endif
            </div>
          @endif
        </nav>
      @endif
    </div>
  @endif

  <div class="mt-8 grid gap-6 lg:grid-cols-2">
    @if ($recent !== [])
      <x-panel.card>
        <h2 class="text-base font-semibold text-slate-900">Ostatnie pomiary</h2>
        <ul class="mt-3 divide-y divide-slate-100 text-sm">
          @foreach ($recent as $run)
            <li class="flex items-center justify-between gap-3 py-2">
              <a href="{{ PositionsController::runUrl($project->publicId, $run->publicId) }}" class="text-brand-600 hover:underline">
                {{ Format::datetime($run->createdAt) }} · {{ $run->trigger === 'schedule' ? 'harmonogram' : 'ręcznie' }} · {{ Format::number($run->keywordsPlanned) }} {{ Text::plural($run->keywordsPlanned, 'fraza', 'frazy', 'fraz') }}
              </a>
              <span @class(['text-xs', 'text-amber-700' => in_array($run->status, [SerpRun::FAILED, SerpRun::PARTIAL, SerpRun::SKIPPED], true), 'text-slate-500' => ! in_array($run->status, [SerpRun::FAILED, SerpRun::PARTIAL, SerpRun::SKIPPED], true)])>
                {{ $run->statusLabel() }}@if ($run->status === SerpRun::SKIPPED) — {{ SerpRun::skipLabel($run->skipReason) }}@endif
              </span>
            </li>
          @endforeach
        </ul>
      </x-panel.card>
    @endif

    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Konkurenci</h2>
      <p class="mt-1 text-sm text-slate-600">
        @if ($competitors === [])
          Brak monitorowanych konkurentów.
        @else
          Monitorowani: {{ implode(', ', array_map(fn ($competitor) => $competitor->name, $competitors)) }}.
        @endif
        Pozycje konkurentów odczytujemy z tych samych pełnych wyników — bez dodatkowych kosztów, także wstecz.
      </p>
      <div class="mt-3 flex flex-wrap gap-2">
        <x-panel.button variant="secondary" :href="CompetitorsController::url($project->publicId)">Konkurenci</x-panel.button>
        <x-panel.button variant="secondary" :href="PanelUrl::project($project->publicId, 'competitors/organic')">Konkurenci organiczni</x-panel.button>
      </div>
    </x-panel.card>
  </div>

  <div class="mt-8 space-y-1 text-xs text-slate-500">
    <p><strong class="font-medium text-slate-600">Pozycja SERP</strong> — miejsce domeny projektu (z subdomenami) wśród wyników organicznych Google w ostatnim pomiarze (lokalizacja i język rynku projektu, wybrane urządzenie). Reklamy i moduły (mapy, „Ludzie pytają też” itd.) nie są liczone; wyróżniony fragment jest oznaczany osobno, nie jako #1. To pojedyncza obserwacja — wyniki Google różnią się między użytkownikami i w czasie.</p>
    <p><strong class="font-medium text-slate-600">Średnia pozycja (GSC)</strong> — średnia z wyświetleń w Search Console z ostatnich {{ \OsfSeo\Serp\SerpConfig::GSC_DAYS }} dni. To inna metryka niż Pozycja SERP i obie mogą się różnić.</p>
    <p><strong class="font-medium text-slate-600">Zmiana</strong> — względem poprzedniego pomiaru z tymi samymi ustawieniami (urządzenie, głębokość); dodatnia = poprawa (#12 → #7 = +5). „Poza TOP100” oznacza, że domeny nie ma w sprawdzonych wynikach — to nie jest pozycja 0. Wolumen i KD: „—” = brak danych.</p>
  </div>
@endsection
