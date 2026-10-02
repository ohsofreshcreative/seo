@extends('panel.layouts.app', ['active' => 'gaps'])

@section('title', 'Luki SEO · ' . $project->name)

@php
  use App\Http\Controllers\Panel\GapContentController;
  use App\Http\Controllers\Panel\GapKeywordsController;
  use App\Http\Controllers\Panel\GapsController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Gap\GapRun;
  use OsfSeo\Opportunities\Text;

  $base = PanelUrl::project($project->publicId, 'gaps');
  $types = $counts['types'];
  $hasCompetitors = array_filter($datasets, static fn (array $entry): bool => $entry['role'] === 'competitor') !== [];
  $imported = array_filter($datasets, static fn (array $entry): bool => $entry['dataset'] !== null && $entry['dataset']['imported_at'] !== null) !== [];
  $triggers = ['manual' => 'ręcznie', 'cli' => 'WP-CLI', 'schedule' => 'harmonogram'];
@endphp

@section('content')
  <x-panel.page-header title="Luki SEO"
    :description="$project->name . ' · frazy, na które rankują konkurenci, a projekt nie albo słabiej (DataForSEO Labs), porównane z pomiarem SERP i Google Search Console'">
    @if ($canManage)
      <x-slot:actions>
        <form method="post" action="{{ $base }}/recalculate">
          <x-panel.nonce />
          <x-panel.button type="submit" variant="secondary">Przelicz (bez kosztów)</x-panel.button>
        </form>
        <x-panel.button :href="$base . '/import'">Importuj frazy konkurentów</x-panel.button>
      </x-slot:actions>
    @endif
  </x-panel.page-header>

  @include('panel.gaps.partials.tabs', ['tab' => 'overview'])

  @if ($market === null)
    <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      Rynek projektu ({{ strtoupper($project->country) }} / {{ $project->language }}) nie jest obsługiwany przez dostawcę — zmień kraj i język w ustawieniach projektu.
    </div>
  @elseif (! $configured)
    <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      DataForSEO nie jest skonfigurowane — import fraz konkurentów jest niedostępny. Zapisane dane i praca nad lukami działają dalej.
    </div>
  @endif

  @if (! $hasCompetitors)
    <x-panel.empty-state title="Brak aktywnych konkurentów"
      description="Luki SEO porównują projekt z domenami konkurentów. Najpierw dodaj konkurenta w module „Konkurenci” — dodanie nic nie kosztuje.">
      <x-panel.button :href="PanelUrl::project($project->publicId, 'competitors')">Przejdź do konkurentów</x-panel.button>
    </x-panel.empty-state>
  @else
    @if ($active !== null)
      <div class="mb-6">
        @include('panel.gaps.partials.progress', ['progress' => $active])
        <p class="mt-1 text-xs"><a href="{{ GapsController::runUrl($project->publicId, $active['id']) }}" class="text-brand-600 hover:underline">Szczegóły importu</a></p>
      </div>
    @endif

    @if (! $imported)
      <x-panel.empty-state title="Brak zaimportowanych danych konkurentów"
        :description="'Import pobiera z DataForSEO Labs frazy, na które rankują domeny konkurentów (domyślnie TOP' . $settings->fetchMaxRank . ', wolumen ≥ ' . $settings->fetchMinVolume . '), oraz punkt odniesienia dla domeny projektu. Przed wysłaniem czegokolwiek zobaczysz liczbę żądań i maksymalny koszt.'">
        @if ($canManage)
          <x-panel.button :href="$base . '/import'">Sprawdź koszt importu</x-panel.button>
        @endif
      </x-panel.empty-state>
    @else
      <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        @foreach ([
          ['Brak widoczności', $types['missing'] ?? 0, 'konkurent rankuje, projekt nie (wiarygodny dowód)', $base . '/keywords?type=missing'],
          ['Słaba widoczność', $types['weak'] ?? 0, 'projekt wyraźnie niżej niż konkurent', $base . '/keywords?type=weak'],
          ['Nieznana', $types['unknown'] ?? 0, 'brak wiarygodnych danych o projekcie', $base . '/keywords?type=unknown'],
          ['Wysoki priorytet', $counts['high'], 'luki z priorytetem ≥ 60', $base . '/keywords?min_priority=60'],
          ['Nowe luki', $counts['new'], 'od poprzedniego importu', $base . '/keywords?sort=first_seen'],
          ['Potencjalne luki treści', $counts['content']['new_page'] ?? 0, 'grupy fraz bez przekonującej strony', $base . '/content?content=new_page'],
        ] as [$label, $count, $hint, $href])
          <a href="{{ $href }}" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm hover:border-brand-500">
            <p class="text-xs font-medium text-slate-500">{{ $label }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ Format::number($count) }}</p>
            <p class="text-xs text-slate-500">{{ $hint }}</p>
          </a>
        @endforeach
      </div>
      <p class="mt-2 text-xs text-slate-500">
        Poza lukami: porównywalna widoczność {{ Format::number($types['competitive'] ?? 0) }}, projekt silniejszy {{ Format::number($types['stronger'] ?? 0) }},
        odfiltrowane (marka, wykluczenia, wolumen, trudność, język) {{ Format::number($counts['filtered']) }} ·
        <a href="{{ $base }}/keywords?type=all" class="text-brand-600 hover:underline">wszystkie frazy konkurentów</a>
      </p>

      <div class="mt-8 grid gap-6 xl:grid-cols-2">
        <x-panel.card>
          <div class="flex items-baseline justify-between gap-3">
            <h2 class="text-base font-semibold text-slate-900">Najważniejsze luki fraz</h2>
            <a href="{{ $base }}/keywords" class="text-sm text-brand-600 hover:underline">Wszystkie</a>
          </div>
          @if ($top === [])
            <p class="mt-3 text-sm text-slate-500">Brak luk dla obecnych ustawień.</p>
          @else
            <div class="mt-3 overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead>
                  <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="py-2 pr-3 font-medium">Fraza</th>
                    <th class="py-2 pr-3 text-center font-medium">Priorytet</th>
                    <th class="py-2 pr-3 font-medium">Luka</th>
                    <th class="py-2 pr-3 text-right font-medium">Wolumen</th>
                    <th class="py-2 text-right font-medium" title="Najlepsza pozycja konkurenta w bazie DataForSEO Labs">Konkurent (Labs)</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  @foreach ($top as $row)
                    <tr>
                      <td class="max-w-xs py-2 pr-3"><a href="{{ GapKeywordsController::keywordUrl($project->publicId, $row['public_id']) }}" class="break-words font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $row['keyword'] }}</a></td>
                      <td class="py-2 pr-3 text-center"><x-panel.score :value="$row['priority']" size="sm" /></td>
                      <td class="py-2 pr-3"><x-panel.gap-type :value="$row['gap_type']" /></td>
                      <td class="py-2 pr-3 text-right tabular-nums">{{ Format::number($row['search_volume'] === null ? null : (int) $row['search_volume']) }}</td>
                      <td class="py-2 text-right text-xs text-slate-600">{{ $row['competitor_name'] ?? '—' }} <span class="tabular-nums">#{{ $row['best_competitor_rank'] }}</span></td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          @endif
        </x-panel.card>

        <x-panel.card>
          <div class="flex items-baseline justify-between gap-3">
            <h2 class="text-base font-semibold text-slate-900">Potencjalne luki treści</h2>
            <a href="{{ $base }}/content" class="text-sm text-brand-600 hover:underline">Wszystkie grupy</a>
          </div>
          <p class="mt-1 text-xs text-slate-500">Grupy fraz, dla których projekt nie ma przekonującej strony, a konkurenci rankują podstronami — heurystyka do sprawdzenia.</p>
          @if ($content === [])
            <p class="mt-3 text-sm text-slate-500">Brak grup z potencjalną luką treści.</p>
          @else
            <ul class="mt-3 divide-y divide-slate-100 text-sm">
              @foreach (array_slice($content, 0, 8) as $cluster)
                <li class="flex items-center justify-between gap-3 py-2">
                  <a href="{{ GapContentController::clusterUrl($project->publicId, $cluster['public_id']) }}" class="font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $cluster['label'] }}</a>
                  <span class="shrink-0 text-xs text-slate-500">{{ Format::number((int) $cluster['keywords_count']) }} {{ Text::plural((int) $cluster['keywords_count'], 'fraza', 'frazy', 'fraz') }} · wolumen luk {{ Format::number((int) $cluster['gap_volume']) }}</span>
                </li>
              @endforeach
            </ul>
          @endif
        </x-panel.card>
      </div>
    @endif

    <x-panel.card class="mt-8">
      <h2 class="text-base font-semibold text-slate-900">Dane domen</h2>
      <p class="mt-1 text-xs text-slate-500">Zbiory fraz domen są wspólne dla projektów na tym samym rynku i odświeżane po {{ $settings->refreshDays }} dniach (import ręczny albo harmonogram). Domena projektu to punkt odniesienia (TOP100).</p>
      <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
              <th class="py-2 pr-4 font-medium">Domena</th>
              <th class="py-2 pr-4 font-medium">Stan</th>
              <th class="py-2 pr-4 font-medium">Zakres</th>
              <th class="py-2 pr-4 text-right font-medium">Frazy</th>
              <th class="py-2 pr-4 font-medium">Dane Labs z</th>
              <th class="py-2 font-medium">Import</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach ($datasets as $entry)
              @php($dataset = $entry['dataset'])
              <tr>
                <td class="py-2 pr-4">
                  <span class="font-medium text-slate-900">{{ $entry['label'] }}</span>
                  <span class="block text-xs text-slate-500">{{ $entry['domain'] }}{{ $entry['role'] === 'project' ? ' · projekt (punkt odniesienia)' : '' }}</span>
                </td>
                <td class="py-2 pr-4 text-slate-700">
                  {{ $entry['status_label'] }}
                  @if ($dataset !== null && $dataset['imported_at'] !== null && ! $entry['fresh'])
                    <span class="block text-xs text-amber-700">do odświeżenia</span>
                  @endif
                </td>
                <td class="py-2 pr-4 text-xs text-slate-600">{{ $dataset === null || $dataset['coverage'] === null ? '—' : 'TOP' . $dataset['coverage']['max_rank'] . ', wolumen ≥ ' . Format::number($dataset['coverage']['min_volume']) . ', maks. ' . Format::number($dataset['coverage']['max_rows']) }}</td>
                <td class="py-2 pr-4 text-right tabular-nums">{{ $dataset === null ? '—' : Format::number($dataset['rows_present']) }}@if ($dataset !== null && $dataset['total_count'] !== null && $dataset['total_count'] > $dataset['rows_present']) <span class="block text-xs text-slate-500">z {{ Format::number($dataset['total_count']) }} u dostawcy</span>@endif</td>
                <td class="py-2 pr-4 text-xs text-slate-600">{{ $dataset === null || $dataset['labs_updated_at'] === null ? '—' : Format::date(substr($dataset['labs_updated_at'], 0, 10)) }}</td>
                <td class="py-2 text-xs text-slate-600">{{ $dataset === null || $dataset['imported_at'] === null ? 'nie pobrano' : Format::datetime($dataset['imported_at']) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </x-panel.card>

    @if ($recent !== [])
      <x-panel.card class="mt-6">
        <h2 class="text-base font-semibold text-slate-900">Ostatnie importy</h2>
        <ul class="mt-3 divide-y divide-slate-100 text-sm">
          @foreach ($recent as $run)
            <li class="flex flex-wrap items-center justify-between gap-3 py-2">
              <a href="{{ GapsController::runUrl($project->publicId, $run->publicId) }}" class="text-brand-600 hover:underline">
                {{ Format::datetime($run->createdAt) }} · TOP{{ $run->coverage->maxRank }}, wolumen ≥ {{ Format::number($run->coverage->minVolume) }} · {{ $triggers[$run->trigger] ?? $run->trigger }}
              </a>
              <span @class(['text-xs', 'text-amber-700' => in_array($run->status, [GapRun::FAILED, GapRun::PARTIAL, GapRun::PAUSED], true), 'text-slate-500' => ! in_array($run->status, [GapRun::FAILED, GapRun::PARTIAL, GapRun::PAUSED], true)])>
                {{ $run->statusLabel() }} · {{ Format::number($run->rowsReceived) }} {{ Text::plural($run->rowsReceived, 'fraza', 'frazy', 'fraz') }}@if ($canManage) · {{ Format::usd($run->cost, 4) }}@endif
              </span>
            </li>
          @endforeach
        </ul>
      </x-panel.card>
    @endif
  @endif

  @include('panel.gaps.partials.disclaimer')
@endsection
