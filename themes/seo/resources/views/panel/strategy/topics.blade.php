@extends('panel.layouts.app', ['active' => 'strategy'])

@section('title', 'Backlog Strategii · ' . $project->name)

@php
  use App\Http\Controllers\Panel\StrategyTopicsController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use App\Panel\StrategyLabels;
  use OsfSeo\Discovery\CandidateRow;
  use OsfSeo\Discovery\DiscoveredKeyword;
  use OsfSeo\Opportunities\Text;
  use OsfSeo\Strategy\Decision\StrategyAction;
  use OsfSeo\Strategy\Serp\SerpFreshness;
  use OsfSeo\Strategy\StrategySource;
  use OsfSeo\Strategy\Target\TargetState;
  use OsfSeo\Strategy\Topics\TopicFilters;
  use OsfSeo\Strategy\Topics\TopicStatus;

  $base = PanelUrl::project($project->publicId, 'strategy/topics');
  $url = fn (array $changes = []) => $base . (($query = http_build_query($filters->toQuery($changes + ['page' => '']))) !== '' ? '?' . $query : '');
  $pageUrl = fn (int $number) => $base . (($query = http_build_query($filters->withPage($number)->toQuery())) !== '' ? '?' . $query : '');
  $sortUrl = function (string $sort) use ($filters, $url): string {
    $ascending = in_array($sort, TopicFilters::ASCENDING, true);
    $direction = $filters->sort === $sort ? ($filters->direction === 'asc' ? 'desc' : 'asc') : ($ascending ? 'asc' : 'desc');

    return $url(['sort' => $sort, 'dir' => $direction]);
  };
  $pages = max(1, (int) ceil($page['total'] / $filters->perPage));
  $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
  $safeUrl = static fn (?string $value): bool => $value !== null && preg_match('#^https?://#i', $value) === 1;
  $freshBands = ['top3', 'top10', 'top20', 'top50', 'top100', 'out'];
  $serpOptions = [
    'fresh' => 'Świeży pomiar (≤ 30 dni)',
    'top3' => 'Pozycja SERP: TOP 3',
    'top10' => 'Pozycja SERP: TOP 10',
    'top20' => 'Pozycja SERP: TOP 20',
    'top50' => 'Pozycja SERP: TOP 50',
    'top100' => 'Pozycja SERP: TOP 100',
    'out' => 'Poza sprawdzonymi wynikami',
    'nofresh' => 'Bez świeżego pomiaru',
    'stale' => 'Pomiar nieaktualny (31–90 dni)',
    'none' => 'Brak pomiaru',
  ];
  $select = 'mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500';
@endphp

