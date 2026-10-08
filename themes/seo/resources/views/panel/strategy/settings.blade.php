@extends('panel.layouts.app', ['active' => 'strategy'])

@section('title', 'Ustawienia Strategii · ' . $project->name)

@php
  use App\Panel\Format;
  use App\Panel\PanelUrl;

  $base = PanelUrl::project($project->publicId, 'strategy');
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
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Ostatnie przeliczenie</dt><dd class="text-slate-900">{{ Format::datetime($state['refreshed_at']) }}@if ($state['refresh_ms'] !== null) <span class="text-xs text-slate-500">({{ Format::number($state['refresh_ms'] / 1000, 1) }} s)</span>@endif</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Aktualność</dt><dd class="text-slate-900">{{ $state['running'] ? 'przeliczenie trwa' : ($state['up_to_date'] ? 'aktualne' : 'dane modułów zmieniły się') }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Zlecone z panelu</dt><dd class="text-slate-900">{{ Format::datetime($state['requested_at']) }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Kandydaci aktywni / ręczni</dt><dd class="tabular-nums text-slate-900">{{ Format::number($state['candidates']['active']) }} / {{ Format::number($state['candidates']['manual']) }}</dd></div>
      </dl>
      @if ($state['supported'])
        <form method="post" action="{{ $base }}/refresh" class="mt-4">
          <x-panel.nonce />
          <input type="hidden" name="back" value="{{ $base }}/settings">
          <x-panel.button type="submit" variant="secondary">Zleć przeliczenie</x-panel.button>
        </form>
      @endif
      <p class="mt-3 text-xs text-slate-500">
        Przeliczenie jest lokalne (bez kosztów i bez żądań do API) i nigdy nie zmienia statusów pracy. Panel tylko zapisuje zlecenie — pełne przeliczenie nie działa
        w żądaniu WWW. Automatyczne przeliczanie w tle nie jest jeszcze włączone; do tego czasu uruchamia je administrator komendą
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
