@extends('panel.layouts.app', ['active' => 'keywords'])

@section('title', 'Frazy · ' . $project->name)

@php
  use App\Panel\Format;
  use App\Panel\PanelUrl;

  $base = PanelUrl::project($project->publicId, 'keywords');
  $url = fn (array $changes = []) => $base . (($query = http_build_query($filters->with($changes)->toQuery())) !== '' ? '?' . $query : '');
  $sortUrl = function (string $sort) use ($filters, $url) {
    $direction = $filters->sort === $sort
      ? ($filters->direction === 'asc' ? 'desc' : 'asc')
      : (in_array($sort, ['position', 'keyword'], true) ? 'asc' : 'desc');

    return $url(['sort' => $sort, 'direction' => $direction, 'page' => 1]);
  };
  $sortMark = fn (string $sort) => $filters->sort === $sort ? ($filters->direction === 'asc' ? ' ▲' : ' ▼') : '';
  $columns = [
    ['keyword', 'Fraza', 'text-left'],
    ['clicks', 'Kliknięcia', 'text-right'],
    ['clicks_change', 'Δ', 'text-right'],
    ['impressions', 'Wyświetlenia', 'text-right'],
    ['impressions_change', 'Δ', 'text-right'],
    ['ctr', 'CTR', 'text-right'],
    [null, 'Δ CTR', 'text-right'],
    ['position', 'Średnia pozycja (GSC)', 'text-right'],
    ['position_change', 'Zmiana pozycji', 'text-right'],
    [null, 'Strona docelowa', 'text-left'],
  ];
@endphp

