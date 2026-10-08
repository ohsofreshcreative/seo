@extends('panel.layouts.app', ['active' => 'strategy'])

@section('title', 'Przygotuj analizę AI · ' . ($topic['label'] ?? 'Temat') . ' · ' . $project->name)

@php
  use App\Http\Controllers\Panel\AiController;
  use App\Http\Controllers\Panel\PagesController;
  use App\Http\Controllers\Panel\StrategyTopicsController;
  use App\Panel\Format;
  use App\Panel\PageLabels;
  use App\Panel\StrategyLabels;

  $projectId = $project->publicId;
  $topicUrl = StrategyTopicsController::topicUrl($projectId, $topic['id']);
  $needsExplicit = $mode === 'explicit' && ! $explicit;
  $runnable = ! $needsExplicit && $readiness['runnable'] && $plan['runnable'] && $active === null;
  $fetchTopic = $canFetch && $section['fetch_target'] && array_intersect($readiness['reason_codes'], ['page_not_fetched', 'page_fetch_failed', 'page_content_empty']) !== [];
  $serpRanks = array_slice(array_column($section['serp_options']['results'], 'rank'), 0, 3);
  $fetchSerp = $canFetch && in_array('no_competitor_snapshots', $readiness['reason_codes'], true) && $serpRanks !== [] && $section['serp_options']['keyword'] !== null;
  $budgetToday = $budget['remaining']['today'] ?? null;
@endphp

