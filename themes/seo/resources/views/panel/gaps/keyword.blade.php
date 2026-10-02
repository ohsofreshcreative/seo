@extends('panel.layouts.app', ['active' => 'gaps'])

@section('title', $row['keyword'] . ' · Luki SEO · ' . $project->name)

@php
  use App\Http\Controllers\Panel\DiscoveryController;
  use App\Http\Controllers\Panel\GapContentController;
  use App\Http\Controllers\Panel\GapKeywordsController;
  use App\Http\Controllers\Panel\OpportunitiesController;
  use App\Http\Controllers\Panel\PositionsController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Discovery\CandidateRow;
  use OsfSeo\Gap\ContentGap;
  use OsfSeo\Gap\GapScorer;
  use OsfSeo\Gap\GapStatus;
  use OsfSeo\Opportunities\OpportunityType;

  $base = PanelUrl::project($project->publicId, 'gaps');
  $int = static fn (?string $value): ?int => $value === null ? null : (int) $value;
  $safeUrl = static fn (?string $url): bool => $url !== null && preg_match('#^https?://#i', $url) === 1;
  $components = [
    'demand' => ['Popyt', 'wolumen wyszukiwań (skala logarytmiczna)'],
    'attainability' => ['Osiągalność', 'trudność SEO — niższa = łatwiej'],
    'evidence' => ['Siła sygnału', 'pozycja najlepszego konkurenta i liczba konkurentów'],
    'gap' => ['Luka', 'widoczność projektu (brak / słaba / nieznana)'],
    'intent' => ['Intencja', 'komercyjna i transakcyjna wyżej'],
    'commercial' => ['Sygnał komercyjny', 'CPC — mała waga'],
  ];
  $eventLabels = ['new' => 'nowa fraza', 'lost' => 'zniknęła z zakresu', 'back' => 'wróciła', 'url' => 'inny adres', 'up' => 'wzrost', 'down' => 'spadek'];
  $domainNames = [];

  foreach ($competitors as $entry) {
    if ($entry['dataset'] !== null) {
      $domainNames[$entry['dataset']->id] = $entry['competitor']->name;
    }
  }

  if ($baseline !== null) {
    $domainNames[$baseline['dataset']->id] = $project->name . ' (projekt)';
  }

  $serp = $evidence['serp'];
  $gscImpressions = $row['gsc_impressions'] === null ? null : (int) $row['gsc_impressions'];
  $confidence = ['low' => 1, 'medium' => 2, 'high' => 3][$row['confidence'] ?? ''] ?? null;
  $status = GapStatus::tryFrom((string) $row['status']);
@endphp