@section('content')
  <x-panel.page-header title="Frazy" :description="$project->name . ' · dane z Google Search Console'" />

  @if (! $page->hasData())
    <x-panel.empty-state title="Brak danych fraz"
      description="Frazy pojawią się po pierwszej synchronizacji z Google Search Console (najpierw najnowsze dni, potem historia).">
      <x-panel.button :href="PanelUrl::project($project->publicId, 'search-console')">Przejdź do Search Console</x-panel.button>
    </x-panel.empty-state>
  @else
    @php($period = $page->period)
    <div class="mb-4 flex flex-col gap-3 text-sm text-slate-600 lg:flex-row lg:items-center lg:justify-between">
      <p>
        Okres: <span class="font-medium text-slate-900">{{ Format::date($period->current->start) }} – {{ Format::date($period->current->end) }}</span>
        ({{ $period->days }} dni do ostatniej dostępnej daty GSC), porównanie z {{ Format::date($period->previous->start) }} – {{ Format::date($period->previous->end) }}.
      </p>
      <nav class="flex gap-1" aria-label="Długość okresu">
        @foreach (\OsfSeo\Analytics\Period::ALLOWED_DAYS as $option)
          <a href="{{ $url(['days' => $option, 'page' => 1]) }}" @class([
            'rounded-md px-3 py-1.5 font-medium',
            'bg-brand-700 text-white' => $period->days === $option,
            'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50' => $period->days !== $option,
          ]) @if ($period->days === $option) aria-current="true" @endif>{{ $option }} dni</a>
        @endforeach
      </nav>
    </div>

    <form method="get" action="{{ $base }}" class="mb-6 grid grid-cols-2 gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-6">
      <input type="hidden" name="days" value="{{ $period->days }}">
      <input type="hidden" name="sort" value="{{ $filters->sort }}">
      <input type="hidden" name="dir" value="{{ $filters->direction }}">
      <label class="col-span-2 block text-sm">
        <span class="text-slate-600">Fraza zawiera</span>
        <input type="search" name="q" value="{{ $filters->search }}" maxlength="100" class="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
      </label>
      <label class="block text-sm">
        <span class="text-slate-600">Pozycja od</span>
        <input type="number" name="pos_min" value="{{ $filters->positionMin }}" min="1" max="1000" step="0.1" class="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
      </label>
      <label class="block text-sm">
        <span class="text-slate-600">Pozycja do</span>
        <input type="number" name="pos_max" value="{{ $filters->positionMax }}" min="1" max="1000" step="0.1" class="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
      </label>
      <label class="block text-sm">
        <span class="text-slate-600">Min. wyświetleń</span>
        <input type="number" name="min_impr" value="{{ $filters->minImpressions }}" min="1" class="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
      </label>
      <label class="block text-sm">
        <span class="text-slate-600">Zmiana pozycji</span>
        <select name="movement" class="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
          <option value="">Wszystkie</option>
          <option value="gains" @selected($filters->movement === 'gains')>Wzrosty</option>
          <option value="losses" @selected($filters->movement === 'losses')>Spadki</option>
        </select>
      </label>
      <div class="col-span-2 flex items-end gap-2 md:col-span-6">
        <x-panel.button type="submit">Filtruj</x-panel.button>
        <x-panel.button variant="secondary" :href="$base . ($period->days !== 28 ? '?days=' . $period->days : '')">Wyczyść</x-panel.button>
        <span class="ml-auto text-sm text-slate-600">{{ Format::number($page->total) }} {{ $page->total === 1 ? 'fraza' : 'fraz' }}</span>
      </div>
    </form>

    @if ($filters->movement !== null)
      <p class="mb-3 text-xs text-slate-500">
        Wzrosty i spadki: frazy z co najmniej {{ $moversMinImpressions }} wyświetleniami w obu okresach (bez szumu fraz z pojedynczymi wyświetleniami).
      </p>
    @endif

    @if ($page->rows === [])
      <x-panel.empty-state title="Brak fraz dla wybranych filtrów" description="Zmień filtry albo okres." />
    @else
      <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50">
            <tr>
              @foreach ($columns as [$sort, $title, $align])
                <th scope="col" class="{{ $align }} whitespace-nowrap px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-600">
                  @if ($sort)
                    <a href="{{ $sortUrl($sort) }}" class="hover:text-slate-900" @if ($filters->sort === $sort) aria-sort="{{ $filters->direction === 'asc' ? 'ascending' : 'descending' }}" @endif>{{ $title }}{{ $sortMark($sort) }}</a>
                  @else
                    {{ $title }}
                  @endif
                </th>
              @endforeach
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach ($page->rows as $row)
              <tr class="hover:bg-slate-50">
                <td class="min-w-48 max-w-xs px-3 py-2 text-slate-900">
                  <span class="break-words">{{ $row->keyword }}</span>
                  @if ($row->isNew())
                    <span class="ml-1 inline-flex rounded bg-sky-50 px-1.5 py-0.5 text-xs font-medium text-sky-700">nowa</span>
                  @endif
                </td>
                <td class="px-3 py-2 text-right tabular-nums">{{ Format::number($row->clicks) }}</td>
                <td class="px-3 py-2 text-right"><x-panel.delta :value="$row->clicksChange()" /></td>
                <td class="px-3 py-2 text-right tabular-nums">{{ Format::number($row->impressions) }}</td>
                <td class="px-3 py-2 text-right"><x-panel.delta :value="$row->impressionsChange()" /></td>
                <td class="px-3 py-2 text-right tabular-nums">{{ Format::percent($row->ctr()) }}</td>
                <td class="px-3 py-2 text-right"><x-panel.delta :value="$row->ctrChange()" format="points" :decimals="1" /></td>
                <td class="px-3 py-2 text-right tabular-nums">{{ Format::position($row->position()) }}</td>
                <td class="px-3 py-2 text-right">
                  @if ($row->positionChange() === null)
                    <span class="text-slate-400">—</span>
                  @else
                    <x-panel.delta :value="$row->positionChange()" format="position" />
                    <span class="block text-xs text-slate-400">{{ Format::position($row->previousPosition()) }} → {{ Format::position($row->position()) }}</span>
                  @endif
                </td>
                <td class="max-w-xs px-3 py-2">
                  @if ($row->primaryPage && preg_match('#^https?://#i', $row->primaryPage))
                    <a href="{{ $row->primaryPage }}" target="_blank" rel="noopener noreferrer" class="break-all text-brand-600 hover:underline">{{ \OsfSeo\Gsc\Dictionary::path($row->primaryPage) }}</a>
                  @else
                    <span class="text-slate-400">—</span>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      @if ($page->pages() > 1)
        @php($current = $filters->page)
        <nav class="mt-4 flex items-center justify-between text-sm" aria-label="Strony">
          <span class="text-slate-600">Strona {{ $current }} z {{ $page->pages() }}</span>
          <div class="flex gap-2">
            @if ($current > 1)
              <x-panel.button variant="secondary" :href="$url(['page' => $current - 1])">Poprzednia</x-panel.button>
            @endif
            @if ($current < $page->pages())
              <x-panel.button variant="secondary" :href="$url(['page' => $current + 1])">Następna</x-panel.button>
            @endif
          </div>
        </nav>
      @endif
    @endif

    <div class="mt-6 space-y-1 text-xs text-slate-500">
      <p><strong class="font-medium text-slate-600">Średnia pozycja (GSC)</strong> to średnia pozycja z wyświetleń w okresie (ważona wyświetleniami) — nie jest to dokładna pozycja w wynikach Google.</p>
      <p><strong class="font-medium text-slate-600">Zmiana pozycji</strong> = pozycja poprzednia − obecna: wartość dodatnia (↑) oznacza poprawę, np. 15 → 7 = +8. CTR = kliknięcia / wyświetlenia w okresie.</p>
      <p>Google nie pokazuje zapytań zanonimizowanych, dlatego suma kliknięć fraz bywa mniejsza niż suma kliknięć projektu — to oczekiwane. Daty GSC są w czasie pacyficznym.</p>
    </div>
  @endif
@endsection
