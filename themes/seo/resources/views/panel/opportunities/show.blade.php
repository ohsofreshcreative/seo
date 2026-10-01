@extends('panel.layouts.app', ['active' => 'opportunities'])

@section('title', $opportunity->title() . ' · Szanse SEO · ' . $project->name)

@php
  use App\Http\Controllers\Panel\OpportunitiesController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Opportunities\OpportunityExplainer;
  use OsfSeo\Opportunities\OpportunityStatus;
  use OsfSeo\Opportunities\OpportunityType;
  use OsfSeo\Opportunities\Stats;
  use OsfSeo\Opportunities\Text;

  $evidence = $opportunity->evidence;
  $type = $opportunity->type;
  $period = $evidence['period'] ?? null;
  $current = $opportunity->current();
  $previous = $opportunity->previous();
  $pageTotals = $evidence['page_totals'] ?? null;
  $keywords = $evidence['keywords'] ?? [];
  $shown = count($keywords);
  $totalKeywords = (int) ($evidence['keywords_total'] ?? $shown);
  $isLink = fn (?string $url): bool => $url !== null && preg_match('#^https?://#i', $url) === 1;
  $status = $old['status'] ?? $opportunity->status->value;
  $listUrl = PanelUrl::project($project->publicId, 'opportunities') . ($days !== 28 ? '?days=' . $days : '');
@endphp