@section('content')
  <p class="mb-4 text-sm"><a href="{{ $base }}/keywords" class="text-brand-600 hover:underline">← Luki fraz</a></p>

  <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
    <div>
      <h1 class="break-words text-2xl font-semibold tracking-tight text-slate-900">{{ $row['keyword'] }}</h1>
      <div class="mt-2 flex flex-wrap items-center gap-2">
        <x-panel.gap-type :value="$row['gap_type']" />
        <x-panel.candidate-status :status="$row['status']" />
        @if (CandidateRow::intentLabel($row['intent']) !== null)
          <span class="inline-flex rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600" title="Intencja wyszukiwania według DataForSEO">{{ CandidateRow::intentLabel($row['intent']) }}</span>
        @endif
        @if ($row['other_language'] === '1')
          <span class="inline-flex rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600" title="DataForSEO rozpoznało inny język niż język rynku — tylko informacja, fraza nie jest przez to odfiltrowana">inny język</span>
        @endif
        @if ($row['active'] !== '1')
          <span class="inline-flex rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">nieaktualna — żaden aktywny konkurent już nie rankuje w progu</span>
        @elseif ($row['listed'] !== '1')
          <span class="inline-flex rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-800">odfiltrowana ({{ $row['filter_reason'] }})</span>
        @endif
      </div>
    </div>
    <div class="flex items-center gap-3">
      <x-panel.score :value="$row['priority']" />
      <div class="text-xs text-slate-500">Priorytet luki<br>(sygnał do sprawdzenia)</div>
    </div>
  </div>

  <div class="grid gap-6 lg:grid-cols-3">
    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Dane rynkowe</h2>
      <dl class="mt-2 divide-y divide-slate-100 text-sm">
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Wolumen</dt><dd class="tabular-nums text-slate-900" title="Średnia miesięczna liczba wyszukiwań">{{ Format::number($int($row['search_volume'])) }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Trudność SEO</dt><dd class="tabular-nums text-slate-900">{{ Format::number($int($row['keyword_difficulty'])) }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">CPC</dt><dd class="tabular-nums text-slate-900">{{ $row['cpc'] === null ? '—' : Format::usd((float) $row['cpc']) }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Konkurencja Ads</dt><dd class="text-slate-900">{{ Format::adsCompetition($row['competition_level']) }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Konkurenci w TOP{{ $settings->competitorMaxRank }}</dt><dd class="tabular-nums text-slate-900">{{ $row['competitors_count'] }} <span class="text-slate-500">(w TOP10: {{ $row['competitors_top10'] }})</span></dd></div>
      </dl>
      <p class="mt-2 text-xs text-slate-500">Źródło: DataForSEO. „—” = brak danych (nie 0).</p>
    </x-panel.card>

    <x-panel.card class="lg:col-span-2">
      <h2 class="text-base font-semibold text-slate-900">Dlaczego taki priorytet</h2>
      @if ($score === null)
        <p class="mt-2 text-sm text-slate-500">Priorytet zostanie przeliczony w tle.</p>
      @else
        <dl class="mt-3 space-y-2 text-sm">
          @foreach ($components as $key => [$label, $hint])
            @php($points = (float) ($score['components'][$key] ?? 0))
            @php($max = GapScorer::MAX_POINTS[$key])
            <div class="grid grid-cols-12 items-center gap-3">
              <dt class="col-span-4 text-slate-600">{{ $label }} <span class="block text-xs text-slate-400">{{ $hint }}</span></dt>
              <dd class="col-span-6"><div class="h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-brand-500" style="width: {{ $max > 0 ? round(min(1, $points / $max) * 100) : 0 }}%"></div></div></dd>
              <dd class="col-span-2 text-right tabular-nums text-slate-700">{{ Format::number($points, 1) }} / {{ $max }}</dd>
            </div>
          @endforeach
        </dl>
        @if ((float) $score['multiplier'] < 1)
          <p class="mt-3 text-xs text-slate-600">Mnożnik × {{ Format::number((float) $score['multiplier'], 1) }} — projekt już rankuje porównywalnie albo wyżej, więc to mniejsza luka.</p>
        @endif
      @endif
    </x-panel.card>
  </div>

  <x-panel.card class="mt-6">
    <h2 class="text-base font-semibold text-slate-900">Widoczność projektu</h2>
    <div class="mt-2 flex flex-wrap items-center gap-3 text-sm">
      <x-panel.project-visibility :value="$row['visibility']" :source="$row['visibility_source']" :position="$row['project_position']" :sporadic="$row['sporadic'] === '1'" />
      <span class="text-xs text-slate-500">Hierarchia dowodów: świeży pomiar SERP → średnia pozycja (GSC) → punkt odniesienia DataForSEO Labs. Sam brak frazy w GSC nie oznacza braku widoczności.</span>
    </div>
    <div class="mt-4 grid gap-6 md:grid-cols-3">
      <div>
        <h3 class="text-sm font-medium text-slate-700">Pomiar SERP (moduł Pozycje)</h3>
        @if ($serp === null)
          <p class="mt-1 text-sm text-slate-500">Fraza nie jest monitorowana.</p>
          @if ($canTrack)
            <form method="post" action="{{ PanelUrl::project($project->publicId, 'positions/keywords') }}" class="mt-2">
              <x-panel.nonce />
              <input type="hidden" name="source" value="gap">
              <input type="hidden" name="single" value="{{ $row['public_id'] }}">
              <input type="hidden" name="back" value="{{ GapKeywordsController::keywordUrl($project->publicId, $row['public_id']) }}">
              <x-panel.button type="submit" variant="secondary">Monitoruj pozycję</x-panel.button>
              <p class="mt-1 text-xs text-slate-500">Bez kosztów — pozycję sprawdzi harmonogram albo „Sprawdź pozycje teraz”.</p>
            </form>
          @endif
        @elseif ($serp['last_checked_at'] === null)
          <p class="mt-1 text-sm text-slate-500">Monitorowana — czeka na pierwszy pomiar.</p>
        @else
          <p class="mt-1 text-sm text-slate-900">
            {{ $serp['last_found'] === '1' ? 'Pozycja SERP #' . $serp['last_rank'] : 'Poza TOP' . $serp['last_depth'] }}
            <span class="block text-xs text-slate-500">pomiar {{ Format::datetime($serp['last_checked_at']) }}</span>
            @if ($safeUrl($serp['last_url']))
              <a href="{{ $serp['last_url'] }}" rel="noopener noreferrer" target="_blank" class="block break-all text-xs text-brand-600 hover:underline">{{ $serp['last_url'] }}</a>
            @endif
          </p>
          <a href="{{ PositionsController::keywordUrl($project->publicId, $serp['public_id']) }}" class="mt-1 inline-block text-xs text-brand-600 hover:underline">Historia pozycji</a>
        @endif
      </div>
      <div>
        <h3 class="text-sm font-medium text-slate-700">Google Search Console @if ($window !== null)<span class="font-normal text-slate-500">({{ Format::date($window[0]) }} – {{ Format::date($window[1]) }})</span>@endif</h3>
        @if ($window === null)
          <p class="mt-1 text-sm text-slate-500">Brak danych GSC projektu.</p>
        @elseif (($gscImpressions ?? 0) === 0)
          <p class="mt-1 text-sm text-slate-500">Brak wyświetleń tej frazy w GSC (to samo w sobie nie oznacza braku widoczności).</p>
        @else
          <p class="mt-1 text-sm text-slate-900">
            {{ Format::number($gscImpressions) }} wyświetleń, {{ Format::number($int($row['gsc_clicks'])) }} kliknięć
            <span class="block text-xs text-slate-500">Średnia pozycja (GSC): {{ Format::position($row['gsc_position'] === null ? null : (float) $row['gsc_position']) }}</span>
          </p>
        @endif
        @if ($evidence['variants'] !== [] && count($evidence['variants']) > 1)
          <p class="mt-2 text-xs text-slate-500">Warianty zapisu w GSC:
            @foreach ($evidence['variants'] as $variant)
              {{ $variant['keyword'] }} ({{ Format::number((int) $variant['impressions']) }})@if (! $loop->last), @endif
            @endforeach
          </p>
        @endif
        @if ($evidence['pages'] !== [])
          <ul class="mt-2 space-y-0.5 text-xs">
            @foreach ($evidence['pages'] as $gscPage)
              <li class="break-all text-slate-600">@if ($safeUrl($gscPage['url']))<a href="{{ $gscPage['url'] }}" rel="noopener noreferrer" target="_blank" class="text-brand-600 hover:underline">{{ $gscPage['url'] }}</a>@else{{ $gscPage['url'] }}@endif · {{ Format::number((int) $gscPage['impressions']) }} wyśw.</li>
            @endforeach
          </ul>
        @endif
      </div>
      <div>
        <h3 class="text-sm font-medium text-slate-700">Punkt odniesienia (DataForSEO Labs)</h3>
        @if ($baseline === null)
          <p class="mt-1 text-sm text-slate-500">Nie pobrano fraz domeny projektu.</p>
        @elseif ($baseline['row'] === null || $baseline['row']['present'] !== '1')
          @php($doubt = $baseline['dataset']->absenceDoubt($int($row['search_volume'])))
          @if ($doubt === null)
            <p class="mt-1 text-sm text-slate-900">Domena projektu nie rankuje na tę frazę w bazie Labs (TOP{{ $baseline['dataset']->coverage?->maxRank ?? 100 }}).</p>
          @else
            <p class="mt-1 text-sm text-slate-900">Brak frazy w zbiorze domeny projektu — to nie dowodzi braku widoczności.
              <span class="block text-xs text-amber-700">Niepewne: {{ $doubt }}.</span>
            </p>
          @endif
        @else
          <p class="mt-1 text-sm text-slate-900">
            Pozycja #{{ $baseline['row']['rank_group'] }} <span class="text-xs text-slate-500">(Labs, {{ Format::date($baseline['row']['serp_on']) }})</span>
            @if ($safeUrl($baseline['row']['url']))
              <a href="{{ $baseline['row']['url'] }}" rel="noopener noreferrer" target="_blank" class="block break-all text-xs text-brand-600 hover:underline">{{ $baseline['row']['url'] }}</a>
            @endif
          </p>
        @endif
      </div>
    </div>
    @if ($row['target_url'] !== null)
      <p class="mt-4 text-sm text-slate-700">Strona docelowa projektu: @if ($safeUrl($row['target_url']))<a href="{{ $row['target_url'] }}" rel="noopener noreferrer" target="_blank" class="break-all text-brand-600 hover:underline">{{ $row['target_url'] }}</a>@else{{ $row['target_url'] }}@endif
        <span class="text-xs text-slate-500">({{ ['serp' => 'pomiar SERP', 'gsc' => 'GSC', 'labs' => 'Labs', 'slug' => 'adres'][$row['target_source']] ?? $row['target_source'] }})</span></p>
    @else
      <p class="mt-4 text-sm text-slate-500">Brak przypisanej strony projektu.</p>
    @endif
  </x-panel.card>

  <x-panel.card class="mt-6">
    <h2 class="text-base font-semibold text-slate-900">Konkurenci</h2>
    <p class="mt-1 text-xs text-slate-500">Pozycje z bazy DataForSEO Labs (migawka z podanej daty, nie nasz pomiar). Kolumna „Nasz pomiar SERP” — tylko dla monitorowanych fraz.</p>
    <div class="mt-3 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
            <th class="py-2 pr-4 font-medium">Konkurent</th>
            <th class="py-2 pr-4 text-right font-medium">Pozycja (Labs)</th>
            <th class="py-2 pr-4 font-medium">Adres</th>
            <th class="py-2 pr-4 font-medium">Dane Labs z</th>
            <th class="py-2 pr-4 font-medium">Pierwszy raz</th>
            <th class="py-2 text-right font-medium">Nasz pomiar SERP</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          @foreach ($competitors as $index => $entry)
            @php($hit = $entry['row'])
            <tr>
              <td class="py-2 pr-4"><span class="font-medium text-slate-900">{{ $entry['competitor']->name }}</span><span class="block text-xs text-slate-500">{{ $entry['competitor']->domain }}</span></td>
              @if ($entry['dataset'] === null || $entry['dataset']->importedAt === null)
                <td colspan="4" class="py-2 pr-4 text-slate-500">nie pobrano fraz domeny</td>
              @elseif ($hit !== null && $hit['present'] === '2')
                <td colspan="4" class="py-2 pr-4 text-slate-500">brak w ostatnim imporcie (wcześniej #{{ $hit['rank_group'] }}) — niepotwierdzone: wolumen blisko granicy zakresu</td>
              @elseif ($hit === null || $hit['present'] !== '1')
                <td colspan="4" class="py-2 pr-4 text-slate-500">nie rankuje w zakresie TOP{{ $entry['dataset']->coverage?->maxRank }}{{ $hit !== null ? ' (wcześniej #' . $hit['rank_group'] . ')' : '' }}</td>
              @else
                <td class="py-2 pr-4 text-right tabular-nums text-slate-900">#{{ $hit['rank_group'] }}</td>
                <td class="max-w-sm py-2 pr-4">
                  @if ($hit['title'] !== null)<span class="block truncate text-slate-700">{{ $hit['title'] }}</span>@endif
                  @if ($safeUrl($hit['url']))<a href="{{ $hit['url'] }}" rel="noopener noreferrer" target="_blank" class="block break-all text-xs text-brand-600 hover:underline">{{ $hit['url'] }}</a>@endif
                </td>
                <td class="py-2 pr-4 text-xs text-slate-600">{{ Format::date($hit['serp_on']) }}</td>
                <td class="py-2 pr-4 text-xs text-slate-600">{{ Format::date($hit['first_seen']) }}</td>
              @endif
              <td class="py-2 text-right tabular-nums text-slate-700">{{ isset($serp_competitors[$index]) ? '#' . $serp_competitors[$index]['rank_group'] : '—' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </x-panel.card>

  <div class="mt-6 grid gap-6 lg:grid-cols-2">
    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Grupa i luka treści</h2>
      @if ($row['cluster_public_id'] === null)
        <p class="mt-2 text-sm text-slate-500">Fraza nie należy do grupy (odfiltrowana albo poza limitem grupowania).</p>
      @else
        <p class="mt-2 text-sm"><a href="{{ GapContentController::clusterUrl($project->publicId, $row['cluster_public_id']) }}" class="font-medium text-brand-600 hover:underline">{{ $row['cluster_label'] }}</a></p>
        <div class="mt-2 flex flex-wrap items-center gap-2">
          <x-panel.content-gap :value="$row['content_gap']" />
          @if ($confidence !== null)
            <x-panel.confidence :level="$confidence" />
          @endif
        </div>
        <p class="mt-2 text-xs text-slate-600">{{ ContentGap::reasonLabel($row['content_reason']) }}. To heurystyka do sprawdzenia — nie diagnoza.</p>
      @endif
    </x-panel.card>

    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Powiązane w Whack-a-mole</h2>
      <ul class="mt-2 space-y-1 text-sm">
        @if ($evidence['candidate'] !== null)
          <li><a href="{{ DiscoveryController::candidateUrl($project->publicId, $evidence['candidate']['public_id']) }}" class="text-brand-600 hover:underline">Nowe frazy — kandydat</a> <span class="text-xs text-slate-500">(priorytet odkrycia {{ $evidence['candidate']['priority'] ?? '—' }})</span></li>
        @endif
        @foreach ($evidence['opportunities'] as $opportunity)
          <li><a href="{{ OpportunitiesController::detailUrl($project->publicId, $opportunity['public_id']) }}" class="text-brand-600 hover:underline">Szansa SEO: {{ OpportunityType::tryFrom((string) $opportunity['type'])?->label() ?? $opportunity['type'] }}</a></li>
        @endforeach
        @if ($evidence['candidate'] === null && $evidence['opportunities'] === [])
          <li class="text-slate-500">Brak powiązanych kandydatów Nowych fraz i Szans SEO.</li>
        @endif
      </ul>
    </x-panel.card>
  </div>

  @if ($events !== [])
    <x-panel.card class="mt-6">
      <h2 class="text-base font-semibold text-slate-900">Historia w zbiorach domen</h2>
      <p class="mt-1 text-xs text-slate-500">Zmiany między kolejnymi importami (Labs) — nowe, utracone i powrotne frazy, zmiana adresu, zmiany pozycji o co najmniej 5 miejsc albo przejście progu TOP3/10/20/50/100.</p>
      <ul class="mt-3 divide-y divide-slate-100 text-sm">
        @foreach ($events as $event)
          <li class="flex flex-wrap items-center justify-between gap-3 py-2">
            <span class="text-slate-700">{{ $domainNames[(int) $event['domain_id']] ?? 'domena' }} — {{ $eventLabels[$event['event']] ?? $event['event'] }}
              @if ($event['rank_old'] !== null || $event['rank_new'] !== null)
                <span class="tabular-nums text-slate-500">({{ $event['rank_old'] === null ? '—' : '#' . $event['rank_old'] }} → {{ $event['rank_new'] === null ? '—' : '#' . $event['rank_new'] }})</span>
              @endif
            </span>
            <span class="text-xs text-slate-500">{{ Format::date($event['observed_on']) }}</span>
          </li>
        @endforeach
      </ul>
    </x-panel.card>
  @endif

  @if ($canManage)
    <x-panel.card class="mt-6">
      <h2 class="text-base font-semibold text-slate-900">Praca nad luką</h2>
      <form method="post" action="{{ GapKeywordsController::keywordUrl($project->publicId, $row['public_id']) }}" class="mt-3 grid gap-4 md:grid-cols-3">
        <x-panel.nonce />
        <div>
          <label for="status" class="block text-sm font-medium text-slate-700">Status</label>
          <select id="status" name="status" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            @foreach (GapStatus::cases() as $option)
              <option value="{{ $option->value }}" @selected($status === $option)>{{ $option->label() }}</option>
            @endforeach
          </select>
          @if (isset($errors['status']))
            <p class="mt-1 text-sm text-red-700">{{ $errors['status'] }}</p>
          @endif
        </div>
        <div class="md:col-span-2">
          <label for="note" class="block text-sm font-medium text-slate-700">Notatka</label>
          <textarea id="note" name="note" rows="2" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">{{ $row['note'] }}</textarea>
        </div>
        <div class="md:col-span-3">
          <x-panel.button type="submit">Zapisz</x-panel.button>
          @if ($row['status_changed_at'] !== null)
            <span class="ml-2 text-xs text-slate-500">ostatnia zmiana {{ Format::datetime($row['status_changed_at']) }}</span>
          @endif
        </div>
      </form>
    </x-panel.card>
  @elseif ($row['note'] !== null)
    <x-panel.card class="mt-6">
      <h2 class="text-base font-semibold text-slate-900">Notatka</h2>
      <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $row['note'] }}</p>
    </x-panel.card>
  @endif

  @include('panel.gaps.partials.disclaimer', ['freshDays' => 30])
@endsection
