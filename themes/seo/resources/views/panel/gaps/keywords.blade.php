@extends('panel.layouts.app', ['active' => 'gaps'])

@section('title', 'Luki fraz · ' . $project->name)

@php
  use App\Http\Controllers\Panel\GapContentController;
  use App\Http\Controllers\Panel\GapKeywordsController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Discovery\CandidateRow;
  use OsfSeo\Discovery\DiscoveredKeyword;
  use OsfSeo\Gap\ContentGap;
  use OsfSeo\Gap\GapFilters;
  use OsfSeo\Gap\GapStatus;
  use OsfSeo\Gap\GapType;
  use OsfSeo\Gap\ProjectVisibility;
  use OsfSeo\Opportunities\Text;

  $base = PanelUrl::project($project->publicId, 'gaps/keywords');
  $url = fn (array $changes = []) => $base . (($query = http_build_query($filters->toQuery($changes + ['page' => '']))) !== '' ? '?' . $query : '');
  $pageUrl = fn (int $number) => $base . (($query = http_build_query($filters->withPage($number)->toQuery())) !== '' ? '?' . $query : '');
  $sortUrl = function (string $sort) use ($filters, $url): string {
    $ascending = in_array($sort, GapFilters::ASCENDING, true);
    $direction = $filters->sort === $sort ? ($filters->direction === 'asc' ? 'desc' : 'asc') : ($ascending ? 'asc' : 'desc');

    return $url(['sort' => $sort, 'dir' => $direction]);
  };
  $pages = max(1, (int) ceil($page['total'] / $filters->perPage));
  $returnQuery = http_build_query($filters->toQuery());
  $reasons = [
    'brand_own' => 'marka projektu',
    'brand_competitor' => 'marka konkurenta',
    'excluded' => 'wykluczenie',
    'not_included' => 'bez słów tematycznych',
    'foreign_language' => 'inny język',
    'low_volume' => 'niski wolumen',
    'high_difficulty' => 'wysoka trudność SEO',
    'inactive' => 'nieaktualna',
  ];
  $types = $counts['types'];
@endphp

