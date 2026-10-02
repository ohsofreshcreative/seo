@extends('panel.layouts.app', ['active' => 'market-data'])

@section('title', 'Dane rynkowe · ' . $project->name)

@php
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Market\CostBudget;
  use OsfSeo\Market\PlannedTask;
  use OsfSeo\Market\ProviderErrorCategory;
  use OsfSeo\Opportunities\Text;

  $state = $status['state'];
  $lastError = $state['last_error'] ? ProviderErrorCategory::tryFrom($state['last_error']) : null;
  $connected = $status['configured'] && ! in_array($lastError, [ProviderErrorCategory::Authentication, ProviderErrorCategory::Billing], true);
  $budget = $status['budget'];
  $projectMetrics = $status['project_metrics'];
  $taskLabels = [
    'google_ads_search_volume' => 'Wolumen (Google Ads, Standard)',
    'labs_bulk_keyword_difficulty' => 'Trudność SEO (Labs, Live)',
    'labs_related_keywords' => 'Nowe frazy: powiązane (Labs, Live)',
    'labs_keyword_suggestions' => 'Nowe frazy: zawierające seed (Labs, Live)',
    'google_organic_serp' => 'Pozycje SERP (Google Organic, Standard)',
  ];
  $triggerLabels = ['auto' => 'automatycznie', 'discovery' => 'wyszukiwanie fraz', 'serp_manual' => 'pomiar ręczny', 'serp_schedule' => 'harmonogram pozycji'];
  $purposes = ['enrichment' => 'Dane rynkowe (wzbogacanie fraz)', 'discovery' => 'Nowe frazy', 'serp' => 'Pozycje SERP'];
  $breakdown = $status['usage_breakdown'] ?? null;
  $taskStatuses = ['pending' => 'czeka na wynik', 'completed' => 'zakończone', 'failed' => 'błąd', 'expired' => 'przeterminowane'];
@endphp

