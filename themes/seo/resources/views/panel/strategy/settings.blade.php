@extends('panel.layouts.app', ['active' => 'strategy'])

@section('title', 'Ustawienia Strategii · ' . $project->name)

@php
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use App\Panel\StrategyRefreshView;

  $base = PanelUrl::project($project->publicId, 'strategy');
  $job = $state['job'];
  $jobStatuses = ['idle' => 'bezczynne', 'queued' => 'w kolejce', 'running' => 'trwa', 'failed' => 'wstrzymane po błędach'];
  $jobSources = ['manual' => 'zlecenie z panelu', 'auto' => 'zmiana danych modułów', 'cli' => 'WP-CLI'];
  $limits = [
    'max_keywords' => ['Limit kandydatów Strategii', 'OSF_SEO_STRATEGY_MAX_KEYWORDS — nadmiar jest liczony i pokazywany, nie pomijany po cichu'],
    'serp_max_per_run' => ['Nowe pomiary SERP na jedną analizę', 'OSF_SEO_STRATEGY_SERP_MAX_PER_RUN — bez cichego obcinania wyboru'],
    'window_days' => ['Okno GSC (dni)', 'OSF_SEO_STRATEGY_WINDOW_DAYS'],
    'gsc_min_impressions' => ['Min. wyświetleń frazy GSC', 'OSF_SEO_STRATEGY_GSC_MIN_IMPRESSIONS'],
    'gsc_max_position' => ['Maks. średnia pozycja (GSC) kandydata', 'OSF_SEO_STRATEGY_GSC_MAX_POSITION'],
    'discovery_min_priority' => ['Min. priorytet Nowych fraz', 'OSF_SEO_STRATEGY_DISCOVERY_MIN_PRIORITY'],
    'gap_min_priority' => ['Min. priorytet luki fraz', 'OSF_SEO_STRATEGY_GAP_MIN_PRIORITY'],
  ];
@endphp

