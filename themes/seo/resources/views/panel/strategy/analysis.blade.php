@extends('panel.layouts.app', ['active' => 'strategy'])

@section('title', 'Analiza SERP · Strategia · ' . $project->name)

@php
  use App\Http\Controllers\Panel\PositionsController;
  use App\Http\Controllers\Panel\StrategySerpController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use App\Panel\StrategyLabels;
  use OsfSeo\Market\CostBudget;
  use OsfSeo\Opportunities\Text;
  use OsfSeo\Strategy\Serp\SerpAnalysisPlan;

  $base = PanelUrl::project($project->publicId, 'strategy');
  $action = StrategySerpController::analysisUrl($project->publicId);
  $budget = $plan->budget;
  $remainingToday = $budget === null ? null : max(0.0, $budget['daily_limit'] - $budget['spent_today']);
  $remainingMonth = $budget === null ? null : max(0.0, $budget['monthly_limit'] - $budget['spent_month']);
  $groups = [
    SerpAnalysisPlan::MEASURE => ['Nowy pomiar (płatny)', 'text-slate-900'],
    SerpAnalysisPlan::REUSE => ['Ponowne użycie świeżego pomiaru (bez kosztu)', 'text-emerald-700'],
    SerpAnalysisPlan::PENDING => ['Pomiar w toku (bez kosztu)', 'text-sky-700'],
    SerpAnalysisPlan::REJECTED => ['Odrzucone', 'text-slate-500'],
  ];
  $skipReasons = [
    SerpAnalysisPlan::NOT_CONFIGURED => 'DataForSEO nie jest skonfigurowane (stałe w wp-config.php)',
    SerpAnalysisPlan::UNSUPPORTED_MARKET => 'rynek projektu nie jest obsługiwany — zmień kraj i język projektu',
    SerpAnalysisPlan::NO_CANDIDATES => 'brak kandydatów Strategii do analizy — najpierw przelicz Strategię',
    SerpAnalysisPlan::NOTHING_TO_DO => 'wszystkie wybrane frazy mają świeży pomiar (użyty bez kosztu) albo pomiar w toku',
    SerpAnalysisPlan::OVER_RUN_LIMIT => 'wybrano więcej nowych pomiarów niż limit na jedno uruchomienie (' . $plan->limit . ') — wybierz mniej fraz',
  ];
  $confirmText = 'Zlecić ' . $plan->tasks() . ' ' . Text::plural($plan->tasks(), 'płatny pomiar', 'płatne pomiary', 'płatnych pomiarów') . ' SERP (TOP' . ($plan->context?->depth ?? '—') . ') w DataForSEO? Szacowany maksymalny koszt: ' . Format::usd($plan->estimatedCost(), 4) . '.';
@endphp

