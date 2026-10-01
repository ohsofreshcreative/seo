@extends('panel.layouts.app', ['active' => 'discovery'])

@section('title', $candidate->keyword . ' · Nowe frazy · ' . $project->name)

@php
  use App\Http\Controllers\Panel\DiscoveryController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Discovery\CandidateRow;
  use OsfSeo\Discovery\CandidateStatus;
  use OsfSeo\Discovery\DiscoveryMethod;
  use OsfSeo\Discovery\DiscoveryScorer;
  use OsfSeo\Discovery\Visibility;

  $metrics = $candidate->market;
  $status = $old['status'] ?? $candidate->status->value;
  $isLink = fn (?string $url): bool => $url !== null && preg_match('#^https?://#i', $url) === 1;
  $componentLabels = [
    'demand' => ['Popyt', 'wolumen w skali logarytmicznej (limit 10 000)'],
    'attainability' => ['Osiągalność', 'niższa trudność SEO = więcej punktów; brak danych = połowa'],
    'relevance' => ['Trafność', 'powiązanie z seedem + kolejne seedy, które dały frazę'],
    'gap' => ['Luka w GSC', 'brak widoczności = pełna luka, już widoczna = 0'],
    'commercial' => ['Sygnał komercyjny', 'CPC — celowo mała waga'],
  ];
@endphp