@section('content')
  <p class="mb-4 text-sm"><a href="{{ $listUrl }}" class="text-brand-600 hover:underline">← Szanse SEO</a></p>

  <x-panel.page-header :title="$opportunity->title()" :description="$type->label()">
    <x-slot:actions>
      <x-panel.opportunity-status :status="$opportunity->status" />
      @if ($opportunity->pageUrl && $isLink($opportunity->pageUrl))
        <x-panel.button variant="secondary" :href="$opportunity->pageUrl" target="_blank" rel="noopener noreferrer">Otwórz stronę ↗</x-panel.button>
      @endif
    </x-slot:actions>
  </x-panel.page-header>

  @if ($opportunity->state !== \OsfSeo\Opportunities\OpportunityState::Active)
    <div class="mb-6 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
      {{ $opportunity->state->label() }}@if ($opportunity->inactiveSince) (od {{ Format::datetime($opportunity->inactiveSince) }})@endif.
      Poniżej ostatnie zapisane dowody — nie są to aktualne rekomendacje.
    </div>
  @elseif (! $opportunity->detectedInPeriod)
    <div class="mb-6 rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
      W okresie {{ $days }} dni ten sygnał nie spełnia kryteriów — pokazujemy dowody z okresu {{ $opportunity->periodDays }} dni.
    </div>
  @endif

  <div class="mb-6 flex flex-wrap items-center gap-2 text-sm">
    <span class="text-slate-600">Okres:</span>
    @foreach (\OsfSeo\Analytics\Period::ALLOWED_DAYS as $option)
      <a href="{{ OpportunitiesController::detailUrl($project->publicId, $opportunity->publicId, $option) }}" @class([
        'whitespace-nowrap rounded-md px-3 py-1.5 font-medium',
        'bg-brand-700 text-white' => $opportunity->periodDays === $option,
        'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50' => $opportunity->periodDays !== $option,
      ])>{{ $option }} dni @if (! in_array($option, $detectedPeriods, true)) <span class="text-xs opacity-75">(bez sygnału)</span>@endif</a>
    @endforeach
    @if ($period)
      <span class="text-slate-500">{{ Text::range($period['current']) }} vs {{ Text::range($period['previous']) }} (daty GSC, czas pacyficzny)</span>
    @endif
  </div>

  <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <x-panel.card class="lg:col-span-2">
      <h2 class="text-base font-semibold text-slate-900">Dlaczego ta szansa?</h2>
      <p class="mt-2 text-sm text-slate-700">{{ $opportunity->summary() }}</p>

      <h3 class="mt-6 text-sm font-semibold text-slate-900">Co sprawdzić</h3>
      <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-700">
        @foreach ($opportunity->recommendations() as $step)
          <li>{{ $step }}</li>
        @endforeach
      </ol>
      <p class="mt-3 text-xs text-slate-500">Rekomendacje to hipotezy na podstawie danych GSC — system nie sprawdzał treści strony, title, opisu, linków ani wyników konkurencji.</p>
    </x-panel.card>

    <div class="space-y-6">
      <x-panel.card>
        <div class="flex items-center gap-4">
          <x-panel.score :value="$opportunity->priority" />
          <div>
            <p class="text-sm font-semibold text-slate-900">Priorytet {{ $opportunity->priority }}/100</p>
            <p class="text-xs text-slate-500">Jak bardzo warto to sprawdzić — nie prognoza wzrostu.</p>
          </div>
        </div>
        <dl class="mt-4 space-y-2 text-sm">
          @foreach (OpportunityExplainer::scoreBreakdown($type, $evidence) as $component)
            <div>
              <dt class="flex justify-between font-medium text-slate-700"><span>{{ $component['label'] }}</span><span class="tabular-nums">{{ Format::number($component['points'], 1) }} / {{ $component['max'] }}</span></dt>
              <dd class="text-xs text-slate-500">{{ $component['text'] }}</dd>
            </div>
          @endforeach
        </dl>
      </x-panel.card>

      <x-panel.card>
        <x-panel.confidence :level="$opportunity->confidence" />
        <ul class="mt-3 space-y-1 text-sm">
          @foreach (OpportunityExplainer::confidenceReasons($evidence) as $reason)
            <li @class(['flex gap-2', 'text-slate-700' => $reason['ok'], 'text-slate-500' => ! $reason['ok']])>
              <span aria-hidden="true">{{ $reason['ok'] ? '✓' : '–' }}</span><span>{{ $reason['text'] }}</span>
            </li>
          @endforeach
        </ul>
      </x-panel.card>
    </div>
  </div>

  {{-- Metryki grupy: bieżący vs poprzedni okres (sumy fraz z dowodów; CTR i pozycja z sum). --}}
  <x-panel.card class="mt-6">
    <h2 class="text-base font-semibold text-slate-900">Metryki</h2>
    <div class="mt-3 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
            <th class="py-2 pr-4 font-medium">Zakres</th>
            <th class="py-2 pr-4 text-right font-medium">Wyświetlenia</th>
            <th class="py-2 pr-4 text-right font-medium">Kliknięcia</th>
            <th class="py-2 pr-4 text-right font-medium">CTR</th>
            <th class="py-2 text-right font-medium">Średnia pozycja (GSC)</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 tabular-nums">
          @foreach (array_filter([
            [$type === OpportunityType::Cannibalization ? 'Frazy (suma adresów), bieżący okres' : 'Frazy szansy, bieżący okres', $current],
            [$type === OpportunityType::Cannibalization ? 'Frazy (suma adresów), poprzedni okres' : 'Frazy szansy, poprzedni okres', $previous],
            $pageTotals ? ['Cała podstrona (widoczne frazy), bieżący okres', Stats::fromArray($pageTotals['current'])] : null,
            $pageTotals ? ['Cała podstrona (widoczne frazy), poprzedni okres', Stats::fromArray($pageTotals['previous'])] : null,
          ]) as [$label, $stats])
            <tr>
              <td class="py-2 pr-4 text-slate-700">{{ $label }}</td>
              <td class="py-2 pr-4 text-right">{{ Format::number($stats->impressions) }}</td>
              <td class="py-2 pr-4 text-right">{{ Format::number($stats->clicks) }}</td>
              <td class="py-2 pr-4 text-right">{{ Format::percent($stats->ctr()) }}</td>
              <td class="py-2 text-right">{{ Format::position($stats->position()) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @if ($type === OpportunityType::LowCtr && isset($evidence['details']['expected_ctr']))
      <p class="mt-3 text-sm text-slate-600">Referencyjny CTR dla pozycji tych fraz: ok. {{ Format::percent((float) $evidence['details']['expected_ctr']) }} (ważony wyświetleniami fraz).</p>
    @endif
    <p class="mt-2 text-xs text-slate-500">Sumy frazy i podstrony pochodzą z fraz widocznych w GSC — nie obejmują zapytań zanonimizowanych, więc są mniejsze niż sumy projektu.</p>
  </x-panel.card>

  {{-- Dowody: frazy (albo frazy z adresami przy kanibalizacji). --}}
  <x-panel.card class="mt-6">
    <h2 class="text-base font-semibold text-slate-900">
      {{ $type === OpportunityType::Cannibalization ? 'Frazy i adresy' : 'Frazy' }}
      <span class="text-sm font-normal text-slate-500">({{ $shown < $totalKeywords ? $shown . ' najważniejszych z ' . $totalKeywords : $totalKeywords }})</span>
    </h2>

    @if ($type === OpportunityType::Cannibalization)
      <div class="mt-4 space-y-6">
        @foreach ($keywords as $query)
          <div>
            <p class="text-sm font-semibold text-slate-900">„{{ $query['keyword'] }}”
              <span class="font-normal text-slate-500">· {{ Format::number($query['current']['impressions']) }} wyświetleń (suma adresów)</span>
              @if ($query['dominant_changed'])
                <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-800">dominujący adres zmienił się względem poprzedniego okresu</span>
              @endif
              @if ($query['switches'] > 0)
                <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-800">{{ $query['switches'] }} {{ Text::plural($query['switches'], 'zmiana', 'zmiany', 'zmian') }} lidera w {{ $query['segments'] }} odcinkach po {{ $query['segment_days'] }} {{ $query['segment_days'] === 1 ? 'dniu' : 'dni' }}</span>
              @endif
            </p>
            <div class="mt-2 overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead>
                  <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="py-1.5 pr-4 font-medium">Adres</th>
                    <th class="py-1.5 pr-4 text-right font-medium">Wyświetlenia (udział)</th>
                    <th class="py-1.5 pr-4 text-right font-medium">Kliknięcia</th>
                    <th class="py-1.5 pr-4 text-right font-medium">Średnia pozycja (GSC)</th>
                    <th class="py-1.5 text-right font-medium">Poprzedni okres</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 tabular-nums">
                  @foreach ($query['urls'] as $url)
                    @php($urlCurrent = Stats::fromArray($url['current']))
                    @php($urlPrevious = Stats::fromArray($url['previous']))
                    <tr @class(['text-slate-400' => ! $url['meaningful']])>
                      <td class="max-w-sm py-1.5 pr-4">
                        @if ($isLink($url['url']))
                          <a href="{{ $url['url'] }}" target="_blank" rel="noopener noreferrer" class="break-all text-brand-600 hover:underline">{{ Text::path($url['url']) }}</a>
                        @else
                          <span class="break-all">{{ $url['url'] }}</span>
                        @endif
                        @if ($url['url'] === $query['dominant_current'])<span class="ml-1 text-xs text-slate-500">(najwięcej kliknięć)</span>@endif
                      </td>
                      <td class="py-1.5 pr-4 text-right">{{ Format::number($urlCurrent->impressions) }} ({{ Format::percent((float) $url['share'], 0) }})</td>
                      <td class="py-1.5 pr-4 text-right">{{ Format::number($urlCurrent->clicks) }}</td>
                      <td class="py-1.5 pr-4 text-right">{{ Format::position($urlCurrent->position()) }}</td>
                      <td class="py-1.5 text-right text-slate-500">{{ Format::number($urlPrevious->impressions) }} wyśw.@if ($url['previous_share'] !== null) ({{ Format::percent((float) $url['previous_share'], 0) }})@endif</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </div>
        @endforeach
      </div>
      <p class="mt-4 text-xs text-slate-500">Wiele adresów dla jednej frazy nie musi być problemem (np. różne intencje, rozszerzone wyniki). Adresy różniące się tylko kotwicą (#…) traktujemy jako jedną podstronę; szare wiersze mają mały udział.</p>
    @else
      <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
              <th class="py-2 pr-4 font-medium">Fraza</th>
              <th class="py-2 pr-4 text-right font-medium">Wyświetlenia</th>
              <th class="py-2 pr-4 text-right font-medium">Kliknięcia</th>
              <th class="py-2 pr-4 text-right font-medium">CTR</th>
              <th class="py-2 pr-4 text-right font-medium">Średnia pozycja (GSC)</th>
              <th class="py-2 text-right font-medium">
                @switch($type)
                  @case(OpportunityType::LowCtr) Referencyjny CTR / luka @break
                  @case(OpportunityType::Decline) Sygnały spadku @break
                  @default Cel / potencjał
                @endswitch
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 tabular-nums">
            @foreach ($keywords as $item)
              @php($itemCurrent = Stats::fromArray($item['current']))
              @php($itemPrevious = Stats::fromArray($item['previous']))
              <tr>
                <td class="max-w-xs break-words py-2 pr-4 text-slate-900">{{ $item['keyword'] }}</td>
                <td class="py-2 pr-4 text-right">{{ Format::number($itemPrevious->impressions) }} → {{ Format::number($itemCurrent->impressions) }}</td>
                <td class="py-2 pr-4 text-right">{{ Format::number($itemPrevious->clicks) }} → {{ Format::number($itemCurrent->clicks) }}</td>
                <td class="py-2 pr-4 text-right">{{ Format::percent($itemPrevious->ctr()) }} → {{ Format::percent($itemCurrent->ctr()) }}</td>
                <td class="py-2 pr-4 text-right">{{ Format::position($itemPrevious->position()) }} → {{ Format::position($itemCurrent->position()) }}</td>
                <td class="py-2 text-right text-slate-600">
                  @switch($type)
                    @case(OpportunityType::LowCtr)
                      {{ Format::percent((float) $item['reference_ctr']) }} (poz. {{ $item['bucket'] }}) · ok. {{ Format::number((float) $item['click_gap']) }} klik.
                      @break
                    @case(OpportunityType::Decline)
                      @if (! empty($item['context']))
                        <span class="text-slate-400">fraza podstrony</span>
                      @else
                        {{ implode(', ', array_map(static fn ($signal) => ['clicks' => 'kliknięcia', 'impressions' => 'wyświetlenia', 'position' => 'pozycja'][$signal] ?? $signal, array_keys($item['signals'] ?? []))) }}
                      @endif
                      @break
                    @default
                      TOP {{ $item['target'] }} · ok. {{ Format::number((float) $item['potential_clicks']) }} klik.
                  @endswitch
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <p class="mt-3 text-xs text-slate-500">Wartości: poprzedni okres → bieżący. Pozycja to średnia pozycja (GSC) ważona wyświetleniami — nie dokładny ranking.
        @if ($type === OpportunityType::NearTop || $type === OpportunityType::WeakPosition) Potencjał = wyświetlenia × referencyjny CTR pozycji docelowej − obecne kliknięcia: szacunek, nie obietnica. @endif
      </p>
    @endif
  </x-panel.card>

  @if ($type === OpportunityType::LowCtr && ! empty($evidence['details']['reference']))
    <details class="mt-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
      <summary class="cursor-pointer text-sm font-semibold text-slate-900">Referencyjny CTR według średniej pozycji (GSC)</summary>
      <table class="mt-3 min-w-full text-sm">
        <thead><tr class="text-left text-xs uppercase tracking-wide text-slate-500"><th class="py-1.5 pr-4 font-medium">Pozycja</th><th class="py-1.5 pr-4 text-right font-medium">CTR</th><th class="py-1.5 font-medium">Źródło</th></tr></thead>
        <tbody class="divide-y divide-slate-100 tabular-nums">
          @foreach ($evidence['details']['reference'] as $bucket)
            <tr>
              <td class="py-1.5 pr-4">{{ $bucket['label'] }}</td>
              <td class="py-1.5 pr-4 text-right">{{ Format::percent((float) $bucket['ctr']) }}</td>
              <td class="py-1.5 text-slate-600">{{ $bucket['source'] === 'project' ? 'mediana ' . $bucket['keywords'] . ' fraz projektu' : 'wartość domyślna (za mało fraz projektu: ' . $bucket['keywords'] . ')' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </details>
  @endif

  <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Praca nad szansą</h2>
      @if ($canManage)
        <form method="post" action="{{ OpportunitiesController::detailUrl($project->publicId, $opportunity->publicId) }}" class="mt-4 space-y-4" x-data="{ status: @js($status) }">
          <x-panel.nonce />
          <input type="hidden" name="days" value="{{ $days }}">
          <div>
            <label for="field-status" class="block text-sm font-medium text-slate-700">Status</label>
            <select id="field-status" name="status" x-model="status" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
              @foreach (OpportunityStatus::cases() as $option)
                <option value="{{ $option->value }}" @selected($status === $option->value)>{{ $option->label() }}</option>
              @endforeach
            </select>
            @if (isset($errors['status']))<p class="mt-1 text-sm text-red-700">{{ $errors['status'] }}</p>@endif
          </div>
          <div x-show="status === 'completed'" x-cloak>
            <x-panel.field name="completed_on" type="date" label="Data wdrożenia" :value="$old['completed_on'] ?? ($opportunity->completedOn ?? wp_date('Y-m-d'))"
              :error="$errors['completed_on'] ?? null" help="Od tej daty liczymy obserwację „po wdrożeniu”." />
          </div>
          <div>
            <label for="field-note" class="block text-sm font-medium text-slate-700">Notatka</label>
            <textarea id="field-note" name="note" rows="4" maxlength="{{ \OsfSeo\Opportunities\OpportunityService::NOTE_MAX_LENGTH }}"
              class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">{{ $old['note'] ?? $opportunity->note }}</textarea>
            @if (isset($errors['note']))<p class="mt-1 text-sm text-red-700">{{ $errors['note'] }}</p>@endif
          </div>
          <x-panel.button type="submit">Zapisz</x-panel.button>
        </form>
      @else
        <dl class="mt-4 space-y-2 text-sm">
          <div><dt class="inline text-slate-500">Status:</dt> <dd class="inline"><x-panel.opportunity-status :status="$opportunity->status" /></dd></div>
          @if ($opportunity->completedOn)<div><dt class="inline text-slate-500">Data wdrożenia:</dt> <dd class="inline">{{ Format::date($opportunity->completedOn) }}</dd></div>@endif
          @if ($opportunity->note)<div><dt class="text-slate-500">Notatka:</dt> <dd class="mt-1 whitespace-pre-line text-slate-700">{{ $opportunity->note }}</dd></div>@endif
        </dl>
      @endif
    </x-panel.card>

    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Historia</h2>
      <dl class="mt-4 space-y-2 text-sm">
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Pierwsze wykrycie</dt><dd>{{ Format::datetime($opportunity->firstDetectedAt) }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Ostatnie wykrycie</dt><dd>{{ Format::datetime($opportunity->lastDetectedAt) }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Dane GSC do</dt><dd>{{ Format::date($opportunity->latestDate) }}</dd></div>
        @if ($opportunity->statusChangedAt)
          <div class="flex justify-between gap-4"><dt class="text-slate-500">Zmiana statusu</dt><dd>{{ Format::datetime($opportunity->statusChangedAt) }}</dd></div>
        @endif
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Property GSC</dt><dd class="break-all text-right">{{ $opportunity->property }}</dd></div>
      </dl>

      @if ($after)
        <div class="mt-6 border-t border-slate-100 pt-4">
          <h3 class="text-sm font-semibold text-slate-900">Po wdrożeniu (obserwacja)</h3>
          @if (! $after['ready'])
            <p class="mt-2 text-sm text-slate-600">Za mało danych po wdrożeniu: {{ $after['available_days'] }} z {{ $after['required_days'] }} dni ({{ Text::range($after['window']) }}).</p>
          @else
            <table class="mt-2 min-w-full text-sm">
              <thead><tr class="text-left text-xs uppercase tracking-wide text-slate-500"><th class="py-1.5 pr-3 font-medium">Okres</th><th class="py-1.5 pr-3 text-right font-medium">Wyśw.</th><th class="py-1.5 pr-3 text-right font-medium">Klik.</th><th class="py-1.5 text-right font-medium">Śr. pozycja</th></tr></thead>
              <tbody class="divide-y divide-slate-100 tabular-nums">
                @foreach ([['Przed: ' . Text::range($after['before_window']), $after['before']], ['Po: ' . Text::range($after['window']), $after['after']]] as [$label, $stats])
                  <tr>
                    <td class="py-1.5 pr-3 text-slate-700">{{ $label }}</td>
                    <td class="py-1.5 pr-3 text-right">{{ Format::number($stats->impressions) }}</td>
                    <td class="py-1.5 pr-3 text-right">{{ Format::number($stats->clicks) }}</td>
                    <td class="py-1.5 text-right">{{ Format::position($stats->position()) }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
            <p class="mt-2 text-sm text-slate-700">Po wdrożeniu kliknięcia tych fraz zmieniły się o {{ Format::signed($after['after']->clicks - $after['before']->clicks) }} względem okresu bazowego.</p>
          @endif
          <p class="mt-2 text-xs text-slate-500">To obserwacja, nie dowód przyczynowości — na wynik mogły wpłynąć też sezonowość, zmiany w Google czy działania konkurencji.</p>
        </div>
      @endif
    </x-panel.card>
  </div>
@endsection
