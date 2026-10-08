@extends('panel.layouts.app', ['active' => 'strategy'])

@section('title', 'SERP Intelligence · Strategia · ' . $project->name)

@php
  use App\Http\Controllers\Panel\StrategySerpController;
  use App\Http\Controllers\Panel\StrategyTopicsController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use App\Panel\StrategyLabels;
  use OsfSeo\Discovery\CandidateRow;
  use OsfSeo\Opportunities\Text;
  use OsfSeo\Strategy\Serp\SerpConfidence;

  $base = PanelUrl::project($project->publicId, 'strategy/serp');
  $url = fn (array $query = []) => $base . (($built = http_build_query(array_filter($query, static fn ($value): bool => $value !== '' && $value !== null && $value !== 1))) !== '' ? '?' . $built : '');
  $counts = $list['counts'];
  $pages = max(1, (int) ceil($list['total'] / $perPage));
  $filters = [
    'all' => ['Wszystkie', $counts['all']],
    'fresh' => ['Świeży pomiar (≤ 30 dni)', $counts['fresh']],
    'stale' => ['Nieaktualny (31–90 dni)', $counts['stale']],
    'missing' => ['Wymaga pomiaru (brak albo > 90 dni)', $counts['missing']],
  ];
@endphp

@section('content')
  <x-panel.page-header title="SERP Intelligence"
    :description="$project->name . ' · interpretacja zapisanych pomiarów SERP fraz Strategii (bez żadnych żądań): świeżość, kompozycja wyników, kształty, obecność projektu i konkurentów'">
    @if ($canAnalyze)
      <x-slot:actions>
        <x-panel.button variant="secondary" :href="StrategySerpController::analysisUrl($project->publicId)">Analiza SERP (podgląd kosztu)</x-panel.button>
      </x-slot:actions>
    @endif
  </x-panel.page-header>

  @include('panel.strategy.partials.tabs', ['tab' => 'serp'])
  @include('panel.strategy.partials.state', ['back' => $base])

  @if (! $list['supported'])
    <x-panel.empty-state title="Rynek projektu nie jest obsługiwany" description="SERP Intelligence wymaga rynku obsługiwanego przez dostawcę danych (kraj i język projektu)." />
  @else
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <x-panel.card>
        <h2 class="text-base font-semibold text-slate-900">Pokrycie pomiarami</h2>
        <dl class="mt-2 divide-y divide-slate-100 text-sm">
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Kontekst analizy</dt><dd class="text-slate-900">{{ $list['context']?->device->label() ?? '—' }}, TOP{{ $list['context']?->depth ?? '—' }}</dd></div>
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Frazy ze świeżym pomiarem</dt><dd class="tabular-nums text-slate-900">{{ Format::number($counts['fresh']) }} / {{ Format::number($counts['all']) }}</dd></div>
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Tematy ze świeżym pomiarem</dt><dd class="tabular-nums text-slate-900">{{ Format::number($list['topics']['fresh']) }}</dd></div>
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Tematy z nieaktualnym pomiarem</dt><dd class="tabular-nums text-slate-900">{{ Format::number($list['topics']['stale']) }}</dd></div>
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Tematy bez pomiaru</dt><dd class="tabular-nums text-slate-900"><a href="{{ PanelUrl::project($project->publicId, 'strategy/topics') }}?serp=nofresh" class="text-brand-600 hover:underline">{{ Format::number($list['topics']['none']) }}</a></dd></div>
        </dl>
        <p class="mt-2 text-xs text-slate-500">Zgodny pomiar: rynek projektu, urządzenie z ustawień Pozycji, głębokość ≥ TOP20. Pozycja SERP projektu tylko z pomiaru ≤ 30 dni.</p>
      </x-panel.card>

      <x-panel.card class="lg:col-span-2">
        <h2 class="text-base font-semibold text-slate-900">Dominujące domeny w TOP10</h2>
        @if ($domains['measurements'] === 0)
          <p class="mt-2 text-sm text-slate-500">Brak świeżych pomiarów projektu (≤ 30 dni) — dominujące domeny pojawią się po pomiarach w module Pozycje albo analizie SERP.</p>
        @else
          <p class="mt-1 text-xs text-slate-500">Liczba świeżych pomiarów projektu, w których domena jest w TOP10 (z {{ Format::number($domains['measurements']) }} {{ Text::plural($domains['measurements'], 'pomiaru', 'pomiarów', 'pomiarów') }}).</p>
          <ul class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
            @foreach ($domains['domains'] as $domain)
              <li class="flex items-center gap-2">
                <span class="min-w-0 flex-1 truncate text-slate-800">{{ $domain['host'] }}</span>
                @if ($domain['project'])<span class="rounded bg-brand-700 px-1.5 py-0.5 text-xs text-white">projekt</span>@endif
                @if ($domain['competitor'] !== null)<span class="rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-800">{{ $domain['competitor'] }}</span>@endif
                <span class="tabular-nums text-slate-600">{{ Format::number($domain['count']) }}</span>
                <span class="w-12 text-right tabular-nums text-xs text-slate-500">{{ Format::percent($domain['share'], 0) }}</span>
              </li>
            @endforeach
          </ul>
        @endif
      </x-panel.card>
    </div>

    <div class="mt-6 flex flex-wrap gap-2 text-sm">
      @foreach ($filters as $value => [$label, $count])
        <a href="{{ $url(['filter' => $value === 'all' ? '' : $value]) }}" @class([
          'rounded-full px-3 py-1 ring-1 ring-inset',
          'bg-brand-700 text-white ring-brand-700' => $filter === $value,
          'bg-white text-slate-700 ring-slate-300 hover:bg-slate-50' => $filter !== $value,
        ])>{{ $label }} <span class="tabular-nums opacity-80">{{ Format::number($count) }}</span></a>
      @endforeach
    </div>

    <div class="mt-4">
      @if ($list['total'] === 0)
        <x-panel.empty-state
          :title="$counts['all'] === 0 ? 'Brak kandydatów Strategii' : 'Brak fraz w tym widoku'"
          :description="$counts['all'] === 0
            ? 'SERP Intelligence dotyczy fraz Strategii — pojawią się po przeliczeniu Strategii.'
            : ($filter === 'missing' ? 'Wszystkie frazy mają pomiar nie starszy niż 90 dni.' : 'Żadna fraza nie ma pomiaru w tym przedziale świeżości. Pomiary pochodzą z modułu Pozycje i z analizy SERP Strategii.')" />
      @else
        <form method="get" action="{{ StrategySerpController::analysisUrl($project->publicId) }}" x-data="{ selected: [] }">
          <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
              <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                <tr>
                  @if ($canAnalyze)<th class="w-8 px-3 py-2"><span class="sr-only">Zaznacz</span></th>@endif
                  <th class="px-3 py-2">Fraza</th>
                  <th class="px-3 py-2">Temat</th>
                  <th class="px-3 py-2">Pomiar</th>
                  <th class="px-3 py-2 text-right" title="Tylko ze świeżego pomiaru (≤ 30 dni) — nie średnia pozycja GSC">Pozycja SERP</th>
                  <th class="px-3 py-2" title="Heurystyka z adresów i prezentacji wyników">Kształt TOP10</th>
                  <th class="px-3 py-2" title="Sygnał z kształtu strony wyników — osobno od intencji dostawcy">Sygnał intencji (SERP)</th>
                  <th class="px-3 py-2 text-right" title="Skonfigurowani konkurenci w TOP10 / TOP20">Konk.</th>
                  <th class="px-3 py-2 text-right">Wolumen</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                @foreach ($list['rows'] as $row)
                  @php($profile = $row['intel']['profile'] ?? null)
                  @php($own = $row['intel']['project'] ?? null)
                  @php($rivals = $row['intel']['competitors'] ?? null)
                  <tr class="align-top hover:bg-slate-50">
                    @if ($canAnalyze)
                      <td class="px-3 py-2"><input type="checkbox" name="ids[]" value="{{ $row['id'] }}" x-model="selected" aria-label="Zaznacz {{ $row['keyword'] }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"></td>
                    @endif
                    <td class="max-w-xs px-3 py-2">
                      <a href="{{ StrategySerpController::keywordUrl($project->publicId, $row['id']) }}" class="break-words font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $row['keyword'] }}</a>
                      @if (CandidateRow::intentLabel($row['intent']) !== null)
                        <span class="ml-1 inline-flex rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600" title="Intencja według DataForSEO">{{ CandidateRow::intentLabel($row['intent']) }}</span>
                      @endif
                    </td>
                    <td class="max-w-[14rem] px-3 py-2 text-xs">
                      @if ($row['topic'] === null)
                        <span class="text-slate-400">—</span>
                      @else
                        <a href="{{ StrategyTopicsController::topicUrl($project->publicId, $row['topic']['id']) }}" class="block truncate text-brand-600 hover:underline">{{ $row['topic']['label'] }}</a>
                        <span class="text-slate-500">{{ $row['topic']['leader'] ? 'fraza główna · ' : '' }}{{ StrategyLabels::action($row['topic']['action']) }}</span>
                      @endif
                    </td>
                    <td class="px-3 py-2">
                      <x-panel.serp-freshness :freshness="$row['freshness']" :checked-at="$row['checked_at']" />
                      @if ($row['checked_at'] !== null)
                        <span class="mt-1 block text-xs text-slate-500">{{ Format::datetime($row['checked_at']) }}</span>
                      @endif
                    </td>
                    <td class="px-3 py-2 text-right">
                      @if ($own === null)
                        <span class="text-slate-400">—</span>
                      @else
                        <x-panel.serp-rank :rank="$own['rank']" :found="$own['found']" :featured="(bool) ($own['featured'] ?? false)" />
                      @endif
                    </td>
                    <td class="px-3 py-2 text-xs text-slate-700">
                      @if ($profile === null)
                        <span class="text-slate-400">—</span>
                      @else
                        {{ StrategyLabels::shape($profile['shape']) }}
                        <span class="block text-slate-500">pewność {{ SerpConfidence::label($profile['shape_confidence']) }}</span>
                      @endif
                    </td>
                    <td class="px-3 py-2 text-xs text-slate-700">
                      @if ($profile === null)
                        <span class="text-slate-400">—</span>
                      @else
                        {{ StrategyLabels::serpIntent($profile['intent_signal']) }}
                        <span class="block text-slate-500">pewność {{ SerpConfidence::label($profile['intent_confidence']) }}</span>
                      @endif
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums text-xs">{{ $rivals === null ? '—' : $rivals['top10'] . ' / ' . $rivals['top20'] }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ Format::number($row['volume']) }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
          @if ($canAnalyze)
            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm" x-show="selected.length > 0" x-cloak>
              <span class="text-slate-600">Zaznaczone: <span class="font-medium" x-text="selected.length"></span></span>
              <x-panel.button type="submit" variant="secondary">Podgląd analizy SERP zaznaczonych</x-panel.button>
              <span class="text-xs text-slate-500">Podgląd jest bezpłatny — nic nie zostanie wysłane bez potwierdzenia.</span>
            </div>
          @endif
        </form>

        <nav class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm" aria-label="Strony">
          <span class="text-slate-600">{{ Format::number($list['total']) }} {{ Text::plural($list['total'], 'fraza', 'frazy', 'fraz') }}@if ($pages > 1) · strona {{ $pageNumber }} z {{ $pages }}@endif</span>
          @if ($pages > 1)
            <div class="flex gap-2">
              @if ($pageNumber > 1)
                <x-panel.button variant="secondary" :href="$url(['filter' => $filter === 'all' ? '' : $filter, 'page' => $pageNumber - 1])">Poprzednia</x-panel.button>
              @endif
              @if ($pageNumber < $pages)
                <x-panel.button variant="secondary" :href="$url(['filter' => $filter === 'all' ? '' : $filter, 'page' => $pageNumber + 1])">Następna</x-panel.button>
              @endif
            </div>
          @endif
        </nav>
      @endif
    </div>
  @endif

  @include('panel.strategy.partials.disclaimer')
@endsection
