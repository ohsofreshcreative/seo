{{--
  Sekcja „Analiza AI” w szczegółach tematu (STEP 17 D): gotowość trzech typów z jednego odczytu danych tematu, typ zalecany według działania
  Strategii, stan dowodów i ostatnie analizy. Otwarcie strony niczego nie pobiera i nie wywołuje modelu. Klient widzi tylko gotowe analizy.
--}}
@php
  use App\Http\Controllers\Panel\AiController;
  use App\Http\Controllers\Panel\PagesController;
  use App\Panel\Format;
  use App\Panel\PageLabels;
  use App\Panel\PanelUrl;

  $projectId = $project->publicId;
  $prepareBase = fn (?string $type = null) => AiController::prepareUrl($projectId, $ai['topic'], $type);
@endphp

<x-panel.card id="analiza-ai" class="mb-6 scroll-mt-20">
  <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
    <div class="min-w-0">
      <h2 class="text-base font-semibold text-slate-900">Analiza AI</h2>
      <p class="mt-1 max-w-2xl text-xs text-slate-500">
        @if ($ai['manage'])
          Rekomendacje na podstawie zapisanych danych tematu (Strategia, GSC, SERP, pobrane strony). Otwarcie tej strony niczego nie pobiera i nie wywołuje modelu —
          koszt zobaczysz przed zleceniem.
        @else
          Gotowe analizy AI tego tematu przygotowane przez zespół agencji.
        @endif
      </p>
    </div>
    @if ($ai['manage'])
      <div class="flex shrink-0 flex-wrap gap-2">
        <x-panel.button :href="$prepareBase($ai['recommended'])">Przygotuj analizę AI</x-panel.button>
      </div>
    @endif
  </div>

  @if ($ai['manage'])
    @if ($ai['recommended'] === null)
      <p class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
        Działanie Strategii „Do sprawdzenia” nie wskazuje rodzaju analizy — każdy typ wymaga świadomego wyboru i ma niższą pewność wniosków.
      </p>
    @endif

    {{-- Rodzaje analiz: zgodność z działaniem Strategii, gotowość danych, ostatnia analiza. --}}
    <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
      @foreach ($ai['types'] as $type)
        <div @class(['min-w-0 rounded-lg border p-4', 'border-brand-300 bg-brand-50/50' => $type['recommended'], 'border-slate-200' => ! $type['recommended']])>
          <div class="flex flex-wrap items-center gap-2">
            <h3 class="text-sm font-semibold text-slate-900">{{ $type['label'] }}</h3>
            @if ($type['recommended'])
              <span class="inline-flex rounded bg-brand-700 px-1.5 py-0.5 text-xs font-medium text-white">Zalecany</span>
            @endif
          </div>
          <p class="mt-1 text-xs text-slate-500">{{ $type['description'] }}</p>
          <div class="mt-2 flex flex-wrap items-center gap-2">
            <x-panel.readiness :state="$type['readiness']['state']" />
            @if ($type['mode'] !== 'allowed')
              <span class="text-xs text-slate-500">{{ $type['readiness']['compatibility'] }}</span>
            @endif
          </div>
          @if ($type['readiness']['reasons'] !== [])
            <ul class="mt-2 list-disc space-y-0.5 pl-4 text-xs text-slate-600">
              @foreach (array_slice($type['readiness']['reasons'], 0, 2) as $reason)
                <li>{{ $reason }}</li>
              @endforeach
            </ul>
          @elseif ($type['readiness']['limitations'] !== [])
            <p class="mt-2 text-xs text-amber-800">Ograniczenia: {{ count($type['readiness']['limitations']) }} — szczegóły na ekranie przygotowania.</p>
          @endif
          @if ($type['latest'] !== null)
            <p class="mt-2 text-xs text-slate-600">
              Ostatnia: <a href="{{ AiController::runUrl($projectId, $type['latest']['id']) }}" class="text-brand-600 hover:underline">{{ Format::datetime($type['latest']['created_at']) }}</a>
              · <x-panel.ai-status :status="$type['latest']['status']" :decision="$type['latest']['decision']" />
              @if (($type['latest']['freshness']['state'] ?? null) === 'stale')
                <span class="block text-amber-800">Dane tematu zmieniły się od tej analizy.</span>
              @elseif (($type['latest']['freshness']['state'] ?? null) === 'current')
                <span class="block text-emerald-700">Aktualna względem danych tematu.</span>
              @endif
            </p>
          @endif
          @if ($type['readiness']['state'] !== 'blocked')
            <a href="{{ $prepareBase($type['type']) }}" class="mt-3 inline-block text-xs font-medium text-brand-600 hover:underline">Przygotuj ten typ →</a>
          @endif
        </div>
      @endforeach
    </div>

    {{-- Dane, na których opiera się analiza. --}}
    @php($evidence = $ai['evidence'])
    <h3 class="mt-6 text-sm font-semibold text-slate-900">Dane do analizy</h3>
    <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
      <div class="min-w-0">
        <dt class="text-xs text-slate-500">Kopia strony docelowej</dt>
        <dd class="mt-1 flex flex-wrap items-center gap-2">
          @if ($evidence['project_page'] === null)
            <span class="text-slate-500">{{ $evidence['target']['url'] === null ? 'Brak znanej strony docelowej' : 'Strona nie została pobrana' }}</span>
          @else
            <x-panel.page-cache :cache="$evidence['project_page']['cache']" />
            @if ($evidence['project_page']['fetched_at'] !== null)
              <span class="text-xs text-slate-600">{{ Format::datetime($evidence['project_page']['fetched_at']) }} · jakość {{ mb_strtolower(PageLabels::quality($evidence['project_page']['quality'])) }} · {{ Format::number((int) $evidence['project_page']['word_count']) }} słów</span>
            @endif
            @if ($evidence['project_page']['page'] !== null)
              <a href="{{ PagesController::pageUrl($projectId, $evidence['project_page']['page']) }}" class="text-xs text-brand-600 hover:underline">Szczegóły kopii</a>
            @endif
          @endif
        </dd>
      </div>
      <div>
        <dt class="text-xs text-slate-500">Strony konkurencji (z SERP)</dt>
        <dd class="mt-1 text-slate-800">
          {{ Format::number($evidence['competitors']['usable']) }} przydatnych z {{ Format::number(max($evidence['competitors']['linked'], count($evidence['competitors']['items']))) }} pobranych
        </dd>
      </div>
      <div>
        <dt class="text-xs text-slate-500">Pomiar SERP</dt>
        <dd class="mt-1 flex flex-wrap items-center gap-2">
          @if ($evidence['serp'] === null)
            <span class="text-slate-500">Brak pomiaru</span>
          @else
            <x-panel.serp-freshness :freshness="$evidence['serp']['freshness']" :checked-at="$evidence['serp']['checked_at']" />
            @if ($evidence['serp']['keyword'] !== null)
              <span class="text-xs text-slate-500">„{{ $evidence['serp']['keyword'] }}”</span>
            @endif
          @endif
        </dd>
      </div>
      <div>
        <dt class="text-xs text-slate-500">Google Search Console</dt>
        <dd class="mt-1 text-slate-800">{{ $evidence['gsc'] ? 'Dane tematu do ' . Format::date($evidence['gsc_newest']) : 'Brak danych tematu (to nie dowód braku widoczności)' }}</dd>
      </div>
      <div>
        <dt class="text-xs text-slate-500">Frazy tematu</dt>
        <dd class="mt-1 text-slate-800">{{ Format::number($evidence['keywords']) }} (z wolumenem: {{ Format::number($evidence['with_volume']) }})</dd>
      </div>
      <div>
        <dt class="text-xs text-slate-500">Indeks stron projektu</dt>
        <dd class="mt-1 text-slate-800">{{ $evidence['page_index_complete'] ? 'Pełny' : 'Niepełny — inne strony mogą istnieć' }}</dd>
      </div>
    </dl>

    {{-- Brakujące strony: jawne zlecenie pobrania (plan → potwierdzenie → pobranie w tle). --}}
    @if ($ai['can_fetch'] && ($ai['fetch_target'] || $ai['serp_options']['results'] !== []))
      <div class="mt-5 rounded-md border border-slate-200 bg-slate-50 px-4 py-3">
        <h3 class="text-sm font-semibold text-slate-900">Pobierz brakujące strony</h3>
        <p class="mt-1 text-xs text-slate-500">Najpierw zobaczysz plan (zakres, robots.txt, pamięć) — nic nie zostanie pobrane bez potwierdzenia. Najwyżej 5 stron w zleceniu.</p>
        <div class="mt-3 flex flex-col gap-4 lg:flex-row lg:items-start">
          @if ($ai['fetch_target'])
            <x-panel.button variant="secondary" :href="PagesController::planUrl($projectId, ['mode' => 'topic', 'topic' => $ai['topic']])">Stronę docelową tematu</x-panel.button>
          @endif
          @if ($ai['serp_options']['results'] !== [] && $ai['serp_options']['keyword'] !== null)
            <form method="get" action="{{ PanelUrl::project($projectId, 'pages/fetch') }}" class="min-w-0 flex-1">
              <input type="hidden" name="mode" value="serp">
              <input type="hidden" name="keyword" value="{{ $ai['serp_options']['keyword']['id'] }}">
              <input type="hidden" name="topic" value="{{ $ai['topic'] }}">
              <p class="text-xs text-slate-600">Strony konkurencji z SERP frazy „{{ $ai['serp_options']['keyword']['keyword'] }}”:</p>
              <div class="mt-1 grid grid-cols-1 gap-1 sm:grid-cols-2">
                @foreach ($ai['serp_options']['results'] as $result)
                  <label class="flex min-w-0 items-center gap-2 text-xs text-slate-700">
                    <input type="checkbox" name="ranks[]" value="{{ $result['rank'] }}" @checked($loop->index < 3) class="rounded border-slate-300 text-brand-600">
                    <span class="shrink-0 tabular-nums text-slate-500">#{{ $result['rank'] }}</span>
                    <span class="min-w-0 truncate" title="{{ $result['url'] }}">{{ $result['host'] ?? $result['url'] }}</span>
                  </label>
                @endforeach
              </div>
              <x-panel.button type="submit" variant="secondary" class="mt-2">Zaplanuj pobranie zaznaczonych</x-panel.button>
            </form>
          @endif
        </div>
        @if ($ai['jobs'] !== [])
          <ul class="mt-3 space-y-1 text-xs text-slate-600">
            @foreach ($ai['jobs'] as $job)
              <li>
                <a href="{{ PagesController::jobUrl($projectId, $job['id']) }}" class="text-brand-600 hover:underline">Zlecenie z {{ Format::datetime($job['created_at']) }}</a>:
                {{ mb_strtolower(PageLabels::jobStatus($job['status'])) }}, pobrane {{ $job['items_succeeded'] }} z {{ $job['items_total'] }}
              </li>
            @endforeach
          </ul>
        @endif
      </div>
    @endif
  @endif

  {{-- Ostatnie analizy tematu. --}}
  <h3 class="mt-6 text-sm font-semibold text-slate-900">Analizy tematu</h3>
  @if ($ai['runs'] === [])
    <p class="mt-1 text-sm text-slate-500">{{ $ai['manage'] ? 'Brak analiz — przygotuj pierwszą analizę.' : 'Brak gotowych analiz AI dla tego tematu.' }}</p>
  @else
    <ul class="mt-2 divide-y divide-slate-100 text-sm">
      @foreach (array_slice($ai['runs'], 0, 5) as $run)
        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
          <a href="{{ AiController::runUrl($projectId, $run['id']) }}" class="font-medium text-brand-600 hover:underline">{{ $run['type_label'] }}</a>
          <span class="text-xs text-slate-500">{{ Format::datetime($run['created_at']) }}</span>
          <x-panel.ai-status :status="$run['status']" :decision="$run['decision']" />
          @if ($run['strategy_changed'] === true)
            <span class="text-xs text-amber-800">Strategia zmieniła się od analizy</span>
          @endif
          @if ($run['test_provider'])
            <span class="text-xs text-slate-500">wynik przykładowy (dostawca testowy)</span>
          @endif
        </li>
      @endforeach
    </ul>
    @if ($ai['runs_total'] > 5)
      <a href="{{ PanelUrl::project($projectId, 'ai') . '?topic=' . rawurlencode($ai['topic']) }}" class="mt-2 inline-block text-xs text-brand-600 hover:underline">Wszystkie analizy tematu ({{ Format::number($ai['runs_total']) }})</a>
    @endif
  @endif
</x-panel.card>