@section('content')
  <x-panel.page-header title="Analiza SERP"
    :description="$project->name . ' · płatne pomiary SERP fraz Strategii przez moduł Pozycje — podgląd jest bezpłatny, nic nie zostanie wysłane bez potwierdzenia'" />

  @include('panel.strategy.partials.tabs', ['tab' => 'analysis', 'canAnalyze' => true, 'canManage' => true])

  <ol class="mb-6 flex flex-wrap gap-2 text-xs font-medium text-slate-600" aria-label="Kroki">
    @foreach (['1. Wybór fraz', '2. Podgląd', '3. Potwierdzenie', '4. Kolejka', '5. Status'] as $index => $step)
      <li @class(['rounded-full px-3 py-1 ring-1 ring-inset', 'bg-brand-700 text-white ring-brand-700' => $index <= 1, 'bg-white ring-slate-300' => $index > 1])>{{ $step }}</li>
    @endforeach
  </ol>

  <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Wybór fraz</h2>
      <p class="mt-1 text-sm text-slate-600">
        @if ($values === null)
          Kolejność priorytetu Strategii (najważniejsze tematy bez świeżego pomiaru), najwyżej {{ $plan->limit }} nowych pomiarów.
        @else
          Jawny wybór: {{ Format::number(count($values)) }} {{ Text::plural(count($values), 'pozycja', 'pozycje', 'pozycji') }}.
          <a href="{{ $action }}" class="text-brand-600 hover:underline">Wróć do kolejności priorytetu</a>
        @endif
      </p>
      <form method="get" action="{{ $action }}" class="mt-3">
        <label for="analysis-keywords" class="block text-xs font-medium text-slate-600">Frazy Strategii (po jednej w wierszu)</label>
        <textarea id="analysis-keywords" name="keywords" rows="5" maxlength="20000" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"></textarea>
        <x-panel.button type="submit" variant="secondary" class="mt-2">Pokaż podgląd</x-panel.button>
      </form>
      <p class="mt-3 text-xs text-slate-500">Frazy zaznaczysz też na liście <a href="{{ $base }}/serp?filter=missing" class="text-brand-600 hover:underline">SERP Intelligence</a> albo w szczegółach tematu.</p>
    </x-panel.card>

    <x-panel.card class="lg:col-span-2">
      <h2 class="text-base font-semibold text-slate-900">Podgląd (bez żadnego żądania)</h2>

      @if ($plan->skipReason !== null && $plan->items === [])
        <p class="mt-2 text-sm text-amber-700">Nie można uruchomić: {{ $skipReasons[$plan->skipReason] ?? $plan->skipReason }}.</p>
      @else
        <dl class="mt-3 grid grid-cols-2 gap-4 text-sm md:grid-cols-4">
          <div><dt class="text-slate-500">Rynek</dt><dd class="mt-1 font-medium text-slate-900">{{ $plan->market?->label() ?? '—' }}</dd></div>
          <div><dt class="text-slate-500">Urządzenie / głębokość</dt><dd class="mt-1 font-medium text-slate-900">{{ $plan->context?->device->label() ?? '—' }}, TOP{{ $plan->context?->depth ?? '—' }}</dd></div>
          <div><dt class="text-slate-500">Nowe zadania</dt><dd class="mt-1 font-medium tabular-nums text-slate-900">{{ Format::number($plan->tasks()) }}</dd></div>
          <div><dt class="text-slate-500">Szacowany maksymalny koszt</dt><dd class="mt-1 text-lg font-semibold tabular-nums text-slate-900">{{ Format::usd($plan->estimatedCost(), 4) }}</dd></div>
        </dl>
        <p class="mt-3 text-sm text-slate-600">
          Ponowne użycie: {{ Format::number(count($plan->byAction(SerpAnalysisPlan::REUSE))) }},
          w toku: {{ Format::number(count($plan->byAction(SerpAnalysisPlan::PENDING))) }},
          odrzucone: {{ Format::number(count($plan->byAction(SerpAnalysisPlan::REJECTED))) }}.
          Koszt zadania (maks.): {{ Format::usd($plan->costPerTask, 5) }}.
        </p>
        <p class="mt-1 text-sm text-slate-600">
          Pozostały wspólny limit DataForSEO: dziś {{ Format::usd($remainingToday, 4) }}, w tym miesiącu {{ Format::usd($remainingMonth, 4) }}.
          Rozstrzygający jest koszt zgłoszony przez DataForSEO po wykonaniu.
        </p>

        @if ($plan->skipReason === SerpAnalysisPlan::NOTHING_TO_DO)
          <p class="mt-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">Nic do zlecenia — {{ $skipReasons[$plan->skipReason] }}. Nic nie zostanie wysłane.</p>
        @elseif ($plan->skipReason !== null)
          <p class="mt-4 text-sm text-amber-700">Nie można uruchomić: {{ $skipReasons[$plan->skipReason] ?? $plan->skipReason }}.</p>
        @elseif ($plan->paused)
          <p class="mt-4 text-sm text-amber-700">Płatne wywołania DataForSEO są wstrzymane po błędzie konta — sprawdź stronę „Dane rynkowe”.</p>
        @elseif ($plan->blockedBy() !== null)
          <p class="mt-4 text-sm text-amber-700">Analiza przekracza wspólny limit kosztów ({{ CostBudget::label((string) $plan->blockedBy()) }}). Wybierz mniej fraz albo poczekaj na odnowienie limitu — nie wykonujemy częściowych analiz.</p>
        @elseif ($plan->coolingDown)
          <p class="mt-4 text-sm text-amber-700">Pomiar ręczny był uruchomiony przed chwilą (wspólny odstęp {{ $cooldownMinutes }} min z modułem Pozycje). Spróbuj ponownie za kilka minut.</p>
        @else
          <form method="post" action="{{ $action }}" class="mt-4" x-data="{ sending: false }"
            @submit="if (sending || ! confirm(@js($confirmText))) { $event.preventDefault(); return; } sending = true">
            <x-panel.nonce />
            @foreach ($values ?? [] as $value)
              <input type="hidden" name="ids[]" value="{{ $value }}">
            @endforeach
            <input type="hidden" name="confirm" value="1">
            <input type="hidden" name="expected_tasks" value="{{ $plan->tasks() }}">
            <input type="hidden" name="expected_cost" value="{{ sprintf('%.6F', $plan->estimatedCost()) }}">
            <x-panel.button type="submit" x-bind:disabled="sending">Potwierdź i zleć analizę ({{ Format::usd($plan->estimatedCost(), 4) }} maks.)</x-panel.button>
            <span class="ml-2 text-xs text-slate-500">Zlecenie trafia do kolejki pomiarów (moduł Pozycje) — plan większy albo droższy niż ten podgląd nie zostanie wysłany.</span>
          </form>
        @endif

        <div class="mt-6 space-y-4">
          @foreach ($groups as $group => [$label, $color])
            @php($items = $plan->byAction($group))
            @if ($items !== [])
              <div>
                <h3 class="text-sm font-medium {{ $color }}">{{ $label }} <span class="tabular-nums text-slate-500">({{ count($items) }})</span></h3>
                <ul class="mt-1 divide-y divide-slate-100 text-sm">
                  @foreach (array_slice($items, 0, 100) as $item)
                    <li class="flex flex-wrap items-center gap-2 py-1.5">
                      <span class="min-w-0 flex-1 break-words text-slate-800">{{ $item['keyword'] }}</span>
                      <span class="text-xs text-slate-500">{{ StrategyLabels::analysisReason($item['reason']) }}</span>
                      @if ($item['checked_at'] !== null)
                        <x-panel.serp-freshness :freshness="$item['freshness']" :checked-at="$item['checked_at']" />
                      @endif
                    </li>
                  @endforeach
                </ul>
              </div>
            @endif
          @endforeach
        </div>
      @endif
    </x-panel.card>
  </div>

  <x-panel.card class="mt-6">
    <h2 class="text-base font-semibold text-slate-900">Status analiz</h2>
    <p class="mt-1 text-sm text-slate-600">
      Frazy analizy (poza listą Pozycji): {{ Format::number($status['analysis_keywords']) }}, z pomiarem: {{ Format::number($status['measured']) }}
      · ostatni pomiar {{ Format::datetime($status['last_checked_at']) }} · limit na uruchomienie: {{ Format::number($status['limit_per_run']) }}.
    </p>
    @if ($status['runs'] === [])
      <p class="mt-2 text-sm text-slate-500">Nie zlecono jeszcze analizy SERP Strategii.</p>
    @else
      <div class="mt-3 overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
            <tr><th class="py-2 pr-3">Zlecono</th><th class="py-2 pr-3">Status</th><th class="py-2 pr-3 text-right">Ukończone / zaplanowane</th><th class="py-2 pr-3 text-right">Koszt (szac. maks. / zgłoszony)</th><th class="py-2"></th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach ($status['runs'] as $run)
              <tr>
                <td class="py-2 pr-3 text-slate-700">{{ Format::datetime($run['created_at'] ?? null) }}</td>
                <td class="py-2 pr-3 text-slate-700">{{ $run['status_label'] }}@if ($run['skip_reason'] !== null) <span class="text-xs text-slate-500">({{ \OsfSeo\Serp\SerpRun::skipLabel($run['skip_reason']) }})</span>@endif</td>
                <td class="py-2 pr-3 text-right tabular-nums">{{ Format::number($run['tasks_completed']) }} / {{ Format::number($run['keywords_planned']) }}@if ($run['tasks_failed'] > 0) <span class="text-xs text-rose-700">(błędy: {{ $run['tasks_failed'] }})</span>@endif</td>
                <td class="py-2 pr-3 text-right tabular-nums">{{ Format::usd($run['estimated_cost'] ?? null, 4) }} / {{ Format::usd($run['cost'] ?? null, 4) }}</td>
                <td class="py-2 text-right"><a href="{{ PositionsController::runUrl($project->publicId, $run['id']) }}" class="text-brand-600 hover:underline">Postęp</a></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
    <p class="mt-3 text-xs text-slate-500">
      Pomiary działają w tle (kolejka Standard, bez płatnych dodatków). Zlecenie o nieznanym wyniku nie jest ponawiane — odzyskanie po identyfikatorze.
      Wyniki trafią do SERP Intelligence po najbliższym przeliczeniu Strategii. Frazy analizy nie są monitorowane ani wliczane do limitu Pozycji.
    </p>
  </x-panel.card>
@endsection