@section('content')
  <p class="mb-4 text-sm"><a href="{{ $topicUrl }}#analiza-ai" class="text-brand-600 hover:underline">← Temat: {{ $topic['label'] ?? '—' }}</a></p>

  <x-panel.page-header title="Przygotuj analizę AI" :description="'Temat: ' . ($topic['label'] ?? '—') . '. Plan i koszt liczone z zapisanych danych — bez wywołania modelu.'" />

  <div class="mb-6 flex flex-wrap items-center gap-2 text-sm">
    <span class="text-slate-500">Działanie Strategii:</span>
    <x-panel.strategy-action :action="$topic['action']" />
    <span class="text-xs text-slate-500">— analiza AI nie zmienia działania ani statusu pracy tematu.</span>
  </div>

  {{-- 1. Rodzaj analizy. --}}
  <x-panel.card>
    <h2 class="text-base font-semibold text-slate-900">1. Rodzaj analizy</h2>
    <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
      @foreach ($section['types'] as $option)
        @php($selected = $option['type'] === $type)
        @php($available = $option['readiness']['state'] !== 'blocked')
        <div @class(['min-w-0 rounded-lg border p-4', 'border-brand-500 ring-1 ring-brand-500' => $selected, 'border-slate-200' => ! $selected, 'opacity-60' => ! $available && ! $selected])>
          <div class="flex flex-wrap items-center gap-2">
            @if ($available && ! $selected)
              <a href="{{ AiController::prepareUrl($projectId, $topic['id'], $option['type']) }}" class="text-sm font-semibold text-brand-600 hover:underline">{{ $option['label'] }}</a>
            @else
              <span class="text-sm font-semibold text-slate-900">{{ $option['label'] }}</span>
            @endif
            @if ($option['recommended'])
              <span class="inline-flex rounded bg-brand-700 px-1.5 py-0.5 text-xs font-medium text-white">Zalecany</span>
            @endif
          </div>
          <p class="mt-1 text-xs text-slate-500">{{ $option['description'] }}</p>
          <div class="mt-2 flex flex-wrap items-center gap-2">
            <x-panel.readiness :state="$option['readiness']['state']" />
            <span class="text-xs text-slate-500">{{ $option['readiness']['compatibility'] }}</span>
          </div>
        </div>
      @endforeach
    </div>
  </x-panel.card>

  {{-- Typ niezalecany przez Strategię: osobny, świadomy wybór (zapisywany w historii analizy). --}}
  @if ($needsExplicit)
    <div class="mt-6 rounded-lg border border-amber-300 bg-amber-50 px-5 py-4">
      <h2 class="text-sm font-semibold text-amber-900">Ten rodzaj analizy nie jest zalecany dla działania „{{ StrategyLabels::action($topic['action']) }}”</h2>
      <p class="mt-1 text-sm text-amber-900">
        Możesz go wybrać świadomie. Strategia i status pracy tematu się nie zmienią, wnioski będą miały niższą pewność, a Twój wybór zostanie zapisany w historii analizy.
      </p>
      <div class="mt-3 flex flex-wrap gap-2">
        <x-panel.button :href="AiController::prepareUrl($projectId, $topic['id'], $type, true)">Wybieram ten typ świadomie</x-panel.button>
        <x-panel.button variant="secondary" :href="$topicUrl . '#analiza-ai'">Wróć do tematu</x-panel.button>
      </div>
    </div>
  @else
    {{-- 2. Gotowość danych. --}}
    <x-panel.card class="mt-6">
      <div class="flex flex-wrap items-center gap-3">
        <h2 class="text-base font-semibold text-slate-900">2. Dane do analizy</h2>
        <x-panel.readiness :state="$readiness['state']" />
        @if ($explicit)
          <span class="text-xs text-amber-800">Świadomy wybór typu niezalecanego przez Strategię</span>
        @endif
      </div>

      @if ($readiness['reasons'] !== [])
        <div class="mt-3 rounded-md bg-red-50 px-4 py-3">
          <p class="text-sm font-medium text-red-800">{{ $readiness['state'] === 'blocked' ? 'Analiza jest niedostępna dla tego tematu:' : 'Brakuje danych — analiza nie zostanie uruchomiona:' }}</p>
          <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-red-800">
            @foreach ($readiness['reasons'] as $reason)
              <li>{{ $reason }}</li>
            @endforeach
          </ul>
          @if ($fetchTopic || $fetchSerp)
            <div class="mt-3 flex flex-wrap gap-2">
              @if ($fetchTopic)
                <x-panel.button variant="secondary" :href="PagesController::planUrl($projectId, ['mode' => 'topic', 'topic' => $topic['id']])">Pobierz stronę docelową</x-panel.button>
              @endif
              @if ($fetchSerp)
                <x-panel.button variant="secondary" :href="PagesController::planUrl($projectId, ['mode' => 'serp', 'keyword' => $section['serp_options']['keyword']['id'], 'ranks' => $serpRanks, 'topic' => $topic['id']])">Pobierz strony konkurencji z SERP (TOP {{ count($serpRanks) }})</x-panel.button>
              @endif
            </div>
            <p class="mt-2 text-xs text-red-700">Najpierw zobaczysz plan pobrania — nic nie zostanie pobrane bez potwierdzenia.</p>
          @endif
        </div>
      @endif

      @if ($readiness['limitations'] !== [])
        <div class="mt-3 rounded-md bg-amber-50 px-4 py-3">
          <p class="text-sm font-medium text-amber-900">Analiza możliwa w ograniczonym zakresie — raport ujawni te ograniczenia:</p>
          <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-amber-900">
            @foreach ($readiness['limitations'] as $limitation)
              <li>{{ $limitation }}</li>
            @endforeach
          </ul>
        </div>
      @endif

      @if ($readiness['notes'] !== [])
        <ul class="mt-3 list-disc space-y-0.5 pl-5 text-xs text-slate-500">
          @foreach ($readiness['notes'] as $note)
            <li>{{ $note }}</li>
          @endforeach
        </ul>
      @endif

      {{-- Podgląd danych wejściowych (z kontekstu planu — bez treści stron). --}}
      <dl class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
        <div class="min-w-0">
          <dt class="text-xs text-slate-500">Strona docelowa</dt>
          <dd class="mt-1 min-w-0">
            @if ($preview['target']['url'] !== null && PageLabels::safeUrl($preview['target']['url']))
              <a href="{{ $preview['target']['url'] }}" target="_blank" rel="noopener noreferrer" class="block truncate text-brand-600 hover:underline" title="{{ $preview['target']['url'] }}">{{ $preview['target']['url'] }}</a>
            @endif
            <span class="text-xs text-slate-500">{{ $preview['target']['label'] ?? 'Brak znanej strony' }}</span>
          </dd>
        </div>
        <div>
          <dt class="text-xs text-slate-500">Treść strony docelowej</dt>
          <dd class="mt-1">
            @if ($preview['page']['available'])
              pobrana {{ Format::datetime($preview['page']['fetched_at']) }}{{ $preview['page']['fresh'] ? '' : ' (starsza kopia)' }},
              jakość {{ mb_strtolower(PageLabels::quality($preview['page']['quality'])) }}, {{ Format::number((int) $preview['page']['word_count']) }} słów
            @else
              <span class="text-slate-500">{{ $preview['page']['failed'] ? 'Ostatnie pobranie nieudane' : 'Nie pobrano' }} — treść strony nie trafi do analizy</span>
            @endif
          </dd>
        </div>
        <div class="min-w-0 sm:col-span-2">
          <dt class="text-xs text-slate-500">Strony konkurencji ({{ count($preview['competitors']) }} w analizie, powiązanych z SERP: {{ Format::number($preview['competitors_linked']) }})</dt>
          <dd class="mt-1">
            @if ($preview['competitors'] === [])
              <span class="text-slate-500">Brak pobranych stron konkurencji</span>
            @else
              <ul class="space-y-1">
                @foreach ($preview['competitors'] as $competitor)
                  <li class="flex min-w-0 flex-wrap items-center gap-x-2 text-xs">
                    <span class="tabular-nums text-slate-500">#{{ $competitor['rank'] ?? '—' }}</span>
                    <span class="min-w-0 truncate font-medium text-slate-800">{{ $competitor['domain'] ?? $competitor['url'] }}</span>
                    <span class="text-slate-500">{{ $competitor['available'] ? 'pobrana ' . Format::date($competitor['fetched_at']) : 'nie pobrano' }}</span>
                    @if ($competitor['available'] && ! $competitor['usable'])
                      <span class="text-amber-800">pominięta (niepełna treść)</span>
                    @endif
                  </li>
                @endforeach
              </ul>
            @endif
          </dd>
        </div>
        <div>
          <dt class="text-xs text-slate-500">Pomiar SERP</dt>
          <dd class="mt-1">{{ $preview['serp'] === null ? 'Brak' : Format::datetime($preview['serp']['measured_at']) . ($preview['serp']['keyword'] !== null ? ' („' . $preview['serp']['keyword'] . '”)' : '') }}</dd>
        </div>
        <div>
          <dt class="text-xs text-slate-500">Google Search Console</dt>
          <dd class="mt-1">
            @if (! $preview['gsc']['connected'])
              Brak połączenia
            @elseif ($preview['gsc']['totals'] === null)
              Brak danych tematu
            @else
              {{ Format::number((int) $preview['gsc']['totals']['clicks']) }} kliknięć, {{ Format::number((int) $preview['gsc']['totals']['impressions']) }} wyświetleń (dane do {{ Format::date($preview['gsc']['as_of']) }})
            @endif
          </dd>
        </div>
        <div>
          <dt class="text-xs text-slate-500">Frazy w analizie</dt>
          <dd class="mt-1">{{ Format::number((int) $preview['keywords']['in_context']) }} z {{ Format::number((int) $preview['keywords']['total']) }}</dd>
        </div>
        <div>
          <dt class="text-xs text-slate-500">Inne tematy (linkowanie wewnętrzne)</dt>
          <dd class="mt-1">{{ Format::number($preview['site_pages']) }}</dd>
        </div>
      </dl>

      @if ($preview['data_gaps'] !== [])
        <details class="mt-4 text-sm">
          <summary class="cursor-pointer text-xs font-medium text-slate-600">Braki danych przekazywane do analizy ({{ count($preview['data_gaps']) }})</summary>
          <ul class="mt-2 list-disc space-y-0.5 pl-5 text-xs text-slate-600">
            @foreach ($preview['data_gaps'] as $gap)
              <li>{{ $gap }}</li>
            @endforeach
          </ul>
        </details>
      @endif
    </x-panel.card>

    {{-- 3. Koszt i jawne potwierdzenie. --}}
    <x-panel.card id="koszt" class="mt-6 scroll-mt-20">
      <h2 class="text-base font-semibold text-slate-900">3. Koszt i potwierdzenie</h2>

      @if (count($providers) > 1)
        <form method="get" action="{{ $topicUrl }}/ai" class="mt-3 flex flex-wrap items-end gap-3">
          <input type="hidden" name="type" value="{{ $type }}">
          @if ($explicit)<input type="hidden" name="explicit" value="1">@endif
          <label class="text-sm">
            <span class="block text-xs text-slate-500">Dostawca</span>
            <select name="provider" class="mt-1 rounded-md border-slate-300 text-sm">
              @foreach ($providers as $option)
                <option value="{{ $option['id'] }}" @selected($option['id'] === $provider)>{{ $option['label'] }}{{ $option['model'] ? ' — ' . $option['model'] : '' }}</option>
              @endforeach
            </select>
          </label>
          <x-panel.button type="submit" variant="secondary">Przelicz plan</x-panel.button>
        </form>
      @else
        <p class="mt-2 text-sm text-slate-600">Dostawca: <span class="font-medium">Dostawca testowy (bez kosztów)</span>. Płatny dostawca AI nie jest włączony w konfiguracji serwera — wynik będzie przykładowy.</p>
      @endif

      <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-4">
        <div>
          <dt class="text-xs text-slate-500">Model</dt>
          <dd class="mt-1 font-medium text-slate-900">{{ $plan['model'] ?? '—' }}</dd>
        </div>
        <div>
          <dt class="text-xs text-slate-500">Szacowane tokeny wejścia</dt>
          <dd class="mt-1 tabular-nums text-slate-900">{{ Format::number($plan['input_tokens']) }}</dd>
        </div>
        <div>
          <dt class="text-xs text-slate-500">Koszt maksymalny</dt>
          <dd class="mt-1 font-semibold tabular-nums text-slate-900">{{ $plan['paid'] ? Format::usd($plan['max_cost'], 4) : '0 USD (test)' }}</dd>
        </div>
        @if ($plan['paid'])
          <div>
            <dt class="text-xs text-slate-500">Budżet AI (pozostało dziś / w miesiącu)</dt>
            <dd class="mt-1 tabular-nums text-slate-900">{{ Format::usd($budgetToday, 2) }} / {{ Format::usd($budget['remaining']['month'] ?? null, 2) }}</dd>
          </div>
        @endif
      </dl>
      @if ($plan['paid'])
        <p class="mt-2 text-xs text-slate-500">Koszt maksymalny zostanie zarezerwowany przy zleceniu; rozliczenie według faktycznego zużycia. Budżet AI jest oddzielny od DataForSEO.</p>
      @endif

      @if ($plan['blockers'] !== [] && $readiness['runnable'])
        <div class="mt-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
          <p class="font-medium">Analiza nie może zostać zlecona:</p>
          <ul class="mt-1 list-disc space-y-0.5 pl-5">
            @foreach ($plan['blockers'] as $blocker)
              <li>{{ $blocker }}</li>
            @endforeach
          </ul>
        </div>
      @endif

      @if ($active !== null)
        <div class="mt-4 rounded-md bg-sky-50 px-4 py-3 text-sm text-sky-900">
          Analiza tego typu jest już w toku ({{ mb_strtolower($active['status_label']) }}). <a href="{{ AiController::runUrl($projectId, $active['id']) }}" class="font-medium underline">Zobacz status</a>
        </div>
      @elseif ($runnable)
        <form method="post" action="{{ $topicUrl }}/ai" class="mt-5 space-y-3" x-data="{ sending: false }" @submit="sending = true">
          <x-panel.nonce />
          <input type="hidden" name="type" value="{{ $type }}">
          <input type="hidden" name="provider" value="{{ $provider }}">
          <input type="hidden" name="plan" value="{{ $plan['fingerprint'] }}">
          @if ($explicit)
            <input type="hidden" name="explicit" value="1">
            <label class="flex items-start gap-2 text-sm text-slate-800">
              <input type="checkbox" name="explicit_confirmed" value="1" required class="mt-0.5 rounded border-slate-300 text-brand-600">
              <span>Potwierdzam świadomy wybór typu niezalecanego przez Strategię (zostanie zapisany w historii analizy).</span>
            </label>
          @endif
          @if ($duplicate !== null)
            <div class="rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-700">
              Ta sama analiza (te same dane i ustawienia) jest już gotowa: <a href="{{ AiController::runUrl($projectId, $duplicate['id']) }}" class="font-medium text-brand-600 hover:underline">otwórz raport</a>.
              <label class="mt-2 flex items-start gap-2">
                <input type="checkbox" name="repeat" value="1" required class="mt-0.5 rounded border-slate-300 text-brand-600">
                <span>Mimo to wygeneruj ponownie (nowe wywołanie{{ $plan['paid'] ? ' i nowy koszt' : '' }}).</span>
              </label>
            </div>
          @endif
          @if ($plan['paid'])
            <label class="flex items-start gap-2 text-sm text-slate-800">
              <input type="checkbox" name="confirmed" value="1" required class="mt-0.5 rounded border-slate-300 text-brand-600">
              <span>Akceptuję koszt maksymalny {{ Format::usd($plan['max_cost'], 4) }} (płatne wywołanie modelu {{ $plan['model'] }}).</span>
            </label>
          @endif
          <div class="flex flex-wrap items-center gap-3">
            <x-panel.button type="submit" x-bind:disabled="sending">{{ $plan['paid'] ? 'Zleć płatną analizę' : 'Zleć analizę (bez kosztów)' }}</x-panel.button>
            <span class="text-xs text-slate-500">Analizę wykona przetwarzanie w tle (zwykle do kilku minut). Ponowne kliknięcie nie utworzy drugiego zlecenia.</span>
          </div>
        </form>
      @elseif (! $readiness['runnable'])
        <p class="mt-4 text-sm text-slate-500">Uzupełnij brakujące dane (punkt 2), aby zlecić analizę.</p>
      @endif
    </x-panel.card>
  @endif
@endsection
