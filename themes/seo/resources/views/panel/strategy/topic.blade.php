@extends('panel.layouts.app', ['active' => 'strategy'])

@section('title', ($topic->label ?? 'Temat') . ' · Strategia · ' . $project->name)

@php
  use App\Http\Controllers\Panel\DiscoveryController;
  use App\Http\Controllers\Panel\GapContentController;
  use App\Http\Controllers\Panel\GapKeywordsController;
  use App\Http\Controllers\Panel\OpportunitiesController;
  use App\Http\Controllers\Panel\StrategySerpController;
  use App\Http\Controllers\Panel\StrategyTopicsController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use App\Panel\StrategyLabels;
  use OsfSeo\Discovery\CandidateRow;
  use OsfSeo\Opportunities\OpportunityType;
  use OsfSeo\Opportunities\Text;
  use OsfSeo\Strategy\Decision\StrategyAction;
  use OsfSeo\Strategy\Serp\SerpFreshness;
  use OsfSeo\Strategy\Serp\SerpOverlap;
  use OsfSeo\Strategy\Topics\TopicStatus;

  $base = PanelUrl::project($project->publicId, 'strategy');
  $topicUrl = StrategyTopicsController::topicUrl($project->publicId, $topic->publicId);
  $analysis = $topic->analysis ?? [];
  $priority = $context['priority'] ?? null;
  $confidence = $context['confidence'] ?? null;
  $target = $context['target'] ?? [];
  $decision = $context['decision'] ?? [];
  $facts = $context['facts'] ?? null;
  $gsc = $context['gsc'] ?? null;
  $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
  $safeUrl = static fn (?string $value): bool => $value !== null && preg_match('#^https?://#i', $value) === 1;
  $leaderId = $analysis['leader'] ?? ($members[0]['id'] ?? null);
  $leader = null;

  foreach ($members as $member) {
    if ($member['id'] === $leaderId) {
      $leader = $member;
    }
  }

  $leader ??= $members[0] ?? null;
  $basisById = [];

  foreach ((array) ($analysis['members'] ?? []) as $entry) {
    if (is_array($entry) && isset($entry['id'])) {
      $basisById[(string) $entry['id']] = $entry['basis'] ?? null;
    }
  }

  $isCreate = $topic->action === StrategyAction::Create->value;
  $pending = $topic->inactiveReason === 'pending';
  $memberIds = array_map(static fn (array $member): string => (string) $member['id'], $members);
  $competitorsTop10 = $facts['competitors_top10'] ?? null;
  $topicFreshness = SerpFreshness::of($topic->serpCheckedAt, $now);
  $votesByUrl = [];
  foreach (($target['votes'] ?? []) as $vote) {
    $votesByUrl[(string) $vote['url']][StrategyLabels::vote((string) $vote['basis']) . ' (' . StrategyLabels::strength($vote['strength'] ?? null) . ')'] = true;
  }
  $derivedByUrl = [];
  foreach (($target['derived'] ?? []) as $derived) {
    $derivedByUrl[(string) $derived['url']][StrategyLabels::hint((string) $derived['source'])] = true;
  }
  $against = array_merge(
    array_map(static fn (array $alternative): array => ['text' => 'Inna strona projektu (' . implode(', ', array_map(static fn ($code): string => StrategyLabels::family((string) $code), $alternative['families'] ?? [])) . ')', 'url' => $alternative['url']], $target['alternatives'] ?? []),
    array_map(static fn (array $conflict): array => ['text' => StrategyLabels::conflict((string) $conflict['type']) . ' (' . StrategyLabels::strength($conflict['strength'] ?? null) . ')', 'url' => implode(', ', (array) ($conflict['urls'] ?? []))], $context['conflicts'] ?? []),
    array_map(static fn ($code): array => ['text' => 'Brak widoczności: ' . StrategyLabels::noVisibility((string) $code) . ' — to nie dowód braku strony', 'url' => null], $target['no_visibility'] ?? []),
  );
  $targetKnown = in_array($topic->targetState, ['confirmed', 'probable'], true);
  $targetPath = $topic->targetUrl === null ? null : ((string) (parse_url($topic->targetUrl, PHP_URL_PATH) ?: '/'));
  $nextSteps = array_values(array_filter([
    match ($topic->action) {
      'optimize' => 'Sprawdź stronę ' . ($targetPath ?? 'docelową') . ' pod kątem frazy głównej i pozostałych fraz tematu (treść, tytuł, linkowanie wewnętrzne).',
      'consolidate' => 'Porównaj konkurujące strony projektu (sekcja „Strona docelowa”) i zdecyduj, która ma być stroną główną tematu.',
      'recover' => 'Sprawdź, co zmieniło się na stronie i w wynikach wyszukiwania od poprzedniego pomiaru.',
      'create' => $canManage
        ? 'Sprawdź witrynę: jeśli odpowiednia strona istnieje — wskaż ją ręcznie; jeśli nie — potwierdź brak strony i zaplanuj treść.'
        : 'Kandydat na nową stronę — wymaga sprawdzenia witryny, zanim powstanie nowa treść.',
      'monitor' => 'Temat jest stabilny — nic pilnego; Strategia oznaczy go, gdy dowody istotnie się zmienią.',
      'investigate' => match ($topic->actionReason) {
        'serp_required', 'labs_only' => $canAnalyze
          ? 'Brakuje świeżego pomiaru SERP — zleć analizę SERP (najpierw bezpłatny podgląd kosztu) albo dodaj frazę do monitorowania w Pozycjach.'
          : 'Brakuje świeżego pomiaru SERP — rekomendacja wymaga pomiaru wyników wyszukiwania.',
        'data_incomplete' => 'Dane GSC są niepełne — sprawdź synchronizację Search Console; decyzja wróci po przeliczeniu.',
        'target_unknown', 'target_weak', 'possible_existing_page' => $canManage
          ? 'Nie wiadomo, która strona odpowiada tematowi — jeśli wiesz, wskaż ją ręcznie w sekcji „Strona docelowa”.'
          : 'Nie wiadomo, która strona witryny odpowiada tematowi.',
        default => 'Dane nie pozwalają na pewną rekomendację — przejrzyj dowody poniżej.',
      },
      default => null,
    },
    $topic->needsAttention() ? 'Dowody zmieniły się po Twojej decyzji — sprawdź, czy status pracy jest nadal właściwy.' : null,
    $canManage && in_array($topic->status, ['new', 'review'], true) && $topic->action !== null && $topic->action !== 'monitor' ? 'Po decyzji zmień status (np. „Zaplanowany”) — przeliczenie nigdy nie zmienia go samo.' : null,
  ]));
