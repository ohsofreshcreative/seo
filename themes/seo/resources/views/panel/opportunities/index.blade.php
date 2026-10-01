@extends('panel.layouts.app', ['active' => 'opportunities'])

@section('title', 'Szanse SEO · ' . $project->name)

@php
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Opportunities\OpportunityFilters;
  use OsfSeo\Opportunities\OpportunityStatus;
  use OsfSeo\Opportunities\OpportunityType;

  $base = PanelUrl::project($project->publicId, 'opportunities');
  $url = fn (array $changes = []) => $base . (($query = http_build_query($filters->with($changes + ['page' => 1])->toQuery())) !== '' ? '?' . $query : '');
  $pageUrl = fn (int $number) => $base . (($query = http_build_query($filters->with(['page' => $number])->toQuery())) !== '' ? '?' . $query : '');
  $days = $filters->days;
  $openTotal = array_sum(array_column($summary['types'], 'open'));
  $total = array_sum(array_column($summary['types'], 'total'));
@endphp

@section('content')
  <x-panel.page-header title="Szanse SEO" :description="$project->name . ' · sygnały z Google Search Console do sprawdzenia'">
    @if ($canManage)
      <x-slot:actions>
        <form method="post" action="{{ $base }}/analyze">
          <x-panel.nonce />
          <x-panel.button type="submit" variant="secondary">Przelicz szanse</x-panel.button>
        </form>
      </x-slot:actions>
    @endif
  </x-panel.page-header>

  @if ($project->gscProperty === null)
    <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      Projekt nie ma wybranej property Google Search Console — szanse nie są aktualizowane.
      <a href="{{ PanelUrl::project($project->publicId, 'search-console') }}" class="font-medium underline">Przejdź do Search Console</a>
    </div>
  @endif

  <div class="mb-6 flex flex-col gap-3 text-sm text-slate-600 lg:flex-row lg:items-center lg:justify-between">
    <p>
      @if ($analysis && $analysis['status'] === 'success')
        Analiza z {{ Format::datetime($analysis['analyzed_at']) }} · dane GSC do <span class="font-medium text-slate-900">{{ Format::date($analysis['latest_date']) }}</span>
        ({{ $days }} dni vs poprzednie {{ $days }} dni, daty GSC w czasie pacyficznym)
      @elseif ($analysis && $analysis['status'] === 'skipped')
        Okres {{ $days }} dni nie został przeanalizowany: {{ \OsfSeo\Opportunities\AnalysisResult::describeReason($analysis['message']) }}.
      @elseif ($analysis && $analysis['status'] === 'failed')
        Ostatnia analiza okresu {{ $days }} dni zakończyła się błędem ({{ Format::datetime($analysis['analyzed_at']) }}).
      @else
        Szanse nie były jeszcze analizowane — analiza uruchamia się automatycznie po imporcie danych GSC.
      @endif
    </p>
    <nav class="flex gap-1" aria-label="Długość okresu">
      @foreach (\OsfSeo\Analytics\Period::ALLOWED_DAYS as $option)
        <a href="{{ $url(['days' => $option]) }}" @class([
          'whitespace-nowrap rounded-md px-3 py-1.5 font-medium',
          'bg-brand-700 text-white' => $days === $option,
          'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50' => $days !== $option,
        ]) @if ($days === $option) aria-current="true" @endif>{{ $option }} dni</a>
      @endforeach
    </nav>
  </div>

  {{-- Kategorie: otwarte szanse okresu (kliknięcie filtruje listę). --}}
  <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
    <a href="{{ $url(['type' => null]) }}" @class([
      'rounded-lg border bg-white p-4 shadow-sm hover:border-brand-500',
      'border-brand-500 ring-1 ring-brand-500' => $filters->type === null,
      'border-slate-200' => $filters->type !== null,
    ])>
      <p class="text-xs font-medium text-slate-500">Otwarte szanse</p>
      <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ Format::number($openTotal) }}</p>
      <p class="text-xs text-slate-500">z {{ Format::number($total) }} aktywnych</p>
    </a>
    @foreach (OpportunityType::cases() as $type)
      <a href="{{ $url(['type' => $type->value]) }}" title="{{ $type->description() }}" @class([
        'rounded-lg border bg-white p-4 shadow-sm hover:border-brand-500',
        'border-brand-500 ring-1 ring-brand-500' => $filters->type === $type->value,
        'border-slate-200' => $filters->type !== $type->value,
      ])>
        <p class="text-xs font-medium text-slate-500">{{ $type->shortLabel() }}</p>
        <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ Format::number($summary['types'][$type->value]['open'] ?? 0) }}</p>
        <p class="text-xs text-slate-500">otwarte</p>
      </a>
    @endforeach
  </div>

  <form method="get" action="{{ $base }}" class="mt-6 grid grid-cols-2 gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-4 xl:grid-cols-7">
    <input type="hidden" name="days" value="{{ $days }}">
    <input type="hidden" name="view" value="{{ $filters->view }}">
    <div class="col-span-2">
      <label for="f-q" class="block text-xs font-medium text-slate-600">Szukaj frazy lub adresu</label>
      <input id="f-q" name="q" type="search" value="{{ $filters->search }}" placeholder="np. strony internetowe" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
    </div>
    <div>
      <label for="f-type" class="block text-xs font-medium text-slate-600">Typ</label>
      <select id="f-type" name="type" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <option value="">Wszystkie</option>
        @foreach (OpportunityType::cases() as $type)
          <option value="{{ $type->value }}" @selected($filters->type === $type->value)>{{ $type->shortLabel() }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label for="f-status" class="block text-xs font-medium text-slate-600">Status</label>
      <select id="f-status" name="status" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <option value="open" @selected($filters->status === 'open')>Otwarte</option>
        <option value="all" @selected($filters->status === 'all')>Wszystkie</option>
        @foreach (OpportunityStatus::cases() as $status)
          <option value="{{ $status->value }}" @selected($filters->status === $status->value)>{{ $status->label() }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label for="f-priority" class="block text-xs font-medium text-slate-600">Priorytet</label>
      <select id="f-priority" name="priority" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        @foreach (OpportunityFilters::PRIORITIES as $priority)
          <option value="{{ $priority }}" @selected($filters->minPriority === $priority)>{{ $priority === 0 ? 'Dowolny' : 'co najmniej ' . $priority }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label for="f-confidence" class="block text-xs font-medium text-slate-600">Pewność</label>
      <select id="f-confidence" name="confidence" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <option value="0" @selected($filters->minConfidence === 0)>Dowolna</option>
        <option value="2" @selected($filters->minConfidence === 2)>co najmniej średnia</option>
        <option value="3" @selected($filters->minConfidence === 3)>wysoka</option>
      </select>
    </div>
    <div>
      <label for="f-state" class="block text-xs font-medium text-slate-600">Stan</label>
      <select id="f-state" name="state" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <option value="active" @selected($filters->state === 'active')>Aktywne</option>
        <option value="inactive" @selected($filters->state === 'inactive')>Nieaktywne (sygnał wygasł)</option>
        <option value="archived" @selected($filters->state === 'archived')>Archiwalne (poprzednia property)</option>
      </select>
    </div>
    <div class="col-span-2 flex items-end gap-2 md:col-span-4 xl:col-span-7">
      <x-panel.button type="submit">Filtruj</x-panel.button>
      <x-panel.button variant="secondary" :href="$base . ($days !== 28 ? '?days=' . $days : '')">Wyczyść</x-panel.button>
      @if ($filters->state === 'active')
        <nav class="ml-auto flex gap-1 text-sm" aria-label="Widok">
          @foreach (['list' => 'Lista', 'pages' => 'Wg podstron'] as $view => $label)
            <a href="{{ $url(['view' => $view]) }}" @class([
              'whitespace-nowrap rounded-md px-3 py-1.5 font-medium',
              'bg-brand-700 text-white' => $filters->view === $view,
              'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50' => $filters->view !== $view,
            ]) @if ($filters->view === $view) aria-current="true" @endif>{{ $label }}</a>
          @endforeach
        </nav>
      @endif
    </div>
  </form>

  <div class="mt-6">
    @if ($page->total === 0)
      <x-panel.empty-state :title="$total === 0 && $filters->state === 'active' ? 'Brak wykrytych szans' : 'Brak szans dla wybranych filtrów'"
        :description="$total === 0 && $filters->state === 'active'
          ? 'Szanse pojawią się po analizie zaimportowanych danych GSC (automatycznie po imporcie). Brak szans może też oznaczać, że żaden sygnał nie przekracza progów.'
          : 'Zmień filtry albo wybierz inny okres.'" />
    @elseif ($filters->groupsByPage())
      <div class="space-y-4">
        @foreach ($page->groups as $group)
          <details class="rounded-lg border border-slate-200 bg-white shadow-sm" @if ($loop->first) open @endif>
            <summary class="flex cursor-pointer flex-wrap items-center gap-3 px-5 py-4">
              <x-panel.score :value="$group['max_priority']" size="sm" />
              <span class="min-w-0 flex-1">
                <span class="block break-all font-semibold text-slate-900">{{ $group['page_url'] ? \OsfSeo\Opportunities\Text::path($group['page_url']) : 'Frazy bez wskazanej podstrony' }}</span>
                <span class="text-xs text-slate-500">{{ $group['count'] }} {{ \OsfSeo\Opportunities\Text::plural($group['count'], 'szansa', 'szanse', 'szans') }} ·
                  {{ implode(', ', array_map(static fn ($type) => $type->shortLabel(), $group['types'])) }}</span>
              </span>
              @if ($group['page_url'] && preg_match('#^https?://#i', $group['page_url']))
                <a href="{{ $group['page_url'] }}" target="_blank" rel="noopener noreferrer" class="text-xs text-brand-600 hover:underline">Otwórz stronę ↗</a>
              @endif
            </summary>
            <div class="space-y-3 border-t border-slate-100 p-4">
              @foreach ($group['opportunities'] as $opportunity)
                @include('panel.opportunities.partials.card', ['opportunity' => $opportunity, 'days' => $days])
              @endforeach
            </div>
          </details>
        @endforeach
      </div>
    @else
      <div class="space-y-3">
        @foreach ($page->rows as $opportunity)
          @include('panel.opportunities.partials.card', ['opportunity' => $opportunity, 'days' => $days])
        @endforeach
      </div>
    @endif

    @if ($page->pages() > 1)
      <nav class="mt-4 flex items-center justify-between text-sm" aria-label="Strony">
        <span class="text-slate-600">Strona {{ $filters->page }} z {{ $page->pages() }}</span>
        <div class="flex gap-2">
          @if ($filters->page > 1)
            <x-panel.button variant="secondary" :href="$pageUrl($filters->page - 1)">Poprzednia</x-panel.button>
          @endif
          @if ($filters->page < $page->pages())
            <x-panel.button variant="secondary" :href="$pageUrl($filters->page + 1)">Następna</x-panel.button>
          @endif
        </div>
      </nav>
    @endif
  </div>

  <div class="mt-8 space-y-1 text-xs text-slate-500">
    <p><strong class="font-medium text-slate-600">Szanse to sygnały do sprawdzenia</strong> wyliczone wyłącznie z danych Google Search Console — nie gwarancja wzrostu. System nie analizuje treści strony, title, opisów, linków ani wyników konkurencji; rekomendacje to hipotezy i kolejne kroki.</p>
    <p><strong class="font-medium text-slate-600">Priorytet</strong> (0–100) mówi, jak bardzo warto się przyjrzeć (popyt + skala + trend), a <strong class="font-medium text-slate-600">pewność</strong> — ile danych potwierdza sygnał. Pozycje to średnia pozycja (GSC) ważona wyświetleniami, nie dokładna pozycja w Google; CTR = kliknięcia / wyświetlenia.</p>
  </div>
@endsection