@section('content')
  <x-panel.page-header title="Ustawienia Strategii"
    :description="$project->name . ' · przeliczenie, wpisy ręczne i limity (zmiana limitów — stałe w wp-config.php)'" />

  @include('panel.strategy.partials.tabs', ['tab' => 'settings'])
  @include('panel.strategy.partials.state', ['back' => $base . '/settings'])

  <div class="mt-4 grid grid-cols-1 gap-6 lg:grid-cols-2">
    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Przeliczenie</h2>
      <dl class="mt-2 divide-y divide-slate-100 text-sm">
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Rynek</dt><dd class="text-slate-900">{{ $state['market'] ?? '—' }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Okno GSC</dt><dd class="text-slate-900">{{ $state['gsc_window'] === null ? '—' : Format::date($state['gsc_window'][0]) . ' – ' . Format::date($state['gsc_window'][1]) }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Ostatnie przeliczenie</dt><dd class="text-slate-900">{{ Format::datetime($state['refreshed_at']) }}@if ($state['refresh_ms'] !== null) <span class="text-xs text-slate-500">({{ Format::number($state['refresh_ms']) }} ms)</span>@endif</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Stan</dt><dd class="text-slate-900">{{ StrategyRefreshView::label($job['phase']) }}</dd></div>
        <div class="flex justify-between gap-4 py-2">
          <dt class="text-slate-500">Zadanie w tle</dt>
          <dd class="text-right text-slate-900">
            {{ $jobStatuses[$job['status']] ?? $job['status'] }}
            <span class="block text-xs text-slate-500">źródło: {{ $jobSources[$job['source']] ?? '—' }} · próby {{ $job['attempts'] }} z {{ $job['max_attempts'] }}@if ($job['due_at'] !== null) · termin {{ Format::datetime($job['due_at']) }}@endif</span>
          </dd>
        </div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Zlecone z panelu</dt><dd class="text-slate-900">{{ Format::datetime($state['requested_at']) }}</dd></div>
        <div class="flex justify-between gap-4 py-2">
          <dt class="text-slate-500">Ostatni błąd</dt>
          <dd class="text-right text-slate-900">
            @if ($job['last_error'] === null)
              —
            @else
              {{ StrategyRefreshView::error($job['last_error']) }}
              <span class="block text-xs text-slate-500">{{ Format::datetime($job['last_error_at']) }} · kod {{ $job['last_error'] }} · szczegóły w logu pluginu</span>
            @endif
          </dd>
        </div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Blokada przeliczenia</dt><dd class="text-slate-900">{{ $state['running'] ? 'trzymana — przeliczenie trwa' : 'wolna' }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Ostatni krok w tle</dt><dd class="text-slate-900">{{ $job['worker_heartbeat'] === null ? 'jeszcze nie działał' : Format::datetime($job['worker_heartbeat']) }}</dd></div>
        @if ($job['timings'] !== null)
          <div class="flex justify-between gap-4 py-2">
            <dt class="text-slate-500">Czasy faz (ms)</dt>
            <dd class="text-right text-xs tabular-nums text-slate-900">klucz {{ Format::number($job['timings']['key'] ?? null) }} · zbieranie {{ Format::number($job['timings']['collect'] ?? null) }} · dowody {{ Format::number($job['timings']['evidence'] ?? null) }} · zapis {{ Format::number($job['timings']['save'] ?? null) }} · tematy {{ Format::number($job['timings']['topics'] ?? null) }}</dd>
          </div>
        @endif
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Kandydaci aktywni / ręczni</dt><dd class="tabular-nums text-slate-900">{{ Format::number($state['candidates']['active']) }} / {{ Format::number($state['candidates']['manual']) }}</dd></div>
      </dl>
      <p class="mt-3 text-xs text-slate-500">
        Przeliczenie jest lokalne (bez kosztów i bez żądań do API) i nigdy nie zmienia statusów pracy. Panel tylko zapisuje zadanie — wykonuje je krok w tle
        po kolejce synchronizacji (WP-Cron albo cron systemowy <code class="rounded bg-slate-100 px-1">wp osf-seo sync:run</code> co minutę), automatycznie także po zmianie
        danych modułów, gdy import się zakończy. Diagnostyka kolejki: <code class="rounded bg-slate-100 px-1">wp osf-seo strategy:queue</code>; ręcznie:
        <code class="rounded bg-slate-100 px-1">wp osf-seo strategy:refresh --project={{ $project->publicId }}</code>.
      </p>
    </x-panel.card>

    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Limity (tylko odczyt)</h2>
      <dl class="mt-2 divide-y divide-slate-100 text-sm">
        @foreach ($limits as $key => [$label, $hint])
          <div class="flex justify-between gap-4 py-2">
            <dt class="text-slate-500">{{ $label }}<span class="block text-xs text-slate-400">{{ $hint }}</span></dt>
            <dd class="tabular-nums text-slate-900">{{ Format::number($config[$key] ?? null) }}</dd>
          </div>
        @endforeach
      </dl>
    </x-panel.card>
  </div>

  <x-panel.card class="mt-6">
    <h2 class="text-base font-semibold text-slate-900">Frazy dodane ręcznie</h2>
    <p class="mt-1 text-sm text-slate-600">Wpis ręczny dodaje frazę do kandydatów Strategii bez żadnego żądania do API — fakty (GSC, rynek, SERP, luki) i temat uzupełni przeliczenie.</p>
    <form method="post" action="{{ $base }}/keywords" class="mt-3">
      <x-panel.nonce />
      <label for="manual-keywords" class="block text-xs font-medium text-slate-600">Frazy (po jednej w wierszu albo po przecinku)</label>
      <textarea id="manual-keywords" name="keywords" rows="4" maxlength="20000" @class(['mt-1 block w-full rounded-md text-sm shadow-sm', 'border-red-400' => isset($errors['keywords']), 'border-slate-300 focus:border-brand-500 focus:ring-brand-500' => ! isset($errors['keywords'])])>{{ $old['keywords'] ?? '' }}</textarea>
      @if (isset($errors['keywords']))
        <p class="mt-1 text-sm text-red-700">{{ $errors['keywords'] }}</p>
      @endif
      <x-panel.button type="submit" class="mt-2">Dodaj do Strategii</x-panel.button>
    </form>

    @if ($manual === [])
      <p class="mt-4 text-sm text-slate-500">Brak wpisów ręcznych.</p>
    @else
      <form method="post" action="{{ $base }}/keywords/remove" class="mt-6" x-data="{ selected: [] }">
        <x-panel.nonce />
        <ul class="divide-y divide-slate-100 text-sm">
          @foreach ($manual as $row)
            <li class="flex flex-wrap items-center gap-3 py-2">
              <input type="checkbox" name="ids[]" value="{{ $row->publicId }}" x-model="selected" aria-label="Zaznacz {{ $row->keyword }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
              <a href="{{ \App\Http\Controllers\Panel\StrategySerpController::keywordUrl($project->publicId, $row->publicId) }}" class="min-w-0 flex-1 break-words text-slate-900 hover:text-brand-700 hover:underline">{{ $row->keyword }}</a>
              <span class="text-xs text-slate-500">{{ implode(', ', array_map(static fn (\OsfSeo\Strategy\StrategySource $source): string => $source->label(), $row->sources)) }}</span>
              @if (! $row->active)<span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">nieaktywna</span>@endif
            </li>
          @endforeach
        </ul>
        <div class="mt-3" x-show="selected.length > 0" x-cloak>
          <x-panel.button type="submit" variant="danger">Zdejmij wpis ręczny</x-panel.button>
          <span class="ml-2 text-xs text-slate-500">Fraza zostaje w Strategii, jeśli wskazuje ją inne źródło.</span>
        </div>
      </form>
    @endif
  </x-panel.card>
@endsection