@endphp

@section('content')
  <p class="mb-4 text-sm"><a href="{{ $base }}/topics" class="text-brand-600 hover:underline">← Backlog</a></p>

  @include('panel.strategy.partials.state', ['back' => $topicUrl])

  {{-- Nagłówek: nazwa, działanie z powodem, priorytet, pewność, status pracy. --}}
  <div class="mb-8 mt-4 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
    <div class="min-w-0">
      <h1 class="break-words text-2xl font-semibold tracking-tight text-slate-900">{{ $topic->label ?? '—' }}</h1>
      <div class="mt-2 flex flex-wrap items-center gap-2">
        <x-panel.strategy-action :action="$topic->action" />
        <x-panel.topic-status :status="$topic->status" />
        <x-panel.strategy-confidence :level="$topic->confidenceLevel" :points="$topic->confidence" />
        <span class="text-xs text-slate-500">{{ Format::number($topic->keywordsCount) }} {{ Text::plural($topic->keywordsCount, 'fraza', 'frazy', 'fraz') }}</span>
        @if ($topic->needsAttention())
          <span class="inline-flex rounded bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20">zmiana po decyzji</span>
        @endif
        @if (! $topic->active)
          <span class="inline-flex rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">
            @if ($pending)
              nowy temat z przypięcia — analiza po najbliższym przeliczeniu
            @elseif ($topic->mergedInto !== null)
              scalony z <a href="{{ StrategyTopicsController::topicUrl($project->publicId, $topic->mergedInto) }}" class="ml-1 text-brand-600 hover:underline">innym tematem</a>
            @else
              temat nieaktywny (frazy przestały być kandydatami)
            @endif
          </span>
        @endif
      </div>
      @if ($topic->action !== null)
        <p class="mt-3 max-w-3xl text-sm text-slate-700">
          <span class="font-medium">{{ StrategyLabels::action($topic->action) }}:</span> {{ StrategyLabels::reason($topic->actionReason) }}.
          @if ($isCreate)
            To kandydat na nową stronę — brak strony w danych GSC, SERP i Labs nie dowodzi, że jej nie ma. Sprawdź witrynę przed zaplanowaniem treści.
          @elseif ($topic->action === StrategyAction::Investigate->value)
            Dane nie pozwalają na pewną rekomendację — sprawdź ręcznie.
          @endif
        </p>
      @endif
      @if ($topic->needsAttention() && is_array($topic->statusBasis))
        <p class="mt-2 max-w-3xl text-xs text-amber-800">
          Przy decyzji ({{ Format::datetime($topic->statusBasis['at'] ?? null) }}): {{ StrategyLabels::action($topic->statusBasis['action'] ?? null) }},
          strona {{ \OsfSeo\Strategy\Target\TargetState::tryFrom((string) ($topic->statusBasis['target_state'] ?? ''))?->label() ?? '—' }}.
          Teraz: {{ StrategyLabels::action($topic->action) }}, strona {{ \OsfSeo\Strategy\Target\TargetState::tryFrom((string) $topic->targetState)?->label() ?? '—' }}. Status pracy się nie zmienił.
        </p>
      @endif
      <dl class="mt-4 grid max-w-3xl grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-4">
        <div class="col-span-2">
          <dt class="text-xs text-slate-500">Strona docelowa</dt>
          <dd class="mt-1 flex min-w-0 flex-wrap items-center gap-2">
            <x-panel.target-state :state="$topic->targetState" :manual="$topic->manualTargetUrl !== null || $topic->manualNoPage" />
            @if ($topic->targetUrl !== null)
              @if ($safeUrl($topic->targetUrl))
                <a href="{{ $topic->targetUrl }}" target="_blank" rel="noopener noreferrer" class="min-w-0 truncate text-brand-600 hover:underline" title="{{ $topic->targetUrl }}">{{ $topic->targetUrl }}</a>
              @else
                <span class="min-w-0 truncate text-slate-700">{{ $topic->targetUrl }}</span>
              @endif
            @endif
          </dd>
        </div>
        <div>
          <dt class="text-xs text-slate-500" title="Nasz pomiar SERP frazy odniesienia (rank_group) — tylko ze świeżego pomiaru ≤ 30 dni">Pozycja SERP (pomiar)</dt>
          <dd class="mt-1 flex flex-wrap items-center gap-2">
            @if ($topicFreshness === SerpFreshness::FRESH)
              <x-panel.serp-rank :rank="$topic->serpRank" :found="$topic->serpRank !== null" />
            @else
              <span class="text-slate-400">—</span>
            @endif
            <x-panel.serp-freshness :freshness="$topicFreshness" :checked-at="$topic->serpCheckedAt" />
          </dd>
        </div>
        <div>
          <dt class="text-xs text-slate-500" title="Średnia pozycja z Google Search Console (ważona wyświetleniami) — inna metryka niż Pozycja SERP">Średnia pozycja (GSC)</dt>
          <dd class="mt-1 tabular-nums text-slate-900">{{ Format::position($topic->gscPosition) }}</dd>
        </div>
      </dl>
      <p class="mt-3 text-xs text-slate-500">
        Dane: GSC do {{ Format::date($freshness['gsc']['newest_date'] ?? null) }}
        · pomiar SERP frazy odniesienia {{ Format::datetime($topic->serpCheckedAt) }}
        · Labs (Luki SEO) {{ Format::datetime($freshness['labs']['last_import_at'] ?? null) }}
        · Strategia przeliczona {{ Format::datetime($topic->refreshedAt) }}
      </p>
    </div>
    <div class="flex shrink-0 items-center gap-3">
      @if ($topic->priority === null)
        <span class="inline-flex h-14 w-14 items-center justify-center rounded-lg text-xl text-slate-400 ring-1 ring-inset ring-slate-300">—</span>
      @else
        <x-panel.score :value="$topic->priority" />
      @endif
      <div class="text-xs text-slate-500">Priorytet Strategii<br>(kolejność pracy, nie prognoza ruchu)</div>
    </div>
  </div>

  @if ($nextSteps !== [] || ($canAnalyze && $members !== []))
    <div class="-mt-4 mb-6 rounded-lg border border-brand-200 bg-brand-50/60 px-5 py-4">
      <h2 class="text-sm font-semibold text-slate-900">Co dalej?</h2>
      <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-700">
        @foreach ($nextSteps as $step)
          <li>{{ $step }}</li>
        @endforeach
      </ul>
      @if ($canAnalyze && $members !== [])
        <div class="mt-3 flex flex-wrap gap-2">
          <x-panel.button variant="secondary" :href="StrategySerpController::analysisUrl($project->publicId, array_slice($memberIds, 0, 50))">Analiza SERP fraz tematu (najpierw bezpłatny podgląd kosztu)</x-panel.button>
        </div>
      @endif
    </div>
  @endif

  <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    {{-- Priorytet z rozbiciem. --}}
    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Priorytet — rozbicie</h2>
      @if ($priority === null)
        <p class="mt-2 text-sm text-slate-500">Priorytet powstanie przy najbliższym przeliczeniu.</p>
      @else
        <dl class="mt-3 space-y-2 text-sm">
          @foreach (StrategyLabels::PRIORITY_COMPONENTS as $key => [$label, $hint])
            @php($component = $priority['components'][$key] ?? null)
            @if ($component !== null)
              <div>
                <div class="flex justify-between gap-3"><dt class="text-slate-600">{{ $label }}</dt><dd class="tabular-nums text-slate-700">{{ Format::number((float) $component['value'], 1) }} / {{ Format::number((float) $component['max']) }}</dd></div>
                <div class="mt-1 h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-brand-500" style="width: {{ (float) $component['max'] > 0 ? round(min(1, (float) $component['value'] / (float) $component['max']) * 100) : 0 }}%"></div></div>
                <p class="mt-0.5 text-xs text-slate-400">{{ $hint }}</p>
              </div>
            @endif
          @endforeach
        </dl>
        <p class="mt-3 text-xs text-slate-600">
          Suma {{ Format::number((float) $priority['raw'], 1) }} × mnożnik pewności {{ Format::number((float) $priority['confidence_multiplier'], 2) }}
          @if ((float) $priority['action_factor'] < 1) × {{ Format::number((float) $priority['action_factor'], 1) }} (monitorowanie) @endif
          = {{ $priority['value'] }}.
          @if (($priority['components']['attainability']['difficulty_known'] ?? true) === false) Brak trudności SEO — połowa punktów osiągalności (brak KD ≠ KD 0). @endif
        </p>
      @endif
    </x-panel.card>

    {{-- Pewność z czynnikami. --}}
    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Pewność — czynniki</h2>
      @if ($confidence === null)
        <p class="mt-2 text-sm text-slate-500">Pewność powstanie przy najbliższym przeliczeniu.</p>
      @else
        <p class="mt-1 text-sm text-slate-600">{{ ucfirst(StrategyLabels::level($confidence['level'] ?? null)) }} · {{ $confidence['points'] ?? '—' }}/100 pkt</p>
        @if (($confidence['positive'] ?? []) !== [])
          <h3 class="mt-3 text-xs font-semibold uppercase tracking-wide text-emerald-700">Za</h3>
          <ul class="mt-1 space-y-1 text-sm">
            @foreach ($confidence['positive'] as $factor)
              <li class="flex justify-between gap-3"><span class="text-slate-700">{{ StrategyLabels::confidenceFactor($factor['code']) }}</span><span class="tabular-nums text-emerald-700">+{{ $factor['points'] }}</span></li>
            @endforeach
          </ul>
        @endif
        @if (($confidence['negative'] ?? []) !== [])
          <h3 class="mt-3 text-xs font-semibold uppercase tracking-wide text-rose-700">Przeciw</h3>
          <ul class="mt-1 space-y-1 text-sm">
            @foreach ($confidence['negative'] as $factor)
              <li class="flex justify-between gap-3"><span class="text-slate-700">{{ StrategyLabels::confidenceFactor($factor['code']) }}</span><span class="tabular-nums text-rose-700">{{ $factor['points'] }}</span></li>
            @endforeach
          </ul>
        @endif
        @foreach (($confidence['neutral'] ?? []) as $factor)
          <p class="mt-2 text-xs text-slate-500">{{ StrategyLabels::confidenceFactor($factor['code']) }}</p>
        @endforeach
        @foreach (($confidence['caps'] ?? []) as $cap)
          <p class="mt-2 text-xs text-amber-700">Limit: {{ StrategyLabels::confidenceCap($cap) }}.</p>
        @endforeach
      @endif
    </x-panel.card>

    {{-- Status pracy (tylko decyzja użytkownika) i notatka wewnętrzna. --}}
    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Praca nad tematem</h2>
      <p class="mt-1 text-sm text-slate-600">
        Status: <x-panel.topic-status :status="$topic->status" />
        @if ($topic->statusChangedAt !== null)
          <span class="block text-xs text-slate-500">zmieniony {{ Format::datetime($topic->statusChangedAt) }}@if ($canManage && $topic->statusChangedBy !== null) · użytkownik #{{ $topic->statusChangedBy }}@endif</span>
        @endif
        @if ($topic->completedOn !== null)
          <span class="block text-xs text-slate-500">zrealizowano {{ Format::date($topic->completedOn) }}</span>
        @endif
      </p>
      @if ($canManage)
        <form method="post" action="{{ $topicUrl }}/status" class="mt-3 flex flex-wrap items-end gap-2">
          <x-panel.nonce />
          <div>
            <label for="topic-status" class="block text-xs font-medium text-slate-600">Zmień status</label>
            <select id="topic-status" name="status" class="mt-1 rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
              @foreach (TopicStatus::cases() as $option)
                <option value="{{ $option->value }}" @selected($topic->status === $option->value)>{{ $option->label() }}</option>
              @endforeach
            </select>
          </div>
          <x-panel.button type="submit" variant="secondary">Zapisz</x-panel.button>
        </form>
        @if (isset($errors['status']))
          <p class="mt-1 text-sm text-red-700">{{ $errors['status'] }}</p>
        @endif
        <form method="post" action="{{ $topicUrl }}/note" class="mt-4">
          <x-panel.nonce />
          <label for="topic-note" class="block text-xs font-medium text-slate-600">Notatka wewnętrzna (niewidoczna dla klienta)</label>
          <textarea id="topic-note" name="note" rows="3" maxlength="5000" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">{{ $old['note'] ?? $topic->note }}</textarea>
          <x-panel.button type="submit" variant="secondary" class="mt-2">Zapisz notatkę</x-panel.button>
        </form>
        <p class="mt-3 text-xs text-slate-500">Przeliczenie nigdy nie zmienia statusu pracy — przy istotnej zmianie dowodów pojawi się oznaczenie „zmiana po decyzji”.</p>
      @else
        <p class="mt-3 text-xs text-slate-500">Tylko odczyt.</p>
      @endif
    </x-panel.card>
  </div>

  {{-- Dlaczego ten temat jest w Strategii? --}}
  <h2 class="mt-10 text-lg font-semibold text-slate-900">Dlaczego ten temat jest w Strategii?</h2>
  @if ($pending)
    <p class="mt-2 text-sm text-slate-500">Temat powstał z ręcznego przypięcia — dowody, działanie i strona docelowa pojawią się po najbliższym przeliczeniu Strategii.</p>
  @endif

  <div class="mt-4 grid grid-cols-1 gap-6 lg:grid-cols-2">
    <x-panel.card>
      <h3 class="text-base font-semibold text-slate-900">Decyzja</h3>
      @if ($decision === [] || ($decision['action'] ?? '') === '')
        <p class="mt-2 text-sm text-slate-500">Brak analizy — temat czeka na przeliczenie.</p>
      @else
        <p class="mt-2 text-sm text-slate-700">{{ $decision['action_label'] ?? StrategyLabels::action($decision['action']) }} — {{ $decision['reason_label'] ?? StrategyLabels::reason($decision['reason'] ?? null) }}.</p>
        @if (($decision['basis'] ?? []) !== [])
          <p class="mt-2 text-xs font-medium text-slate-600">Podstawy:</p>
          <ul class="mt-1 list-disc pl-5 text-sm text-slate-700">
            @foreach ($decision['basis'] as $basis)
              <li>{{ StrategyLabels::reason((string) $basis) }}</li>
            @endforeach
          </ul>
        @endif
        @if (($decision['checks'] ?? []) !== [])
          <details class="mt-3 text-xs text-slate-600">
            <summary class="cursor-pointer text-brand-600">Ślad reguł (kolejność: konsolidacja → odzyskanie → optymalizacja → nowa strona → monitorowanie → do sprawdzenia)</summary>
            <ul class="mt-2 space-y-1">
              @foreach ($decision['checks'] as $check)
                <li><span @class(['font-medium', 'text-emerald-700' => $check['passed'], 'text-slate-500' => ! $check['passed']])>{{ $check['passed'] ? '✓' : '✗' }} {{ StrategyLabels::rule((string) $check['rule']) }}</span>: {{ StrategyLabels::check((string) $check['why']) }}</li>
              @endforeach
            </ul>
          </details>
        @endif
      @endif
    </x-panel.card>

    <x-panel.card>
      <h3 class="text-base font-semibold text-slate-900">Google Search Console</h3>
      @if ($gsc === null)
        <p class="mt-2 text-sm text-slate-500">Brak danych GSC dla fraz tematu (projekt bez połączonej usługi, bez danych w oknie albo frazy spoza GSC). To nie jest dowód braku widoczności ani braku strony.</p>
      @else
        <dl class="mt-2 divide-y divide-slate-100 text-sm">
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Wyświetlenia (okno Strategii)</dt><dd class="tabular-nums text-slate-900">{{ Format::number($gsc['impressions'] ?? null) }}</dd></div>
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Kliknięcia</dt><dd class="tabular-nums text-slate-900">{{ Format::number($gsc['clicks'] ?? null) }}</dd></div>
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Średnia pozycja (GSC)</dt><dd class="tabular-nums text-slate-900">{{ Format::position(isset($gsc['position']) ? (float) $gsc['position'] : null) }}</dd></div>
        </dl>
        @if (($gsc['impressions'] ?? null) === 0)
          <p class="mt-2 text-xs text-slate-500">Projekt ma dane GSC, ale frazy tematu nie miały wyświetleń w oknie — to nie dowód braku widoczności.</p>
        @endif
        @if (($gsc['complete'] ?? true) === false)
          <p class="mt-2 text-xs text-amber-700">Dane GSC w oknie są niepełne (import w toku albo luka w synchronizacji).</p>
        @endif
        <p class="mt-2 text-xs text-slate-500">Średnia pozycja z GSC to średnia ważona wyświetleniami — nie dokładna pozycja w wynikach.</p>
      @endif
    </x-panel.card>

    <x-panel.card>
      <h3 class="text-base font-semibold text-slate-900">Dane rynkowe (fraza główna)</h3>
      @if ($leader === null)
        <p class="mt-2 text-sm text-slate-500">Brak fraz w temacie.</p>
      @else
        <p class="mt-1 text-sm text-slate-700">„{{ $leader['keyword'] }}”</p>
        <dl class="mt-2 divide-y divide-slate-100 text-sm">
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Wolumen</dt><dd class="tabular-nums text-slate-900" title="Średnia miesięczna liczba wyszukiwań">{{ Format::number($leader['volume']) }}</dd></div>
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Trudność SEO</dt><dd class="tabular-nums text-slate-900">{{ Format::number($leader['difficulty']) }}</dd></div>
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">CPC</dt><dd class="tabular-nums text-slate-900">{{ Format::usd($leader['cpc']) }}</dd></div>
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Konkurencja Ads</dt><dd class="text-slate-900">{{ Format::adsCompetition($leader['competition_level']) }}</dd></div>
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Intencja (dostawca)</dt><dd class="text-slate-900">{{ CandidateRow::intentLabel($leader['intent']) ?? '—' }}</dd></div>
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Popyt tematu (suma wolumenów)</dt><dd class="tabular-nums text-slate-900">{{ Format::number($topic->demand) }}</dd></div>
        </dl>
        <p class="mt-2 text-xs text-slate-500">Źródło: DataForSEO. „—” = brak danych (nie 0). Trudność SEO ≠ konkurencja Ads.</p>
      @endif
    </x-panel.card>

    <x-panel.card>
      <h3 class="text-base font-semibold text-slate-900">Szanse SEO</h3>
      @if (($context['opportunities'] ?? []) === [])
        <p class="mt-2 text-sm text-slate-500">Frazy tematu nie są bezpośrednio powiązane z aktywną szansą SEO (powiązanie tylko przez dane query × page — wspólna podstrona to kontekst, nie dowód).</p>
      @else
        <ul class="mt-2 divide-y divide-slate-100 text-sm">
          @foreach ($context['opportunities'] as $opportunity)
            <li class="flex flex-wrap items-center gap-2 py-2">
              <a href="{{ OpportunitiesController::detailUrl($project->publicId, $opportunity['id']) }}" class="font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ OpportunityType::tryFrom((string) $opportunity['type'])?->label() ?? $opportunity['type'] }}</a>
              <x-panel.opportunity-status :status="$opportunity['status']" />
              @if ($opportunity['page'] !== null)
                <span class="block w-full truncate text-xs text-slate-500" title="{{ $opportunity['page'] }}">{{ $opportunity['page'] }}</span>
              @endif
            </li>
          @endforeach
        </ul>
      @endif
    </x-panel.card>

    <x-panel.card>
      <h3 class="text-base font-semibold text-slate-900">Nowe frazy</h3>
      @if (($context['discovery'] ?? []) === [])
        <p class="mt-2 text-sm text-slate-500">Żadna fraza tematu nie pochodzi z wyszukiwania nowych fraz.</p>
      @else
        <ul class="mt-2 divide-y divide-slate-100 text-sm">
          @foreach ($context['discovery'] as $found)
            @php($keyword = collect($members)->firstWhere('id', $found['keyword']))
            <li class="flex flex-wrap items-center gap-2 py-2">
              <a href="{{ DiscoveryController::candidateUrl($project->publicId, $found['id']) }}" class="font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $keyword['keyword'] ?? '—' }}</a>
              <x-panel.candidate-status :status="$found['status']" />
              <x-panel.visibility :value="$found['visibility_gsc']" />
              <span class="text-xs text-slate-500">priorytet odkrycia {{ Format::number($found['priority']) }}</span>
            </li>
          @endforeach
        </ul>
        <p class="mt-2 text-xs text-slate-500">„Widoczność GSC” z Nowych fraz nie dowodzi nieobecności strony.</p>
      @endif
    </x-panel.card>

    <x-panel.card>
      <h3 class="text-base font-semibold text-slate-900">Luki fraz (Keyword Gap)</h3>
      @if (($context['gap'] ?? []) === [])
        <p class="mt-2 text-sm text-slate-500">Frazy tematu nie są lukami fraz (brak danych Labs konkurentów albo fraza poza progami).</p>
      @else
        <ul class="mt-2 divide-y divide-slate-100 text-sm">
          @foreach ($context['gap'] as $gap)
            @php($keyword = collect($members)->firstWhere('id', $gap['keyword']))
            <li class="py-2">
              <div class="flex flex-wrap items-center gap-2">
                @if ($gap['id'] !== null)
                  <a href="{{ GapKeywordsController::keywordUrl($project->publicId, $gap['id']) }}" class="font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $keyword['keyword'] ?? '—' }}</a>
                @else
                  <span class="font-medium text-slate-900">{{ $keyword['keyword'] ?? '—' }}</span>
                @endif
                @if ($gap['gap_type'] !== null)<x-panel.gap-type :value="$gap['gap_type']" />@endif
              </div>
              <p class="mt-1 text-xs text-slate-500">
                konkurenci: {{ Format::number($gap['competitors']) }}@if ($gap['competitors_top10'] !== null) (TOP10: {{ Format::number($gap['competitors_top10']) }})@endif
                @if (($gap['best_competitor']['name'] ?? null) !== null) · najlepszy: {{ $gap['best_competitor']['name'] }} #{{ $gap['best_competitor']['rank_labs'] ?? '—' }} (Labs)@endif
                @if ($gap['project_rank_labs'] !== null) · projekt #{{ $gap['project_rank_labs'] }} (Labs)@endif
              </p>
            </li>
          @endforeach
        </ul>
        <p class="mt-2 text-xs text-slate-500">Pozycje „(Labs)” to migawka bazy DataForSEO Labs — nie nasz pomiar SERP.</p>
      @endif
    </x-panel.card>

    <x-panel.card>
      <h3 class="text-base font-semibold text-slate-900">Luki treści (Content Gap)</h3>
      @if (($context['content_gap'] ?? []) === [])
        <p class="mt-2 text-sm text-slate-500">Frazy tematu nie należą do grupy luki treści.</p>
      @else
        <ul class="mt-2 divide-y divide-slate-100 text-sm">
          @foreach ($context['content_gap'] as $cluster)
            <li class="flex flex-wrap items-center gap-2 py-2">
              <a href="{{ GapContentController::clusterUrl($project->publicId, $cluster['id']) }}" class="font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $cluster['label'] ?? '—' }}</a>
              @if ($cluster['content_gap'] !== null)<x-panel.content-gap :value="$cluster['content_gap']" />@endif
              @if ($cluster['confidence'] !== null)<span class="text-xs text-slate-500">pewność {{ StrategyLabels::level($cluster['confidence']) }}</span>@endif
            </li>
          @endforeach
        </ul>
        <p class="mt-2 text-xs text-slate-500">Luka treści to heurystyka z powodem i pewnością — sygnał do sprawdzenia.</p>
      @endif
    </x-panel.card>

    <x-panel.card>
      <h3 class="text-base font-semibold text-slate-900">Konkurenci</h3>
      @if ($competitorsTop10 === null && ($context['gap'] ?? []) === [])
        <p class="mt-2 text-sm text-slate-500">Brak danych o konkurentach dla fraz tematu (brak świeżego pomiaru SERP i luk fraz).</p>
      @else
        <dl class="mt-2 divide-y divide-slate-100 text-sm">
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Skonfigurowani konkurenci w TOP10 (pomiar SERP)</dt><dd class="tabular-nums text-slate-900">{{ Format::number($competitorsTop10) }}</dd></div>
          @foreach (collect($serp['detail']['results'] ?? [])->whereNotNull('competitor')->unique('competitor') as $result)
            <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">{{ $result['competitor'] }} <span class="text-xs">(pomiar SERP frazy odniesienia)</span></dt><dd class="tabular-nums text-slate-900">#{{ $result['rank'] }}</dd></div>
          @endforeach
          @foreach (collect($context['gap'] ?? [])->pluck('best_competitor')->filter(static fn ($best): bool => is_array($best) && ($best['name'] ?? null) !== null)->unique('name') as $best)
            <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">{{ $best['name'] }} <span class="text-xs">(najlepsza pozycja w Labs)</span></dt><dd class="tabular-nums text-slate-900">#{{ $best['rank_labs'] ?? '—' }} (Labs)</dd></div>
          @endforeach
        </dl>
        <p class="mt-2 text-xs text-slate-500">Konkurentów zarządza moduł Konkurenci; pozycje z Luk SEO są oznaczone „(Labs)”.</p>
      @endif
    </x-panel.card>
  </div>

  {{-- Strona docelowa. --}}
  <x-panel.card class="mt-6">
    <h3 class="text-base font-semibold text-slate-900">Strona docelowa</h3>
    <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
      <x-panel.target-state :state="$topic->targetState" :manual="$topic->manualTargetUrl !== null || $topic->manualNoPage" />
      @if ($topic->targetUrl !== null)
        @if ($safeUrl($topic->targetUrl))
          <a href="{{ $topic->targetUrl }}" target="_blank" rel="noopener noreferrer" class="break-all text-brand-600 hover:underline">{{ $topic->targetUrl }}</a>
        @else
          <span class="break-all text-slate-700">{{ $topic->targetUrl }}</span>
        @endif
      @endif
      @if (($target['home'] ?? false) === true)
        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">strona główna</span>
      @endif
    </div>

    @if ($isCreate)
      <p class="mt-2 text-sm text-violet-800">Kandydat na nową stronę: w dostępnych danych nie ma odpowiedniej strony projektu. To nie dowód, że strona nie istnieje — sprawdź witrynę, zanim zaplanujesz nową treść.</p>
    @elseif ($topic->targetState === 'none')
      <p class="mt-2 text-sm text-violet-800">Brak znanej strony docelowej: w dostępnych danych (GSC, SERP, Labs) nie ma odpowiedniej strony projektu. To nie dowód, że strona nie istnieje.</p>
    @endif

    @php($targetReasons = array_values(array_diff(array_map('strval', $target['reasons'] ?? []), array_map('strval', $target['no_visibility'] ?? []))))
    @if ($targetReasons !== [])
      <p class="mt-2 text-xs text-slate-600">Uzasadnienie: {{ implode(', ', array_map(static fn (string $code): string => StrategyLabels::targetReason($code), $targetReasons)) }}.</p>
    @endif
    @if (($target['families'] ?? []) !== [])
      <p class="mt-1 text-xs text-slate-600">Źródła wskazujące ten adres: {{ implode(', ', array_map(static fn ($code): string => StrategyLabels::family((string) $code), $target['families'])) }}.</p>
    @endif

    <div class="mt-4 grid grid-cols-1 gap-6 md:grid-cols-3">
      <div>
        <h4 class="text-sm font-medium text-slate-700">Wskazania stron</h4>
        @if ($votesByUrl === [])
          <p class="mt-1 text-sm text-slate-500">Brak wskazań stron w danych GSC, SERP i Labs.</p>
        @else
          <ul class="mt-1 space-y-2 text-xs">
            @foreach (array_slice($votesByUrl, 0, 6, true) as $url => $labels)
              <li><span class="block truncate font-medium text-slate-800" title="{{ $url }}">{{ $url }}</span><span class="text-slate-500">{{ implode(' · ', array_keys($labels)) }}</span></li>
            @endforeach
          </ul>
        @endif
      </div>
      <div>
        <h4 class="text-sm font-medium text-slate-700">Dowody przeciw</h4>
        @if ($against === [])
          <p class="mt-1 text-sm text-slate-500">Brak sprzecznych wskazań i sygnałów konfliktu adresów.</p>
        @else
          <ul class="mt-1 space-y-2 text-xs">
            @foreach ($against as $item)
              <li class="text-rose-700">{{ $item['text'] }}@if ($item['url'] !== null && $item['url'] !== '')<span class="block truncate text-slate-600" title="{{ $item['url'] }}">{{ $item['url'] }}</span>@endif</li>
            @endforeach
          </ul>
        @endif
      </div>
      <div>
        <h4 class="text-sm font-medium text-slate-700">Sygnały pomocnicze</h4>
        @if ($derivedByUrl === [] && ($targetKnown || ($target['hints'] ?? []) === []))
          <p class="mt-1 text-sm text-slate-500">Brak.</p>
        @else
          <ul class="mt-1 space-y-2 text-xs text-slate-600">
            @foreach ($derivedByUrl as $url => $labels)
              <li><span class="block truncate text-slate-800" title="{{ $url }}">{{ $url }}</span>{{ implode(' · ', array_keys($labels)) }}</li>
            @endforeach
            @if (! $targetKnown)
              @foreach (($target['hints'] ?? []) as $hint)
                <li>Ślad możliwej strony: {{ StrategyLabels::hint((string) $hint['source']) }}@if ($hint['url'] !== null)<span class="block truncate text-slate-800" title="{{ $hint['url'] }}">{{ $hint['url'] }}</span>@endif</li>
              @endforeach
            @endif
          </ul>
          <p class="mt-2 text-xs text-slate-400">Sygnały pochodne (Szanse SEO, Nowe frazy, luki ze źródłem GSC/SERP) powstają z tych samych danych co GSC i SERP — nie liczą się jako niezależne potwierdzenie.</p>
        @endif
      </div>
    </div>

    <div class="mt-4 border-t border-slate-100 pt-4 text-sm">
      @if ($topic->manualTargetUrl !== null)
        <p class="text-slate-700">Ręczna strona docelowa: <span class="break-all">{{ $topic->manualTargetUrl }}</span></p>
      @elseif ($topic->manualNoPage)
        <p class="text-slate-700">Ręcznie potwierdzono brak strony projektu dla tego tematu.</p>
      @else
        <p class="text-slate-500">Brak ręcznego wskazania — stan wynika z danych.</p>
      @endif

      @if ($canManage && ! $pending)
        <form method="post" action="{{ $topicUrl }}/target" class="mt-3 flex flex-wrap items-end gap-2">
          <x-panel.nonce />
          <input type="hidden" name="mode" value="url">
          <div class="min-w-[18rem] flex-1">
            <label for="target-url" class="block text-xs font-medium text-slate-600">Wskaż stronę ręcznie (adres w domenie {{ $project->domain }})</label>
            <input id="target-url" name="target_url" type="url" maxlength="2000" value="{{ $old['target_url'] ?? '' }}" placeholder="https://{{ $project->domain }}/…"
              @class(['mt-1 block w-full rounded-md text-sm shadow-sm', 'border-red-400' => isset($errors['target_url']), 'border-slate-300 focus:border-brand-500 focus:ring-brand-500' => ! isset($errors['target_url'])])>
          </div>
          <x-panel.button type="submit" variant="secondary">Ustaw stronę</x-panel.button>
        </form>
        @if (isset($errors['target_url']))
          <p class="mt-1 text-sm text-red-700">{{ $errors['target_url'] }}</p>
        @endif
        <div class="mt-2 flex flex-wrap gap-2">
          @if (! $topic->manualNoPage && in_array($topic->targetState, ['none', 'unknown'], true))
            <form method="post" action="{{ $topicUrl }}/target">
              <x-panel.nonce />
              <input type="hidden" name="mode" value="none">
              <x-panel.button type="submit" variant="secondary">Potwierdź brak strony w witrynie</x-panel.button>
            </form>
          @endif
          @if ($topic->manualTargetUrl !== null || $topic->manualNoPage)
            <form method="post" action="{{ $topicUrl }}/target">
              <x-panel.nonce />
              <input type="hidden" name="mode" value="clear">
              <x-panel.button type="submit" variant="danger">Cofnij ręczne wskazanie</x-panel.button>
            </form>
          @endif
        </div>
        <p class="mt-2 text-xs text-slate-500">Ręczne wskazanie ma pierwszeństwo przed danymi; działanie i pewność zmienią się po przeliczeniu.</p>
      @endif
    </div>
  </x-panel.card>

  {{-- Frazy tematu. --}}
  <x-panel.card class="mt-6">
    <h3 class="text-base font-semibold text-slate-900">Frazy tematu</h3>
    @if ($members === [])
      <p class="mt-2 text-sm text-slate-500">Temat nie ma aktywnych fraz.</p>
    @else
      <form method="post" action="{{ $base }}/unpin" x-data="{ selected: [] }">
        <x-panel.nonce />
        <input type="hidden" name="back" value="{{ $topicUrl }}">
        <div class="mt-3 overflow-x-auto">
          <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <tr>
                @if ($canManage)<th class="w-8 py-2 pr-2"><span class="sr-only">Zaznacz</span></th>@endif
                <th class="py-2 pr-3">Fraza</th>
                <th class="py-2 pr-3">W temacie</th>
                <th class="py-2 pr-3 text-right">Wolumen</th>
                <th class="py-2 pr-3 text-right">Trudność SEO</th>
                <th class="py-2 pr-3 text-right">CPC</th>
                <th class="py-2 pr-3 text-right">Wyśw. GSC</th>
                <th class="py-2 pr-3 text-right" title="Średnia pozycja z Google Search Console">Średnia pozycja (GSC)</th>
                <th class="py-2 pr-3 text-right" title="Tylko ze świeżego pomiaru (≤ 30 dni)">Pozycja SERP</th>
                <th class="py-2 pr-3">Źródła</th>
                <th class="py-2">Strona frazy</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              @foreach ($members as $member)
                @php($memberFresh = SerpFreshness::of($member['serp_checked_at'], $now) === SerpFreshness::FRESH)
                <tr class="align-top">
                  @if ($canManage)
                    <td class="py-2 pr-2"><input type="checkbox" name="ids[]" value="{{ $member['id'] }}" x-model="selected" aria-label="Zaznacz {{ $member['keyword'] }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"></td>
                  @endif
                  <td class="max-w-xs py-2 pr-3">
                    <a href="{{ StrategySerpController::keywordUrl($project->publicId, $member['id']) }}" class="break-words font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $member['keyword'] }}</a>
                    @if (CandidateRow::intentLabel($member['intent']) !== null)
                      <span class="ml-1 inline-flex rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">{{ CandidateRow::intentLabel($member['intent']) }}</span>
                    @endif
                  </td>
                  <td class="py-2 pr-3 text-xs text-slate-600">
                    {{ $member['id'] === $leaderId ? 'fraza główna' : StrategyLabels::grouping($basisById[$member['id']] ?? null) }}
                    @if ($member['pinned'])<span class="ml-1 rounded bg-brand-50 px-1 text-brand-700">przypięta</span>@endif
                  </td>
                  <td class="py-2 pr-3 text-right tabular-nums">{{ Format::number($member['volume']) }}</td>
                  <td class="py-2 pr-3 text-right tabular-nums">{{ Format::number($member['difficulty']) }}</td>
                  <td class="py-2 pr-3 text-right tabular-nums">{{ $member['cpc'] === null ? '—' : Format::usd($member['cpc']) }}</td>
                  <td class="py-2 pr-3 text-right tabular-nums">{{ Format::number($member['gsc_impressions']) }}</td>
                  <td class="py-2 pr-3 text-right tabular-nums">{{ Format::position($member['gsc_position']) }}</td>
                  <td class="py-2 pr-3 text-right">
                    @if ($memberFresh)
                      <x-panel.serp-rank :rank="$member['serp_rank']" :found="$member['serp_rank'] !== null" />
                    @else
                      <span class="text-slate-400">—</span>
                    @endif
                  </td>
                  <td class="max-w-[9rem] py-2 pr-3 text-xs leading-snug text-slate-600">{{ implode(', ', array_map(static fn (\OsfSeo\Strategy\StrategySource $source): string => $source->label(), \OsfSeo\Strategy\StrategySource::fromBits((int) $member['sources']))) ?: '—' }}</td>
                  <td class="max-w-[14rem] py-2 text-xs">
                    <x-panel.target-state :state="$member['target_state']" />
                    @if ($member['target_url'] !== null)
                      <span class="mt-1 block truncate text-slate-500" title="{{ $member['target_url'] }}">{{ (string) (parse_url($member['target_url'], PHP_URL_PATH) ?: '/') }}</span>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        @if ($canManage)
          <div class="mt-3 flex flex-wrap items-center gap-2 text-sm" x-show="selected.length > 0" x-cloak>
            <span class="text-slate-600">Zaznaczone: <span class="font-medium" x-text="selected.length"></span></span>
            <x-panel.button type="submit" variant="secondary">Odepnij</x-panel.button>
            <input type="hidden" name="topic" value="new">
            <x-panel.button type="submit" variant="secondary" :formaction="$base . '/pin'">Przypnij do nowego tematu</x-panel.button>
          </div>
        @endif
      </form>
    @endif

    @if ($canManage && ! $pending)
      <form method="post" action="{{ $base }}/pin" class="mt-4 border-t border-slate-100 pt-4">
        <x-panel.nonce />
        <input type="hidden" name="topic" value="{{ $topic->publicId }}">
        <input type="hidden" name="back" value="{{ $topicUrl }}">
        <label for="pin-keywords" class="block text-xs font-medium text-slate-600">Przypnij frazy Strategii do tego tematu (po jednej w wierszu)</label>
        <textarea id="pin-keywords" name="keywords" rows="2" maxlength="20000" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"></textarea>
        <x-panel.button type="submit" variant="secondary" class="mt-2">Przypnij</x-panel.button>
        <p class="mt-1 text-xs text-slate-500">Przypięcie ma pierwszeństwo przed grupowaniem automatycznym; nowe frazy dodasz w <a href="{{ $base }}/settings" class="text-brand-600 hover:underline">ustawieniach</a>. Tematy przegrupuje przeliczenie.</p>
      </form>
    @endif

    @if (($analysis['suggestions'] ?? []) !== [])
      <div class="mt-4 border-t border-slate-100 pt-4 text-sm">
        <h4 class="font-medium text-slate-700">Możliwe powiązania (bez automatycznego scalenia)</h4>
        <ul class="mt-1 space-y-1 text-xs text-slate-600">
          @foreach ($analysis['suggestions'] as $suggestion)
            <li><a href="{{ StrategyTopicsController::topicUrl($project->publicId, (string) $suggestion['topic']) }}" class="text-brand-600 hover:underline">{{ $suggestion['label'] }}</a> — {{ implode(', ', array_map('strval', (array) ($suggestion['signals'] ?? []))) }}</li>
          @endforeach
        </ul>
      </div>
    @endif
  </x-panel.card>

  {{-- SERP Intelligence. --}}
  <x-panel.card class="mt-6">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
      <h3 class="text-base font-semibold text-slate-900">SERP Intelligence</h3>
      @if ($serp['keyword'] !== null)
        <a href="{{ StrategySerpController::keywordUrl($project->publicId, $serp['keyword']['id']) }}" class="text-sm text-brand-600 hover:underline">Fraza odniesienia: „{{ $serp['keyword']['keyword'] }}”</a>
      @endif
    </div>
    @if ($serp['detail'] === null)
      <p class="mt-2 text-sm text-slate-500">Brak zgodnego pomiaru SERP frazy odniesienia (w kontekście projektu: rynek, urządzenie, głębokość ≥ TOP20).
        @if ($canAnalyze) Możesz zlecić płatną analizę — najpierw zobaczysz podgląd kosztu. @endif
      </p>
    @else
      <div class="mt-3">
        @include('panel.strategy.partials.serp-results', ['detail' => $serp['detail']])
      </div>
    @endif

    @if ($serp['compared'] > 0)
      <h4 class="mt-6 text-sm font-medium text-slate-700">Overlap SERP z frazą odniesienia</h4>
      <p class="text-xs text-slate-500">Porównano {{ $serp['compared'] }} {{ Text::plural($serp['compared'], 'frazę', 'frazy', 'fraz') }} (wspólne adresy w TOP10, bez domen wszechobecnych i stron głównych; pomiary ≤ 90 dni).</p>
      <ul class="mt-2 divide-y divide-slate-100 text-sm">
        @foreach ($serp['overlap'] as $row)
          <li class="flex flex-wrap items-center gap-2 py-1.5">
            <span class="min-w-0 flex-1 truncate text-slate-800">{{ $row['keyword'] }}</span>
            @if ($row['level'] === null)
              <span class="text-xs text-slate-500">{{ implode(', ', array_map(static fn ($code): string => StrategyLabels::overlapReason((string) $code), $row['reasons'])) }}</span>
            @else
              <span @class([
                'rounded px-1.5 py-0.5 text-xs font-medium',
                'bg-emerald-50 text-emerald-700' => $row['level'] === 'strong',
                'bg-amber-50 text-amber-800' => $row['level'] === 'moderate',
                'bg-slate-100 text-slate-600' => ! in_array($row['level'], ['strong', 'moderate'], true),
              ])>overlap {{ SerpOverlap::label($row['level']) }}</span>
              <span class="text-xs tabular-nums text-slate-500">wspólne adresy: {{ $row['shared_urls'] }}, domeny: {{ $row['shared_domains'] }}</span>
            @endif
          </li>
        @endforeach
      </ul>
    @endif
  </x-panel.card>

  {{-- Historia istotnych zdarzeń. --}}
  <x-panel.card class="mt-6">
    <h3 class="text-base font-semibold text-slate-900">Historia istotnych zmian</h3>
    @if ($events === [])
      <p class="mt-2 text-sm text-slate-500">Brak zdarzeń.</p>
    @else
      <ul class="mt-2 divide-y divide-slate-100 text-sm">
        @foreach ($events as $event)
          <li class="flex flex-wrap gap-x-3 py-2">
            <span class="w-36 shrink-0 text-xs text-slate-500">{{ Format::datetime($event['at']) }}</span>
            <span class="font-medium text-slate-800">{{ $event['label'] }}</span>
            <span class="text-slate-600">
              @switch($event['type'])
                @case('created')
                @case('action_changed')
                  {{ $event['from'] !== null ? StrategyLabels::action($event['from']) . ' → ' : '' }}{{ StrategyLabels::action($event['to']) }}
                  @break
                @case('status_changed')
                  {{ StrategyLabels::status($event['from']) }} → {{ StrategyLabels::status($event['to']) }}
                  @break
                @case('serp_band_changed')
                  {{ StrategyLabels::serpBand($event['from']) }} → {{ StrategyLabels::serpBand($event['to']) }}
                  @break
                @case('priority_band_changed')
                @case('confidence_band_changed')
                  {{ StrategyLabels::level($event['from']) }} → {{ StrategyLabels::level($event['to']) }}
                  @break
                @case('target_changed')
                  {{ \OsfSeo\Strategy\Target\TargetState::tryFrom((string) $event['from'])?->label() ?? '—' }} → {{ \OsfSeo\Strategy\Target\TargetState::tryFrom((string) $event['to'])?->label() ?? '—' }}
                  @break
                @case('pinned')
                  {{ implode(', ', array_map('strval', (array) ($event['data']['keywords'] ?? []))) }}
                  @break
                @default
                  {{ $event['to'] ?? '' }}
              @endswitch
            </span>
            @if ($canManage && $event['user'] !== null)
              <span class="text-xs text-slate-400">użytkownik #{{ $event['user'] }}</span>
            @endif
          </li>
        @endforeach
      </ul>
    @endif
  </x-panel.card>

  @include('panel.strategy.partials.disclaimer')
@endsection
