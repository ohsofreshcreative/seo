@extends('panel.layouts.app', ['active' => 'gaps'])

@section('title', 'Strony konkurencji · ' . $project->name)

@php
  use App\Http\Controllers\Panel\GapContentController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Discovery\CandidateRow;
  use OsfSeo\Opportunities\Text;

  $base = PanelUrl::project($project->publicId, 'gaps/pages');
  $query = array_filter(['competitor' => $competitor, 'q' => $q, 'sort' => $sort === 'gap' ? null : $sort], static fn ($value): bool => $value !== null && $value !== '');
  $url = fn (array $changes = []) => $base . (($built = http_build_query(array_filter($changes + $query, static fn ($value): bool => $value !== null && $value !== ''))) !== '' ? '?' . $built : '');
  $pages = max(1, (int) ceil($result['total'] / 50));
  $safeUrl = static fn (?string $value): bool => $value !== null && preg_match('#^https?://#i', $value) === 1;
@endphp

@section('content')
  <x-panel.page-header title="Strony konkurencji"
    :description="$project->name . ' · podstrony konkurentów z zaimportowanych fraz (DataForSEO Labs): ile fraz, w tym luk projektu, przyciągają'" />

  @include('panel.gaps.partials.tabs', ['tab' => 'pages'])

  <form method="get" action="{{ $base }}" class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
    <div>
      <label for="f-competitor" class="block text-xs font-medium text-slate-600">Konkurent</label>
      <select id="f-competitor" name="competitor" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <option value="">Wszyscy</option>
        @foreach ($competitors as $option)
          <option value="{{ $option->publicId }}" @selected($competitor === $option->publicId)>{{ $option->name }}</option>
        @endforeach
      </select>
    </div>
    <div class="min-w-64 grow">
      <label for="f-q" class="block text-xs font-medium text-slate-600">Adres lub tytuł</label>
      <input id="f-q" name="q" type="search" value="{{ $q }}" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
    </div>
    <div>
      <label for="f-sort" class="block text-xs font-medium text-slate-600">Sortuj</label>
      <select id="f-sort" name="sort" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        @foreach (['gap' => 'Luki projektu', 'keywords' => 'Liczba fraz', 'volume' => 'Wolumen', 'top10' => 'Frazy w TOP10'] as $value => $label)
          <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
        @endforeach
      </select>
    </div>
    <x-panel.button type="submit">Filtruj</x-panel.button>
  </form>

  <div class="mt-6">
    @if ($result['total'] === 0)
      <x-panel.empty-state title="Brak stron konkurencji"
        description="Strony pojawiają się po imporcie fraz konkurentów i przeliczeniu luk (bez dodatkowych kosztów)." />
    @else
      <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-3 py-2">Strona konkurenta</th>
              <th class="px-3 py-2 text-right">Frazy</th>
              <th class="px-3 py-2 text-right" title="Frazy w TOP3 / TOP10 / TOP20 (Labs)">TOP3 / 10 / 20</th>
              <th class="px-3 py-2 text-right" title="Suma wolumenu fraz strony">Wolumen</th>
              <th class="px-3 py-2 text-right" title="Frazy strony, które są lukami projektu (brak, słaba, nieznana widoczność)">Luki projektu</th>
              <th class="px-3 py-2 text-right" title="Frazy, na które projekt rankuje porównywalnie albo wyżej">Wspólne</th>
              <th class="px-3 py-2">Intencja</th>
              <th class="px-3 py-2">Grupa</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach ($result['rows'] as $row)
              <tr class="hover:bg-slate-50">
                <td class="max-w-md px-3 py-2">
                  <a href="{{ GapContentController::pageUrl($project->publicId, $row['competitor_public_id'], $row['url_key_hex']) }}" class="block break-all font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $row['title'] ?? $row['url'] }}</a>
                  <span class="block break-all text-xs text-slate-500">{{ $row['competitor_name'] }} · {{ $row['url'] }}</span>
                </td>
                <td class="px-3 py-2 text-right tabular-nums">{{ Format::number((int) $row['keywords']) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ $row['top3'] }} / {{ $row['top10'] }} / {{ $row['top20'] }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ Format::number((int) $row['total_volume']) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ Format::number((int) $row['gap_keywords']) }} <span class="block text-xs text-slate-500">wol. {{ Format::number((int) $row['gap_volume']) }}</span></td>
                <td class="px-3 py-2 text-right tabular-nums">{{ Format::number((int) $row['overlap_keywords']) }}</td>
                <td class="px-3 py-2 text-xs text-slate-600">{{ CandidateRow::intentLabel($row['main_intent']) ?? '—' }}</td>
                <td class="max-w-xs px-3 py-2 text-xs">
                  @if ($row['cluster_public_id'] !== null)
                    <a href="{{ GapContentController::clusterUrl($project->publicId, $row['cluster_public_id']) }}" class="text-brand-600 hover:underline">{{ $row['cluster_label'] }}</a>
                  @else
                    <span class="text-slate-400">—</span>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <nav class="mt-4 flex items-center justify-between text-sm" aria-label="Strony">
        <span class="text-slate-600">{{ Format::number($result['total']) }} {{ Text::plural($result['total'], 'strona', 'strony', 'stron') }}@if ($pages > 1) · strona {{ $pageNumber }} z {{ $pages }}@endif</span>
        @if ($pages > 1)
          <div class="flex gap-2">
            @if ($pageNumber > 1)
              <x-panel.button variant="secondary" :href="$url(['page' => $pageNumber - 1])">Poprzednia</x-panel.button>
            @endif
            @if ($pageNumber < $pages)
              <x-panel.button variant="secondary" :href="$url(['page' => $pageNumber + 1])">Następna</x-panel.button>
            @endif
          </div>
        @endif
      </nav>
    @endif
  </div>

  @include('panel.gaps.partials.disclaimer')
@endsection