@section('content')
  <x-panel.page-header title="Backlog Strategii"
    :description="$project->name . ' · tematy (grupy fraz) z działaniem, priorytetem i pewnością — domyślnie aktywne tematy wymagające działania'" />

  @include('panel.strategy.partials.tabs', ['tab' => 'topics'])
  @include('panel.strategy.partials.state', ['back' => $url()])

  <form method="get" action="{{ $base }}" class="mt-4 grid grid-cols-2 gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-4 xl:grid-cols-6">
    <div class="col-span-2">
      <label for="f-q" class="block text-xs font-medium text-slate-600">Szukaj (temat lub fraza)</label>
      <input id="f-q" name="q" type="search" value="{{ $filters->q }}" placeholder="np. pozycjonowanie" class="{{ $select }}">
    </div>
    <div>
      <label for="f-action" class="block text-xs font-medium text-slate-600">Działanie</label>
      <select id="f-action" name="action" class="{{ $select }}">
        <option value="">Dowolne</option>
        @foreach (StrategyAction::cases() as $option)
          <option value="{{ $option->value }}" @selected($filters->action === $option)>{{ $option->label() }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label for="f-status" class="block text-xs font-medium text-slate-600">Status pracy</label>
      <select id="f-status" name="status" class="{{ $select }}">
        <option value="" @selected($filters->status === 'open')>Otwarte</option>
        <option value="all" @selected($filters->status === 'all')>Wszystkie</option>
        @foreach (TopicStatus::cases() as $option)
          @if ($option !== TopicStatus::Dismissed || $canManage)
            <option value="{{ $option->value }}" @selected($filters->status === $option->value)>{{ $option->label() }}</option>
          @endif
        @endforeach
      </select>
    </div>
    <div>
      <label for="f-confidence" class="block text-xs font-medium text-slate-600">Pewność</label>
      <select id="f-confidence" name="confidence" class="{{ $select }}">
        <option value="">Dowolna</option>
        @foreach (TopicFilters::LEVELS as $level)
          <option value="{{ $level }}" @selected($filters->confidence === $level)>{{ ucfirst(StrategyLabels::level($level)) }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label for="f-priority" class="block text-xs font-medium text-slate-600">Min. priorytet</label>
      <input id="f-priority" name="min_priority" type="number" min="0" max="100" value="{{ $filters->minPriority }}" class="{{ $select }}">
    </div>
    <div>
      <label for="f-source" class="block text-xs font-medium text-slate-600">Źródło dowodów</label>
      <select id="f-source" name="source" class="{{ $select }}">
        <option value="">Dowolne</option>
        @foreach (StrategySource::cases() as $option)
          <option value="{{ $option->value }}" @selected($filters->source === $option)>{{ $option->label() }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label for="f-intent" class="block text-xs font-medium text-slate-600">Intencja (fraza główna)</label>
      <select id="f-intent" name="intent" class="{{ $select }}">
        <option value="">Dowolna</option>
        @foreach (DiscoveredKeyword::INTENTS as $intent)
          <option value="{{ $intent }}" @selected($filters->intent === $intent)>{{ CandidateRow::intentLabel($intent) }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label for="f-target" class="block text-xs font-medium text-slate-600">Strona docelowa</label>
      <select id="f-target" name="target" class="{{ $select }}">
        <option value="">Dowolna</option>
        @foreach (TargetState::cases() as $option)
          <option value="{{ $option->value }}" @selected($filters->target === $option)>{{ $option->label() }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label for="f-serp" class="block text-xs font-medium text-slate-600">Stan SERP</label>
      <select id="f-serp" name="serp" class="{{ $select }}">
        <option value="">Dowolny</option>
        @foreach ($serpOptions as $value => $label)
          <option value="{{ $value }}" @selected($filters->serp === $value)>{{ $label }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label for="f-volume" class="block text-xs font-medium text-slate-600">Min. popyt</label>
      <input id="f-volume" name="min_volume" type="number" min="0" value="{{ $filters->minVolume }}" class="{{ $select }}" title="Popyt tematu = suma znanych wolumenów fraz">
    </div>
    <div>
      <label for="f-kd" class="block text-xs font-medium text-slate-600">Maks. trudność SEO</label>
      <input id="f-kd" name="max_kd" type="number" min="0" max="100" value="{{ $filters->maxDifficulty }}" class="{{ $select }}" title="Trudność SEO frazy głównej; temat bez KD nie spełnia filtru">
    </div>
    <div class="col-span-2 flex flex-wrap items-end gap-4 md:col-span-4 xl:col-span-6">
      <div>
        <label for="f-state" class="block text-xs font-medium text-slate-600">Tematy</label>
        <select id="f-state" name="state" class="mt-1 rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
          <option value="" @selected($filters->state === 'active')>Aktywne</option>
          <option value="inactive" @selected($filters->state === 'inactive')>Nieaktywne</option>
          <option value="all" @selected($filters->state === 'all')>Wszystkie</option>
        </select>
      </div>
      <label class="inline-flex items-center gap-2 text-sm text-slate-700">
        <input type="checkbox" name="include_monitor" value="1" @checked($filters->includeMonitor) class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
        Pokaż monitorowanie
      </label>
      <label class="inline-flex items-center gap-2 text-sm text-slate-700">
        <input type="checkbox" name="changed" value="1" @checked($filters->changed) class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
        Tylko zmiany po decyzji
      </label>
      <input type="hidden" name="sort" value="{{ $filters->sort === 'priority' ? '' : $filters->sort }}">
      <x-panel.button type="submit">Filtruj</x-panel.button>
      <x-panel.button variant="secondary" :href="$base">Wyczyść</x-panel.button>
    </div>
  </form>

  <div class="mt-6">
    @if ($page['total'] === 0)
      @if ($filters->isDefault())
        <x-panel.empty-state title="Brak otwartych tematów wymagających działania"
          :description="$state['refreshed_at'] === null
            ? 'Strategia nie była jeszcze przeliczona — tematy powstaną po pierwszym przeliczeniu (bez kosztów).'
            : 'Wszystkie aktywne tematy są monitorowane, zrealizowane albo odrzucone. Zmień filtr statusu albo pokaż monitorowanie.'">
          <x-panel.button variant="secondary" :href="$url(['include_monitor' => '1', 'status' => 'all'])">Pokaż wszystkie tematy</x-panel.button>
        </x-panel.empty-state>
      @else
        <x-panel.empty-state title="Brak tematów dla wybranych filtrów" description="Zmień albo wyczyść filtry. Brak danych (np. trudności SEO) nie spełnia filtrów liczbowych — to nie jest wartość 0." />
      @endif
    @else
      <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-3 py-2"><a href="{{ $sortUrl('label') }}" class="hover:text-slate-900">Temat {{ $filters->sort === 'label' ? ($filters->direction === 'asc' ? '↑' : '↓') : '' }}</a></th>
              <th class="px-2 py-2">Działanie</th>
              <th class="px-2 py-2 text-center" title="Priorytet Strategii 0–100 — kolejność pracy, nie prognoza ruchu"><a href="{{ $sortUrl('priority') }}" class="hover:text-slate-900">Priorytet {{ $filters->sort === 'priority' ? ($filters->direction === 'asc' ? '↑' : '↓') : '' }}</a></th>
              <th class="px-2 py-2"><a href="{{ $sortUrl('confidence') }}" class="hover:text-slate-900">Pewność {{ $filters->sort === 'confidence' ? ($filters->direction === 'asc' ? '↑' : '↓') : '' }}</a></th>
              <th class="px-2 py-2">Strona docelowa</th>
              <th class="px-2 py-2 text-right" title="Wynik naszego pomiaru SERP frazy odniesienia (rank_group) — tylko ze świeżego pomiaru ≤ 30 dni; obok świeżość pomiaru"><a href="{{ $sortUrl('serp_rank') }}" class="hover:text-slate-900">Pozycja SERP {{ $filters->sort === 'serp_rank' ? ($filters->direction === 'asc' ? '↑' : '↓') : '' }}</a></th>
              <th class="px-2 py-2 text-right" title="Średnia pozycja z Google Search Console (ważona wyświetleniami) — nie dokładna pozycja SERP"><a href="{{ $sortUrl('gsc_position') }}" class="hover:text-slate-900">Śr. poz. (GSC) {{ $filters->sort === 'gsc_position' ? ($filters->direction === 'asc' ? '↑' : '↓') : '' }}</a></th>
              <th class="px-2 py-2 text-right" title="Suma znanych wolumenów fraz tematu (średnia miesięczna liczba wyszukiwań)"><a href="{{ $sortUrl('demand') }}" class="hover:text-slate-900">Popyt {{ $filters->sort === 'demand' ? ($filters->direction === 'asc' ? '↑' : '↓') : '' }}</a></th>
              <th class="px-2 py-2">Źródła</th>
              <th class="px-3 py-2">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach ($page['rows'] as $topic)
              @php($freshness = SerpFreshness::of($topic->serpCheckedAt, $now))
              @php($targetPath = $topic->targetUrl === null ? null : ((string) (parse_url($topic->targetUrl, PHP_URL_PATH) ?: '/')))
              <tr class="align-top hover:bg-slate-50">
                <td class="min-w-40 max-w-[15rem] px-3 py-2">
                  <a href="{{ StrategyTopicsController::topicUrl($project->publicId, $topic->publicId) }}" class="break-words font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $topic->label ?? '—' }}</a>
                  <span class="block text-xs text-slate-500">{{ Format::number($topic->keywordsCount) }} {{ Text::plural($topic->keywordsCount, 'fraza', 'frazy', 'fraz') }}{{ $topic->active ? '' : ' · nieaktywny' }}</span>
                  @if ($topic->needsAttention())
                    <span class="mt-1 inline-flex rounded bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20" title="Dowody zmieniły działanie albo stronę docelową po decyzji">zmiana po decyzji</span>
                  @endif
                </td>
                <td class="w-44 px-2 py-2">
                  <x-panel.strategy-action :action="$topic->action" />
                  <span class="mt-1 block text-xs leading-snug text-slate-500">{{ StrategyLabels::reason($topic->actionReason) }}</span>
                </td>
                <td class="px-2 py-2 text-center">
                  @if ($topic->priority === null)
                    <span class="text-slate-400">—</span>
                  @else
                    <x-panel.score :value="$topic->priority" size="sm" />
                  @endif
                </td>
                <td class="px-2 py-2"><x-panel.strategy-confidence :level="$topic->confidenceLevel" :points="$topic->confidence" compact /></td>
                <td class="max-w-[12rem] px-2 py-2">
                  <x-panel.target-state :state="$topic->targetState" :manual="$topic->manualTargetUrl !== null || $topic->manualNoPage" />
                  @if ($targetPath !== null)
                    @if ($safeUrl($topic->targetUrl))
                      <a href="{{ $topic->targetUrl }}" target="_blank" rel="noopener noreferrer" class="mt-1 block truncate text-xs text-brand-600 hover:underline" title="{{ $topic->targetUrl }}">{{ $targetPath }}</a>
                    @else
                      <span class="mt-1 block truncate text-xs text-slate-500" title="{{ $topic->targetUrl }}">{{ $targetPath }}</span>
                    @endif
                  @endif
                </td>
                <td class="whitespace-nowrap px-2 py-2 text-right">
                  @if (in_array($topic->serpBand, $freshBands, true))
                    <x-panel.serp-rank :rank="$topic->serpRank" :found="$topic->serpRank !== null" />
                  @else
                    <span class="text-slate-400" title="Pozycja SERP tylko ze świeżego pomiaru (≤ 30 dni)">—</span>
                  @endif
                  <span class="mt-1 block"><x-panel.serp-freshness :freshness="$freshness" :checked-at="$topic->serpCheckedAt" /></span>
                </td>
                <td class="px-2 py-2 text-right tabular-nums">{{ Format::position($topic->gscPosition) }}</td>
                <td class="px-2 py-2 text-right tabular-nums">{{ Format::number($topic->demand) }}</td>
                <td class="min-w-[8rem] max-w-[10rem] px-2 py-2 text-xs leading-snug text-slate-600">{{ $topic->sourceCodes() === [] ? '—' : implode(', ', array_map(static fn (string $code): string => StrategyLabels::source($code), $topic->sourceCodes())) }}</td>
                <td class="px-3 py-2"><x-panel.topic-status :status="$topic->status" /></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <nav class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm" aria-label="Strony">
        <span class="text-slate-600">{{ Format::number($page['total']) }} {{ Text::plural($page['total'], 'temat', 'tematy', 'tematów') }}@if ($pages > 1) · strona {{ $filters->page }} z {{ $pages }}@endif</span>
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
      @if (! $filters->includeMonitor && $filters->action === null)
        <p class="mt-2 text-xs text-slate-500">Monitorowane tematy (stabilny TOP 3) są ukryte — <a href="{{ $url(['include_monitor' => '1']) }}" class="text-brand-600 hover:underline">pokaż je</a>.</p>
      @endif
    @endif
  </div>

  @include('panel.strategy.partials.disclaimer')
@endsection
