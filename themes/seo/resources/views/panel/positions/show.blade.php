@extends('panel.layouts.app', ['active' => 'positions'])

@section('title', $row->keyword . ' · Pozycje · ' . $project->name)

@php
  use App\Http\Controllers\Panel\PositionsController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Serp\ItemTypes;
  use OsfSeo\Serp\SerpItem;

  $base = PanelUrl::project($project->publicId, 'positions');
  $depth = $context?->depth ?? $row->depth;
  $selectedId = $snapshot['public_id'] ?? null;
  $latestId = $history[0]['public_id'] ?? null;
  $features = $snapshot === null ? [] : ItemTypes::labels((int) $snapshot['item_types']);
  $inSnapshot = [];
  foreach ($results as $result) {
    if ($result['competitor'] !== null && (int) $result['result_type'] === SerpItem::TYPE_ORGANIC) {
      $inSnapshot[$result['competitor']][] = $result;
    }
  }
  $flags = [
    SerpItem::FLAG_AMP => 'AMP',
    SerpItem::FLAG_IMAGE => 'obraz',
    SerpItem::FLAG_VIDEO => 'wideo',
    SerpItem::FLAG_RATING => 'ocena',
    SerpItem::FLAG_PRICE => 'cena',
    SerpItem::FLAG_SITELINKS => 'linki witryny',
  ];
  $host = fn (?string $url) => $url === null ? '' : preg_replace('#^https?://(www\.)?#i', '', $url);
@endphp