@section('content')
  <p class="mb-4 text-sm"><a href="{{ PanelUrl::project($project->publicId, 'discovery') }}" class="text-brand-600 hover:underline">← Nowe frazy</a></p>

  <x-panel.page-header :title="$candidate->keyword" :description="'Znaleziona ' . Format::datetime($candidate->discoveredAt) . ($market ? ' · ' . $market->label() : '')">
    <x-slot:actions>
      <x-panel.visibility :value="$candidate->visibility" />
      <x-panel.candidate-status :status="$candidate->status" />
    </x-slot:actions>
  </x-panel.page-header>

  @if ($candidate->excluded)
    <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Fraza pasuje do wykluczonych słów projektu — jest ukryta na liście.</div>
  @endif

  <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
    <x-panel.card><p class="text-xs font-medium text-slate-500">Priorytet</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ $candidate->priority ?? '—' }}</p></x-panel.card>
    <x-panel.card><p class="text-xs font-medium text-slate-500">Wolumen</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ Format::number($metrics->searchVolume) }}</p><p class="text-xs text-slate-500">średnio miesięcznie</p></x-panel.card>
    <x-panel.card><p class="text-xs font-medium text-slate-500">Trudność SEO</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ Format::number($metrics->keywordDifficulty) }}</p><p class="text-xs text-slate-500">0–100, organiczny TOP 10</p></x-panel.card>
    <x-panel.card><p class="text-xs font-medium text-slate-500">CPC</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ Format::usd($metrics->cpc) }}</p></x-panel.card>
    <x-panel.card><p class="text-xs font-medium text-slate-500">Konkurencja Ads</p><p class="mt-1 text-2xl font-semibold">{{ Format::adsCompetition($metrics->competitionLevel) }}</p><p class="text-xs text-slate-500">@if ($metrics->competitionIndex !== null){{ $metrics->competitionIndex }}/100 · @endif płatne wyniki</p></x-panel.card>
    <x-panel.card><p class="text-xs font-medium text-slate-500">Intencja</p><p class="mt-1 text-2xl font-semibold">{{ CandidateRow::intentLabel($candidate->intent) ?? '—' }}</p><p class="text-xs text-slate-500">według DataForSEO</p></x-panel.card>
  </div>

  <div class="mt-6 grid gap-6 lg:grid-cols-2">
    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Widoczność w Google Search Console</h2>
      <dl class="mt-2 divide-y divide-slate-100 text-sm">
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Klasa</dt><dd><x-panel.visibility :value="$candidate->visibility" /></dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Średnia pozycja (GSC)</dt><dd class="tabular-nums">{{ Format::position($candidate->gscPosition) }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Wyświetlenia / kliknięcia</dt><dd class="tabular-nums">{{ Format::number($candidate->gscImpressions) }} / {{ Format::number($candidate->gscClicks) }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Strona docelowa</dt>
          <dd class="min-w-0 text-right">
            @if ($candidate->targetUrl !== null && $isLink($candidate->targetUrl))
              <a href="{{ $candidate->targetUrl }}" target="_blank" rel="noopener noreferrer" class="break-all text-brand-600 hover:underline">{{ \OsfSeo\Opportunities\Text::path($candidate->targetUrl) }} ↗</a>
            @else
              <span class="text-slate-500">Brak przypisanej strony</span>
            @endif
          </dd>
        </div>
      </dl>
      <p class="mt-2 text-xs text-slate-500">
        Ostatnie {{ $settings['window_days'] }} dni danych GSC, wszystkie warianty frazy (wielkość liter, spacje). Średnia pozycja GSC to średnia ważona wyświetleniami — nie dokładna pozycja w Google.
        @if ($candidate->visibility === Visibility::Unknown) Brak danych GSC projektu (np. przed importem) — widoczność zostanie oceniona po imporcie. @endif
        Strona docelowa pochodzi wyłącznie z GSC — bez zgadywania.
      </p>
      <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3 text-sm">
        <span class="text-slate-500">Pozycja SERP (pomiar Google)</span>
        @if ($serp !== null)
          <a href="{{ \App\Http\Controllers\Panel\PositionsController::keywordUrl($project->publicId, $serp['public_id']) }}" class="hover:underline">
            <x-panel.serp-rank :rank="$serp['rank']" :found="$serp['found']" :depth="$serp['depth']" />
            <span class="text-xs text-slate-500">· monitorowana</span>
          </a>
        @elseif ($canTrack)
          <form method="post" action="{{ PanelUrl::project($project->publicId, 'positions/keywords') }}">
            <x-panel.nonce />
            <input type="hidden" name="source" value="discovery">
            <input type="hidden" name="single" value="{{ $candidate->publicId }}">
            <input type="hidden" name="back" value="{{ DiscoveryController::candidateUrl($project->publicId, $candidate->publicId) }}">
            <x-panel.button type="submit" variant="secondary">Monitoruj pozycję</x-panel.button>
          </form>
        @else
          <span class="text-slate-400">nie monitorowana</span>
        @endif
      </div>
    </x-panel.card>

    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Priorytet {{ $candidate->priority ?? '—' }}/100</h2>
      @if ($candidate->score !== null)
        <dl class="mt-2 divide-y divide-slate-100 text-sm">
          @foreach ($componentLabels as $name => [$label, $hint])
            <div class="flex justify-between gap-4 py-2">
              <dt class="text-slate-600">{{ $label }} <span class="block text-xs text-slate-500">{{ $hint }}</span></dt>
              <dd class="tabular-nums">{{ Format::number($candidate->score->components[$name], 1) }} / {{ DiscoveryScorer::MAX_POINTS[$name] }}</dd>
            </div>
          @endforeach
        </dl>
      @else
        <p class="mt-2 text-sm text-slate-600">Priorytet zostanie przeliczony w tle.</p>
      @endif
      <p class="mt-2 text-xs text-slate-500">Priorytet mówi, jak bardzo warto przyjrzeć się frazie — to nie prognoza ruchu ani wartość biznesowa.</p>
    </x-panel.card>
  </div>

  <div class="mt-6 grid gap-6 lg:grid-cols-2">
    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Skąd ta fraza</h2>
      <p class="mt-1 text-sm text-slate-600">Znaleziona z {{ $candidate->seedsCount }} {{ \OsfSeo\Opportunities\Text::plural($candidate->seedsCount, 'seeda', 'seedów', 'seedów') }}.</p>
      <ul class="mt-2 divide-y divide-slate-100 text-sm">
        @foreach ($candidate->sources as $source)
          <li class="flex flex-wrap justify-between gap-2 py-2">
            <span class="text-slate-900">{{ $source['seed'] }}
              @php($details = array_filter([
                DiscoveryMethod::tryFrom($source['method'])?->label() ?? $source['method'],
                $source['relation'] === 100 ? 'sam seed' : ($source['depth'] !== null && $source['depth'] > 0 ? 'poziom ' . $source['depth'] : null),
                $source['position'] !== null ? 'pozycja na liście dostawcy ' . $source['position'] : null,
              ]))
              <span class="block text-xs text-slate-500">{{ implode(' · ', $details) }}</span>
            </span>
            <span class="text-xs text-slate-500">powiązanie {{ $source['relation'] }}/100 · {{ Format::datetime($source['last_seen_at']) }}
              @if ($source['run'] !== '')<a href="{{ DiscoveryController::runUrl($project->publicId, $source['run']) }}" class="ml-1 text-brand-600 hover:underline">przebieg</a>@endif
            </span>
          </li>
        @endforeach
      </ul>
      @if (! empty($candidate->meta['core_keyword']) || ($candidate->meta['is_another_language'] ?? false))
        <p class="mt-2 text-xs text-slate-500">
          @if (! empty($candidate->meta['core_keyword']))Grupa synonimów dostawcy: „{{ $candidate->meta['core_keyword'] }}” (informacyjnie — frazy nie są scalane). @endif
          @if ($candidate->meta['is_another_language'] ?? false) Dostawca rozpoznał inny język niż język rynku. @endif
        </p>
      @endif
    </x-panel.card>

    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Historia wolumenu</h2>
      @if ($metrics->monthly !== [])
        @php($peak = max(1, ...array_map(fn ($m) => (int) ($m['search_volume'] ?? 0), $metrics->monthly)))
        <div class="mt-3 flex h-24 items-end gap-1" role="img" aria-label="Wolumen w kolejnych miesiącach">
          @foreach ($metrics->monthly as $month)
            <span @class(['flex-1 rounded-sm', 'bg-brand-500' => $month['search_volume'] !== null, 'bg-slate-200' => $month['search_volume'] === null])
              style="height: {{ $month['search_volume'] === null ? 8 : max(4, (int) round($month['search_volume'] / $peak * 100)) }}%"
              title="{{ Format::month($month['month']) }}: {{ Format::number($month['search_volume']) }}"></span>
          @endforeach
        </div>
        <p class="mt-1 text-xs text-slate-500">{{ Format::month($metrics->monthly[0]['month']) }} – {{ Format::month($metrics->monthly[count($metrics->monthly) - 1]['month']) }}, szczyt {{ Format::number($peak) }}</p>
      @else
        <p class="mt-2 text-sm text-slate-600">Brak historii od dostawcy.</p>
      @endif
      <p class="mt-2 text-xs text-slate-500">Dane rynkowe z {{ Format::datetime($metrics->updatedAt()) }} — wspólne dla rynku (te same co w module „Frazy”).</p>
    </x-panel.card>
  </div>

  <x-panel.card class="mt-6">
    <h2 class="text-base font-semibold text-slate-900">Decyzja</h2>
    @if ($canManage)
      <form method="post" action="{{ DiscoveryController::candidateUrl($project->publicId, $candidate->publicId) }}" class="mt-4 grid gap-4 md:grid-cols-3">
        <x-panel.nonce />
        <div>
          <label for="field-status" class="block text-sm font-medium text-slate-700">Status</label>
          <select id="field-status" name="status" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            @foreach (CandidateStatus::cases() as $option)
              <option value="{{ $option->value }}" @selected($status === $option->value)>{{ $option->label() }}</option>
            @endforeach
          </select>
          @if (isset($errors['status']))<p class="mt-1 text-sm text-red-700">{{ $errors['status'] }}</p>@endif
          <p class="mt-1 text-xs text-slate-500">„Zaakceptowana” = warto uwzględnić frazę w strategii SEO (nie oznacza wdrożenia).</p>
        </div>
        <div class="md:col-span-2">
          <label for="field-note" class="block text-sm font-medium text-slate-700">Notatka</label>
          <textarea id="field-note" name="note" rows="3" maxlength="{{ \OsfSeo\Discovery\DiscoveryCandidateRepository::NOTE_MAX_LENGTH }}"
            class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">{{ $old['note'] ?? $candidate->note }}</textarea>
          @if (isset($errors['note']))<p class="mt-1 text-sm text-red-700">{{ $errors['note'] }}</p>@endif
        </div>
        <div><x-panel.button type="submit">Zapisz</x-panel.button></div>
      </form>
    @else
      <dl class="mt-4 space-y-2 text-sm">
        <div><dt class="inline text-slate-500">Status:</dt> <dd class="inline"><x-panel.candidate-status :status="$candidate->status" /></dd></div>
        @if ($candidate->note)<div><dt class="text-slate-500">Notatka:</dt> <dd class="mt-1 whitespace-pre-line text-slate-700">{{ $candidate->note }}</dd></div>@endif
      </dl>
    @endif
    @if ($candidate->statusChangedAt)
      <p class="mt-3 text-xs text-slate-500">Status zmieniony {{ Format::datetime($candidate->statusChangedAt) }}@if ($candidate->statusChangedBy && ($user = get_userdata($candidate->statusChangedBy))) przez {{ $user->display_name }}@endif.</p>
    @endif
  </x-panel.card>
@endsection