@section('content')
  <x-panel.page-header title="Dane rynkowe"
    :description="$project->name . ' · wolumen, trudność SEO, CPC i konkurencja Ads z DataForSEO — dodatkowe źródło obok Google Search Console'" />

  <div class="grid gap-6 lg:grid-cols-2">
    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Stan</h2>
      <dl class="mt-2 divide-y divide-slate-100 text-sm">
        <div class="flex justify-between gap-4 py-3">
          <dt class="text-slate-500">DataForSEO</dt>
          <dd @class(['text-right font-medium', 'text-emerald-700' => $connected, 'text-amber-700' => ! $connected])>
            @if (! $status['configured'])
              Brak konfiguracji
              @if ($canManage)
                @foreach ($status['missing'] as $name)
                  <span class="block font-mono text-xs">{{ $name }}</span>
                @endforeach
              @endif
            @elseif ($lastError === ProviderErrorCategory::Authentication)
              Błąd logowania do API
            @elseif ($lastError === ProviderErrorCategory::Billing)
              Brak środków lub limit u dostawcy
            @else
              Połączono
            @endif
          </dd>
        </div>
        <div class="flex justify-between gap-4 py-3">
          <dt class="text-slate-500">Rynek</dt>
          <dd class="text-right font-medium text-slate-900">
            @if ($status['market'] === null)
              <span class="text-amber-700">nieobsługiwany ({{ strtoupper($project->country) }} / {{ $project->language }})</span>
            @else
              {{ $status['market_country'] }}
            @endif
          </dd>
        </div>
        <div class="flex justify-between gap-4 py-3">
          <dt class="text-slate-500">Język</dt>
          <dd class="text-right font-medium text-slate-900">{{ $status['market_language'] ?? '—' }}</dd>
        </div>
        <div class="flex justify-between gap-4 py-3">
          <dt class="text-slate-500">Ostatnia synchronizacja</dt>
          <dd class="text-right font-medium text-slate-900">{{ Format::datetime($state['last_success_at']) }}</dd>
        </div>
        <div class="flex justify-between gap-4 py-3">
          <dt class="text-slate-500">Frazy z danymi rynkowymi</dt>
          <dd class="text-right font-medium text-slate-900">
            @if ($projectMetrics === null)
              —
            @else
              {{ Format::number($projectMetrics['enriched']) }}
              <span class="block text-xs font-normal text-slate-500">z wolumenem: {{ Format::number($projectMetrics['with_volume']) }}, z trudnością SEO: {{ Format::number($projectMetrics['with_difficulty']) }}</span>
            @endif
          </dd>
        </div>
        <div class="flex justify-between gap-4 py-3">
          <dt class="text-slate-500">Zadania czekające na wynik</dt>
          <dd class="text-right font-medium text-slate-900">{{ Format::number($status['pending_tasks']) }}</dd>
        </div>
        <div class="flex justify-between gap-4 py-3">
          <dt class="text-slate-500">Automatyczne odświeżanie</dt>
          <dd class="text-right font-medium text-slate-900">
            @if (! $status['auto_refresh'])
              wyłączone (stała)
            @elseif ($state['enabled_at'] === null)
              po pierwszej ręcznej synchronizacji
            @elseif ($status['paused'] !== null)
              <span class="text-amber-700">wstrzymane do {{ Format::datetime($status['paused']['until']) }}</span>
            @else
              włączone (co {{ \OsfSeo\Market\MarketDataConfig::AUTO_INTERVAL_HOURS }} h, nieaktualne po {{ $status['settings']['volume_ttl_days'] }} dniach)
            @endif
          </dd>
        </div>
        @if ($lastError)
          <div class="flex justify-between gap-4 py-3">
            <dt class="text-slate-500">Ostatni błąd</dt>
            <dd class="text-right font-medium text-amber-700">{{ $lastError->label() }} <span class="block text-xs font-normal text-slate-500">{{ Format::datetime($state['last_error_at']) }}</span></dd>
          </div>
        @endif
      </dl>
    </x-panel.card>

    @if ($canManage)
      <x-panel.card>
        <h2 class="text-base font-semibold text-slate-900">Koszty i limity bezpieczeństwa</h2>
        <p class="mt-1 text-xs text-slate-500">Lokalny bezpiecznik, nie rozliczenie: koszt zgłoszony przez API, a gdy go brak — szacowany. Po osiągnięciu limitu płatna synchronizacja się zatrzymuje.</p>
        <dl class="mt-2 divide-y divide-slate-100 text-sm">
          <div class="flex justify-between gap-4 py-3">
            <dt class="text-slate-500">Status</dt>
            <dd @class(['text-right font-medium', 'text-emerald-700' => $budget['status'] === CostBudget::OK, 'text-amber-700' => $budget['status'] !== CostBudget::OK])>{{ CostBudget::label($budget['status']) }}</dd>
          </div>
          <div class="flex justify-between gap-4 py-3">
            <dt class="text-slate-500">Dziś (UTC)</dt>
            <dd class="text-right font-medium tabular-nums text-slate-900">{{ Format::usd($budget['spent_today'], 4) }} / {{ Format::usd($budget['daily_limit']) }}</dd>
          </div>
          <div class="flex justify-between gap-4 py-3">
            <dt class="text-slate-500">Ten miesiąc (UTC)</dt>
            <dd class="text-right font-medium tabular-nums text-slate-900">{{ Format::usd($budget['spent_month'], 4) }} / {{ Format::usd($budget['monthly_limit']) }}</dd>
          </div>
          @if ($breakdown !== null)
            <div class="py-3">
              <dt class="text-slate-500">Projekt — podział kosztów</dt>
              <dd class="mt-2">
                <table class="min-w-full text-sm tabular-nums">
                  <thead>
                    <tr class="text-left text-xs text-slate-500">
                      <th class="py-1 pr-3 font-medium">Moduł</th>
                      <th class="py-1 pr-3 text-right font-medium">Dziś</th>
                      <th class="py-1 pr-3 text-right font-medium">Ten miesiąc</th>
                      <th class="py-1 text-right font-medium" title="Zadania wolumenu i żądania Labs; dla pozycji — zadania SERP (frazy)">Zadania</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100">
                    @foreach ($purposes as $key => $label)
                      <tr>
                        <td class="py-1 pr-3 text-slate-700">{{ $label }}</td>
                        <td class="py-1 pr-3 text-right">{{ Format::usd($breakdown['today'][$key]['cost'], 4) }}</td>
                        <td class="py-1 pr-3 text-right">{{ Format::usd($breakdown['month'][$key]['cost'], 4) }}</td>
                        <td class="py-1 text-right">{{ Format::number($breakdown['month'][$key]['tasks']) }}</td>
                      </tr>
                    @endforeach
                    <tr class="font-semibold text-slate-900">
                      <td class="py-1 pr-3">RAZEM</td>
                      <td class="py-1 pr-3 text-right">{{ Format::usd($breakdown['today']['total']['cost'], 4) }}</td>
                      <td class="py-1 pr-3 text-right">{{ Format::usd($breakdown['month']['total']['cost'], 4) }}</td>
                      <td class="py-1 text-right">{{ Format::number($breakdown['month']['total']['tasks']) }}</td>
                    </tr>
                  </tbody>
                </table>
                <p class="mt-1 text-xs text-slate-500">Limity dzienny i miesięczny są wspólne dla wszystkich modułów. Zaplanowane pomiary pozycji liczą się od razu (rezerwacja kosztu szacowanego).</p>
              </dd>
            </div>
          @endif
          <div class="flex justify-between gap-4 py-3">
            <dt class="text-slate-500">Projekt — ostatnie 30 dni</dt>
            <dd class="text-right font-medium tabular-nums text-slate-900">
              {{ Format::usd($status['usage_30d']['cost'], 4) }}
              <span class="block text-xs font-normal text-slate-500">zadania: {{ Format::number($status['usage_30d']['tasks']) }}, frazy: {{ Format::number($status['usage_30d']['keywords']) }}</span>
            </dd>
          </div>
          <div class="flex justify-between gap-4 py-3">
            <dt class="text-slate-500">Limit zadań na przebieg</dt>
            <dd class="text-right font-medium tabular-nums text-slate-900">{{ $budget['max_tasks_per_run'] }}</dd>
          </div>
        </dl>
      </x-panel.card>
    @endif
  </div>

  @if ($canManage && $plan !== null)
    <x-panel.card class="mt-6">
      <h2 class="text-base font-semibold text-slate-900">Synchronizuj dane fraz</h2>
      @if ($plan->skipReason !== null)
        <p class="mt-2 text-sm text-slate-600">Brak planu: {{ $plan->skipReason }}.</p>
      @elseif ($plan->keywordsToSend() === 0)
        <p class="mt-2 text-sm text-slate-600">
          @if ($plan->keywordCount() === 0)
            Wybrane frazy mają aktualne dane rynkowe — nie ma czego wysyłać.
          @else
            Nie można teraz wysłać zadań: {{ CostBudget::label((string) $plan->blockedBy()) }}.
          @endif
        </p>
      @else
        @php($volume = array_values(array_filter($plan->allowedTasks(), fn ($t) => $t->type === PlannedTask::VOLUME)))
        @php($difficulty = array_values(array_filter($plan->allowedTasks(), fn ($t) => $t->type === PlannedTask::DIFFICULTY)))
        <p class="mt-2 text-sm text-slate-600">
          Do wysłania: <strong class="text-slate-900">{{ Format::number($plan->keywordsToSend()) }} {{ Text::plural($plan->keywordsToSend(), 'fraza', 'frazy', 'fraz') }}</strong>
          ({{ count($volume) }} {{ count($volume) === 1 ? 'zadanie' : 'zadania' }} wolumenu w kolejce Standard — wynik w ciągu ok. 1–3 h,
          {{ count($difficulty) }} {{ count($difficulty) === 1 ? 'żądanie' : 'żądania' }} trudności SEO).
          Szacowany koszt: <strong class="text-slate-900">{{ Format::usd($plan->estimatedCost(), 4) }}</strong>.
        </p>
        @if ($plan->blockedBy() !== null)
          <p class="mt-1 text-sm text-amber-700">Część fraz poczeka na kolejny przebieg: {{ CostBudget::label((string) $plan->blockedBy()) }} ({{ Format::number($plan->keywordCount()) }} {{ Text::plural($plan->keywordCount(), 'fraza wymaga', 'frazy wymagają', 'fraz wymaga') }} danych).</p>
        @endif
        <form method="post" action="{{ PanelUrl::project($project->publicId, 'market-data/sync') }}" class="mt-4"
          x-data="{ sending: false }"
          @submit="if (sending || ! confirm(@js('Wysłać ' . $plan->keywordsToSend() . ' ' . Text::plural($plan->keywordsToSend(), 'frazę', 'frazy', 'fraz') . ' do DataForSEO? Szacowany koszt: ' . Format::usd($plan->estimatedCost(), 4) . '.'))) { $event.preventDefault(); return; } sending = true">
          <x-panel.nonce />
          <input type="hidden" name="expected_keywords" value="{{ $plan->keywordsToSend() }}">
          <x-panel.button type="submit" x-bind:disabled="sending">Synchronizuj dane fraz</x-panel.button>
        </form>
      @endif
      <p class="mt-4 text-xs text-slate-500">
        Wybór fraz: co najmniej {{ $status['settings']['min_impressions'] }} wyświetleń GSC w ostatnich {{ $status['settings']['window_days'] }} dniach danych, najpierw najczęściej wyświetlane;
        tylko frazy bez danych albo z danymi starszymi niż {{ $status['settings']['volume_ttl_days'] }} dni. Frazy z niedozwolonymi znakami (np. ?, !, przecinek) i dłuższe niż 80 znaków lub 10 słów są pomijane.
        @if ($plan->rejected > 0) Pominięto: {{ Format::number($plan->rejected) }}. @endif
      </p>
    </x-panel.card>
  @endif

  @if ($canManage && $recent !== [])
    <x-panel.card class="mt-6">
      <h2 class="text-base font-semibold text-slate-900">Ostatnie zadania</h2>
      <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
              <th class="py-2 pr-4 font-medium">Utworzono</th>
              <th class="py-2 pr-4 font-medium">Zadanie</th>
              <th class="py-2 pr-4 text-right font-medium">Frazy</th>
              <th class="py-2 pr-4 font-medium">Stan</th>
              <th class="py-2 text-right font-medium">Koszt</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 tabular-nums">
            @foreach ($recent as $task)
              <tr>
                <td class="py-2 pr-4 text-slate-600">{{ Format::datetime($task['created_at']) }}</td>
                <td class="py-2 pr-4 text-slate-900">{{ $taskLabels[$task['endpoint']] ?? $task['endpoint'] }} <span class="text-xs text-slate-500">· {{ $triggerLabels[$task['trigger_type']] ?? 'ręcznie' }}</span></td>
                <td class="py-2 pr-4 text-right">{{ Format::number((int) $task['keywords_count']) }}</td>
                <td @class(['py-2 pr-4', 'text-amber-700' => in_array($task['status'], ['failed', 'expired'], true), 'text-slate-600' => ! in_array($task['status'], ['failed', 'expired'], true)])>
                  {{ $task['endpoint'] === 'google_organic_serp' && $task['status'] === 'pending' ? 'zaplanowane (rezerwacja)' : ($task['endpoint'] === 'google_organic_serp' && $task['status'] === 'completed' ? 'zlecone' : ($taskStatuses[$task['status']] ?? $task['status'])) }}
                  @if ($task['error_code'] && ProviderErrorCategory::tryFrom($task['error_code']))
                    <span class="block text-xs">{{ ProviderErrorCategory::from($task['error_code'])->label() }}</span>
                  @endif
                </td>
                <td class="py-2 text-right">{{ Format::usd($task['cost'] !== null ? (float) $task['cost'] : (float) $task['estimated_cost'], 4) }}@if ($task['cost'] === null)<span class="text-xs text-slate-500"> (szac.)</span>@endif</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </x-panel.card>
  @endif

  <div class="mt-6 space-y-1 text-xs text-slate-500">
    <p><strong class="font-medium text-slate-600">Wolumen</strong> — średnia miesięczna liczba wyszukiwań frazy na rynku projektu (Google Ads, przez DataForSEO), z historią 12 miesięcy.</p>
    <p><strong class="font-medium text-slate-600">Trudność SEO</strong> — szacunek DataForSEO (0–100) trudności wejścia do organicznego TOP 10. To nie jest konkurencja reklamowa.</p>
    <p><strong class="font-medium text-slate-600">Konkurencja Ads</strong> i <strong class="font-medium text-slate-600">CPC</strong> — konkurencja reklamodawców i koszt kliknięcia w płatnych wynikach Google Ads (USD).</p>
    <p>Dane GSC (kliknięcia, wyświetlenia, CTR, średnia pozycja GSC) pozostają źródłem prawdy o skuteczności strony; dane rynkowe ich nie zastępują. „—” oznacza brak danych (nie 0).</p>
  </div>
@endsection