@section('content')
  <x-panel.page-header title="Luki fraz"
    :description="$project->name . ' · frazy, na które konkurenci rankują w TOP' . $settings->competitorMaxRank . ' (DataForSEO Labs), z widocznością projektu i priorytetem'" />

  @include('panel.gaps.partials.tabs', ['tab' => 'keywords'])

  <div class="flex flex-wrap gap-2 text-sm">
    @foreach ([
      ['gaps', 'Do sprawdzenia', ($types['missing'] ?? 0) + ($types['weak'] ?? 0) + ($types['unknown'] ?? 0)],
      ['missing', GapType::Missing->label(), $types['missing'] ?? 0],
      ['weak', GapType::Weak->label(), $types['weak'] ?? 0],
      ['unknown', GapType::Unknown->label(), $types['unknown'] ?? 0],
      ['competitive', GapType::Competitive->label(), $types['competitive'] ?? 0],
      ['stronger', GapType::Stronger->label(), $types['stronger'] ?? 0],
      ['all', 'Wszystkie', array_sum($types)],
    ] as [$value, $label, $count])
      <a href="{{ $url(['type' => $value === 'gaps' ? '' : $value, 'filtered' => '']) }}" @class([
        'rounded-full px-3 py-1 ring-1 ring-inset',
        'bg-brand-700 text-white ring-brand-700' => $filters->type === $value && ! $filters->filtered,
        'bg-white text-slate-700 ring-slate-300 hover:bg-slate-50' => $filters->type !== $value || $filters->filtered,
      ])>{{ $label }} <span class="tabular-nums opacity-80">{{ Format::number($count) }}</span></a>
    @endforeach
    @if ($counts['filtered'] > 0)
      <a href="{{ $url(['filtered' => $filters->filtered ? '' : '1', 'type' => 'all']) }}" @class([
        'rounded-full px-3 py-1 ring-1 ring-inset',
        'bg-amber-600 text-white ring-amber-600' => $filters->filtered,
        'bg-white text-amber-800 ring-amber-300 hover:bg-amber-50' => ! $filters->filtered,
      ])>Odfiltrowane <span class="tabular-nums opacity-80">{{ Format::number($counts['filtered']) }}</span></a>
    @endif
  </div>

  <form method="get" action="{{ $base }}" class="mt-4 grid grid-cols-2 gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-4 xl:grid-cols-8">
    <input type="hidden" name="type" value="{{ $filters->type === 'gaps' ? '' : $filters->type }}">
    @if ($filters->filtered)
      <input type="hidden" name="filtered" value="1">
    @endif
    <div class="col-span-2">
      <label for="f-q" class="block text-xs font-medium text-slate-600">Szukaj frazy</label>
      <input id="f-q" name="q" type="search" value="{{ $filters->q }}" placeholder="np. sklep internetowy" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
    </div>
    <div>
      <label for="f-competitor" class="block text-xs font-medium text-slate-600">Konkurent</label>
      <select id="f-competitor" name="competitor" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <option value="">Wszyscy</option>
        @foreach ($competitors as $competitor)
          <option value="{{ $competitor->publicId }}" @selected($filters->competitor === $competitor->publicId)>{{ $competitor->name }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label for="f-visibility" class="block text-xs font-medium text-slate-600">Widoczność projektu</label>
      <select id="f-visibility" name="visibility" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <option value="">Dowolna</option>
        @foreach (ProjectVisibility::cases() as $option)
          <option value="{{ $option->value }}" @selected($filters->visibility === $option->value)>{{ $option->label() }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label for="f-content" class="block text-xs font-medium text-slate-600">Luka treści</label>
      <select id="f-content" name="content" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <option value="">Dowolna</option>
        @foreach (ContentGap::cases() as $option)
          <option value="{{ $option->value }}" @selected($filters->content === $option->value)>{{ $option->label() }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label for="f-status" class="block text-xs font-medium text-slate-600">Status</label>
      <select id="f-status" name="status" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <option value="" @selected($filters->status === '')>Bez odrzuconych</option>
        <option value="all" @selected($filters->status === 'all')>Wszystkie</option>
        @foreach (GapStatus::cases() as $option)
          <option value="{{ $option->value }}" @selected($filters->status === $option->value)>{{ $option->label() }}</option>
        @endforeach
      </select>
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
    <div class="col-span-2 flex flex-wrap items-end gap-3 md:col-span-4 xl:col-span-6">
      <input type="hidden" name="sort" value="{{ $filters->sort === 'priority' ? '' : $filters->sort }}">
      <x-panel.button type="submit">Filtruj</x-panel.button>
      <x-panel.button variant="secondary" :href="$base">Wyczyść</x-panel.button>
    </div>
  </form>

  @if ($filters->filtered && $counts['filter_reasons'] !== [])
    <p class="mt-3 text-xs text-slate-600">
      Frazy odfiltrowane nie są lukami (zostają zapisane, widać je tylko tutaj):
      @foreach ($counts['filter_reasons'] as $reason => $count)
        {{ $reasons[$reason] ?? $reason }} {{ Format::number($count) }}@if (! $loop->last), @endif
      @endforeach
      · warianty marki, słowa tematyczne i progi zmienisz w <a href="{{ PanelUrl::project($project->publicId, 'gaps/settings') }}" class="text-brand-600 hover:underline">ustawieniach</a>.
    </p>
  @endif

  <div class="mt-6">
    @if ($page['total'] === 0)
      <x-panel.empty-state title="Brak fraz dla wybranych filtrów"
        description="Zmień filtry albo zaimportuj frazy konkurentów. Luki pojawiają się dla fraz, na które aktywni konkurenci rankują w progu znaczącej pozycji (ustawienia)." />
    @else
      <form method="post" action="{{ PanelUrl::project($project->publicId, 'gaps/bulk') }}" x-data="{ selected: [] }">
        <x-panel.nonce />
        <input type="hidden" name="return" value="{{ $returnQuery }}">
        <input type="hidden" name="kind" value="keyword">
        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
          <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <tr>
                @if ($canManage || $canTrack)
                  <th class="w-8 px-3 py-2"><span class="sr-only">Zaznacz</span></th>
                @endif
                @foreach ([
                  ['keyword', 'Fraza', 'text-left', null],
                  ['priority', 'Priorytet', 'text-center', 'Priorytet luki 0–100 — sygnał do sprawdzenia, nie prognoza ruchu'],
                  ['volume', 'Wolumen', 'text-right', 'Średnia miesięczna liczba wyszukiwań'],
                  ['difficulty', 'Trudność SEO', 'text-right', 'Keyword Difficulty (DataForSEO) — nie konkurencja Ads'],
                  ['competitor_rank', 'Konkurent (Labs)', 'text-left', 'Najlepszy konkurent i jego pozycja w bazie DataForSEO Labs (nie nasz pomiar SERP)'],
                  ['competitors', 'Konk.', 'text-right', 'Liczba aktywnych konkurentów rankujących na frazę'],
                ] as [$sort, $label, $align, $title])
                  <th class="px-3 py-2 {{ $align }}" @if ($title) title="{{ $title }}" @endif>
                    <a href="{{ $sortUrl($sort) }}" class="hover:text-slate-900">{{ $label }}@if ($filters->sort === $sort) {{ $filters->direction === 'asc' ? '↑' : '↓' }}@endif</a>
                  </th>
                @endforeach
                <th class="px-3 py-2">Luka</th>
                <th class="px-3 py-2" title="Pomiar SERP → średnia pozycja GSC → punkt odniesienia Labs">Projekt</th>
                <th class="px-3 py-2">Luka treści</th>
                <th class="px-3 py-2">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              @foreach ($page['rows'] as $row)
                <tr class="hover:bg-slate-50">
                  @if ($canManage || $canTrack)
                    <td class="px-3 py-2"><input type="checkbox" name="ids[]" value="{{ $row['public_id'] }}" x-model="selected" aria-label="Zaznacz {{ $row['keyword'] }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"></td>
                  @endif
                  <td class="min-w-48 max-w-xs px-3 py-2">
                    <a href="{{ GapKeywordsController::keywordUrl($project->publicId, $row['public_id']) }}" class="break-words font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $row['keyword'] }}</a>
                    @if (CandidateRow::intentLabel($row['intent']) !== null)
                      <span class="ml-1 inline-flex rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600" title="Intencja wyszukiwania według DataForSEO">{{ CandidateRow::intentLabel($row['intent']) }}</span>
                    @endif
                    @if ($row['other_language'] === '1')
                      <span class="ml-1 inline-flex rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600" title="DataForSEO rozpoznało inny język niż język rynku — tylko informacja, fraza nie jest przez to odfiltrowana">inny język</span>
                    @endif
                    @if ($row['filter_reason'] !== null)
                      <span class="ml-1 inline-flex rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-800">{{ $reasons[$row['filter_reason']] ?? $row['filter_reason'] }}</span>
                    @endif
                    @if ($row['cluster_public_id'] !== null)
                      <a href="{{ GapContentController::clusterUrl($project->publicId, $row['cluster_public_id']) }}" class="block truncate text-xs text-slate-500 hover:text-brand-700" title="Grupa fraz">grupa: {{ $row['cluster_label'] }}</a>
                    @endif
                  </td>
                  <td class="px-3 py-2 text-center"><x-panel.score :value="$row['priority']" size="sm" /></td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ Format::number($row['search_volume'] === null ? null : (int) $row['search_volume']) }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ Format::number($row['keyword_difficulty'] === null ? null : (int) $row['keyword_difficulty']) }}</td>
                  <td class="px-3 py-2 text-xs">
                    <span class="text-slate-700">{{ $row['competitor_name'] ?? '—' }}</span>
                    @if ($row['best_competitor_rank'] !== null)
                      <span class="tabular-nums text-slate-500">#{{ $row['best_competitor_rank'] }}</span>
                    @endif
                  </td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ $row['competitors_count'] }}</td>
                  <td class="px-3 py-2"><x-panel.gap-type :value="$row['gap_type']" /></td>
                  <td class="px-3 py-2"><x-panel.project-visibility :value="$row['visibility']" :source="$row['visibility_source']" :position="$row['project_position']" :sporadic="$row['sporadic'] === '1'" /></td>
                  <td class="px-3 py-2">@if ($row['content_gap'] !== null)<x-panel.content-gap :value="$row['content_gap']" />@else<span class="text-slate-400">—</span>@endif</td>
                  <td class="px-3 py-2"><x-panel.candidate-status :status="$row['status']" /></td>
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
                @foreach (GapStatus::cases() as $option)
                  <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
              </select>
              <x-panel.button type="submit" variant="secondary">Zmień status</x-panel.button>
            @endif
            @if ($canTrack)
              {{-- Ten sam wybór trafia do modułu Pozycje (dodanie do monitorowania — bez kosztów i bez żądań do API). --}}
              <input type="hidden" name="source" value="gap">
              <input type="hidden" name="back" value="{{ $pageUrl($filters->page) }}">
              <x-panel.button type="submit" variant="secondary" :formaction="PanelUrl::project($project->publicId, 'positions/keywords')">Monitoruj pozycję</x-panel.button>
            @endif
          </div>
        @endif
      </form>

      @if ($pages > 1)
        <nav class="mt-4 flex items-center justify-between text-sm" aria-label="Strony">
          <span class="text-slate-600">{{ Format::number($page['total']) }} {{ Text::plural($page['total'], 'fraza', 'frazy', 'fraz') }} · strona {{ $filters->page }} z {{ $pages }}</span>
          <div class="flex gap-2">
            @if ($filters->page > 1)
              <x-panel.button variant="secondary" :href="$pageUrl($filters->page - 1)">Poprzednia</x-panel.button>
            @endif
            @if ($filters->page < $pages)
              <x-panel.button variant="secondary" :href="$pageUrl($filters->page + 1)">Następna</x-panel.button>
            @endif
          </div>
        </nav>
      @else
        <p class="mt-3 text-sm text-slate-600">{{ Format::number($page['total']) }} {{ Text::plural($page['total'], 'fraza', 'frazy', 'fraz') }}</p>
      @endif
    @endif
  </div>

  @include('panel.gaps.partials.disclaimer')
@endsection