@section('content')
  <p class="mb-2 text-sm"><a href="{{ $base }}" class="text-brand-600 hover:underline">← Pozycje</a></p>
  <x-panel.page-header :title="$row->keyword"
    :description="'Pozycja SERP w Google' . ($context !== null ? ' · ' . $context->device->label() . ', TOP' . $context->depth . ', lokalizacja ' . $context->locationCode . ' / ' . $context->languageCode : '') . ' · domena projektu: ' . $project_domain . ' (z subdomenami)'">
    @if ($canManage)
      <x-slot:actions>
        <x-panel.button :href="PositionsController::checkUrl($project->publicId, [$row->publicId])">Sprawdź tę frazę teraz</x-panel.button>
      </x-slot:actions>
    @endif
  </x-panel.page-header>

  <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
    <x-panel.stat label="Pozycja SERP" :value="$row->rankLabel()" hint="ostatni pomiar, wynik organiczny">
      @if ($row->rankAbsolute !== null && $row->rankAbsolute !== $row->rank)
        <span class="text-xs text-slate-500" title="Miejsce wśród wszystkich elementów strony wyników (z modułami)">element #{{ $row->rankAbsolute }} na stronie</span>
      @endif
      @if ($row->featured)
        <span class="text-xs text-sky-700">+ wyróżniony fragment</span>
      @endif
    </x-panel.stat>
    <x-panel.stat label="Zmiana" value="">
      <x-panel.rank-change :type="$row->changeType" :value="$row->changeValue" :top10="$row->top10Change" :depth="$row->depth" class="text-lg font-semibold" />
      @if ($row->previousRank !== null)
        <span class="text-xs text-slate-500">poprzednio #{{ $row->previousRank }}</span>
      @endif
    </x-panel.stat>
    <x-panel.stat label="Średnia pozycja (GSC)" :value="Format::position($row->gscPosition)" :hint="'Search Console, ' . \OsfSeo\Serp\SerpConfig::GSC_DAYS . ' dni — inna metryka'" />
    <x-panel.stat label="Wolumen / KD" :value="Format::number($row->searchVolume) . ' / ' . Format::number($row->difficulty)" hint="DataForSEO; „—” = brak danych" />
    <x-panel.stat label="Ostatni pomiar" :value="Format::datetime($row->lastCheckedAt)" :hint="$row->url !== null ? $host($row->url) : null" />
  </div>

  @if (count($history) > 0)
    <x-panel.card class="mt-6">
      <div x-data="rankChart">
        <h2 class="text-base font-semibold text-slate-900">Historia Pozycji SERP</h2>
        <p class="mt-1 text-xs text-slate-500">Niżej na osi = gorzej; przerwa w linii = poza sprawdzonym TOP{{ $depth }}. Konkurenci odczytani z tych samych zapisanych wyników.</p>
        <div class="relative mt-4 h-72">
          <canvas x-ref="canvas" data-chart="{{ json_encode($chart) }}" role="img" aria-label="Wykres Pozycji SERP projektu i konkurentów w kolejnych pomiarach"></canvas>
        </div>
      </div>

      <div class="mt-6 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
              <th class="py-2 pr-4 font-medium">Pomiar</th>
              <th class="py-2 pr-4 text-right font-medium">Pozycja SERP</th>
              <th class="py-2 pr-4 font-medium">URL projektu</th>
              @foreach ($competitors as $competitor)
                <th class="py-2 pr-4 text-right font-medium" title="{{ $competitor->domain }}">{{ $competitor->name }}</th>
              @endforeach
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 tabular-nums">
            @foreach ($history as $item)
              <tr @class(['bg-brand-50' => $item['public_id'] === $selectedId])>
                <td class="py-2 pr-4">
                  <a href="{{ PositionsController::keywordUrl($project->publicId, $row->publicId, $item['public_id'] === $latestId ? null : $item['public_id']) }}" class="text-brand-600 hover:underline">{{ Format::datetime($item['checked_at']) }}</a>
                </td>
                <td class="py-2 pr-4 text-right">
                  <x-panel.serp-rank :rank="$item['project_rank'] === null ? null : (int) $item['project_rank']" :found="true" :depth="(int) $item['requested_depth']" :featured="(int) $item['project_featured'] === 1" />
                </td>
                <td class="max-w-xs py-2 pr-4 text-xs">
                  @if ($item['url'] !== null && preg_match('#^https?://#i', $item['url']))
                    <span class="break-all text-slate-600">{{ $host($item['url']) }}</span>
                  @else
                    <span class="text-slate-400">—</span>
                  @endif
                </td>
                @foreach ($competitors as $competitor)
                  @php($best = $history_competitors[(int) $item['id']][$competitor->publicId][0]['rank'] ?? null)
                  <td class="py-2 pr-4 text-right {{ $best === null ? 'text-slate-400' : 'text-slate-800' }}">{{ $best === null ? '—' : '#' . $best }}</td>
                @endforeach
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </x-panel.card>
  @endif

  @if ($snapshot === null)
    <x-panel.empty-state class="mt-6" title="Fraza nie była jeszcze sprawdzana"
      description="Wyniki pojawią się po pierwszym pomiarze (harmonogram albo „Sprawdź pozycje teraz” — zawsze z podglądem kosztu)." />
  @else
    <x-panel.card class="mt-6">
      <div class="flex flex-wrap items-baseline justify-between gap-3">
        <h2 class="text-base font-semibold text-slate-900">Wyniki Google — TOP{{ (int) $snapshot['requested_depth'] }}</h2>
        <p class="text-xs text-slate-500">
          Pomiar {{ Format::datetime($snapshot['checked_at']) }}@if ($snapshot['public_id'] !== $latestId) (archiwalny — <a href="{{ PositionsController::keywordUrl($project->publicId, $row->publicId) }}" class="text-brand-600 hover:underline">pokaż ostatni</a>)@endif ·
          {{ $snapshot['se_domain'] ?? 'google' }} · wyników organicznych: {{ Format::number((int) $snapshot['organic_count']) }}@if ($snapshot['se_results_count'] !== null) · Google szacuje {{ Format::number((int) $snapshot['se_results_count']) }} wyników @endif
        </p>
      </div>

      @if ($snapshot['spell_keyword'] !== null)
        <p class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">Google poprawił zapytanie ({{ $snapshot['spell_type'] ?? 'korekta' }}): „{{ $snapshot['spell_keyword'] }}” — wyniki mogą dotyczyć poprawionej frazy.</p>
      @endif

      @if ($features !== [])
        <p class="mt-2 text-xs text-slate-600">Na stronie wyników były też: {{ implode(', ', $features) }} (te elementy nie są liczone w Pozycji SERP).</p>
      @endif

      @if ($inSnapshot !== [] || $competitors !== [])
        <div class="mt-4 flex flex-wrap gap-2 text-xs">
          <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-1 font-medium text-emerald-800 ring-1 ring-inset ring-emerald-600/20">Projekt: {{ $snapshot['project_rank'] !== null ? '#' . $snapshot['project_rank'] : 'poza TOP' . (int) $snapshot['requested_depth'] }}</span>
          @foreach ($competitors as $competitor)
            @php($found = $inSnapshot[$competitor->name] ?? [])
            <span class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-2 py-1 font-medium text-amber-900 ring-1 ring-inset ring-amber-600/20">{{ $competitor->name }}: {{ $found === [] ? 'poza TOP' . (int) $snapshot['requested_depth'] : '#' . implode(', #', array_map(fn ($item) => (int) $item['rank_group'], $found)) }}</span>
          @endforeach
        </div>
      @endif

      @if ($results === [])
        <p class="mt-4 text-sm text-slate-600">Dostawca nie zwrócił wyników organicznych dla tej frazy.</p>
      @else
        <div class="mt-4 overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                <th class="py-2 pr-3 text-right font-medium" title="Pozycja wśród wyników organicznych (rank_group)">#</th>
                <th class="py-2 pr-3 text-right font-medium" title="Miejsce wśród wszystkich elementów strony (rank_absolute)">Element</th>
                <th class="py-2 pr-3 font-medium">Wynik</th>
                <th class="py-2 font-medium">Oznaczenie</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              @foreach ($results as $result)
                @php($featured = (int) $result['result_type'] === SerpItem::TYPE_FEATURED_SNIPPET)
                <tr @class([
                  'bg-emerald-50' => $result['is_project'],
                  'bg-amber-50' => ! $result['is_project'] && $result['competitor'] !== null,
                ])>
                  <td class="py-2 pr-3 text-right align-top font-semibold tabular-nums text-slate-900">{{ $featured ? '—' : $result['rank_group'] }}</td>
                  <td class="py-2 pr-3 text-right align-top tabular-nums text-slate-500">{{ $result['rank_absolute'] }}</td>
                  <td class="max-w-2xl py-2 pr-3 align-top">
                    @if ($featured)
                      <span class="mb-0.5 inline-flex rounded bg-sky-50 px-1.5 py-0.5 text-xs font-medium text-sky-700">wyróżniony fragment</span>
                    @endif
                    <span class="block font-medium text-slate-900">{{ $result['title'] ?? $result['host'] }}</span>
                    @if (preg_match('#^https?://#i', (string) $result['url']))
                      <a href="{{ $result['url'] }}" target="_blank" rel="noopener noreferrer nofollow" class="block break-all text-xs text-brand-600 hover:underline">{{ $host($result['url']) }}</a>
                    @endif
                    @if ($result['description'] !== null && $result['description'] !== '')
                      <span class="mt-0.5 block text-xs text-slate-600">{{ mb_strimwidth((string) $result['description'], 0, 240, '…') }}</span>
                    @endif
                    @php($badges = array_values(array_filter($flags, fn ($label, $bit) => ((int) $result['flags'] & $bit) !== 0, ARRAY_FILTER_USE_BOTH)))
                    @if ($badges !== [])
                      <span class="mt-1 block text-xs text-slate-500">{{ implode(' · ', $badges) }}</span>
                    @endif
                  </td>
                  <td class="whitespace-nowrap py-2 align-top text-xs">
                    @if ($result['is_project'])
                      <span class="font-semibold text-emerald-800">Projekt</span>
                    @elseif ($result['competitor'] !== null)
                      <span class="font-semibold text-amber-900">{{ $result['competitor'] }}</span>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </x-panel.card>
  @endif

  <div class="mt-6 space-y-1 text-xs text-slate-500">
    <p><strong class="font-medium text-slate-600">#</strong> — Pozycja SERP: miejsce wśród wyników organicznych (bez reklam i modułów). <strong class="font-medium text-slate-600">Element</strong> — miejsce wśród wszystkich elementów strony wyników (z modułami), dla orientacji. Wyróżniony fragment jest pokazywany osobno i nie jest liczony jako #1.</p>
    <p>Pełne wyniki każdego pomiaru są zapisywane — konkurent dodany później widzi też historię. Wyniki Google różnią się między użytkownikami, urządzeniami i w czasie; pomiar to pojedyncza obserwacja dla rynku projektu.</p>
  </div>
@endsection
