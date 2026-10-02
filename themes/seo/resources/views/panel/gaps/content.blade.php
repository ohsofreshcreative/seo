@extends('panel.layouts.app', ['active' => 'gaps'])

@section('title', 'Luki treści · ' . $project->name)

@php
  use App\Http\Controllers\Panel\GapContentController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Gap\ContentGap;
  use OsfSeo\Gap\GapStatus;
  use OsfSeo\Opportunities\Text;

  $base = PanelUrl::project($project->publicId, 'gaps/content');
  $query = array_filter(['content' => $content, 'status' => $status, 'q' => $q, 'sort' => $sort === 'priority' ? null : $sort], static fn ($value): bool => $value !== null && $value !== '');
  $url = fn (array $changes = []) => $base . (($built = http_build_query(array_filter($changes + $query, static fn ($value): bool => $value !== null && $value !== ''))) !== '' ? '?' . $built : '');
  $pages = max(1, (int) ceil($result['total'] / 50));
  $safeUrl = static fn (?string $value): bool => $value !== null && preg_match('#^https?://#i', $value) === 1;
  $confidence = ['low' => 1, 'medium' => 2, 'high' => 3];
@endphp

@section('content')
  <x-panel.page-header title="Luki treści"
    :description="$project->name . ' · grupy powiązanych fraz z heurystyką: czy projekt ma stronę, którą warto wzmocnić, czy to potencjalna luka treści — do sprawdzenia, nie diagnoza'" />

  @include('panel.gaps.partials.tabs', ['tab' => 'content'])

  <div class="flex flex-wrap gap-2 text-sm">
    @foreach ([
      [null, 'Do sprawdzenia', ($counts['content']['new_page'] ?? 0) + ($counts['content']['improve'] ?? 0) + ($counts['content']['unclear'] ?? 0)],
      [ContentGap::NewPage->value, ContentGap::NewPage->label(), $counts['content']['new_page'] ?? 0],
      [ContentGap::Improve->value, ContentGap::Improve->label(), $counts['content']['improve'] ?? 0],
      [ContentGap::Unclear->value, ContentGap::Unclear->label(), $counts['content']['unclear'] ?? 0],
      [ContentGap::Covered->value, ContentGap::Covered->label(), $counts['content']['covered'] ?? 0],
    ] as [$value, $label, $count])
      <a href="{{ $url(['content' => $value, 'page' => null]) }}" @class([
        'rounded-full px-3 py-1 ring-1 ring-inset',
        'bg-brand-700 text-white ring-brand-700' => $content === $value,
        'bg-white text-slate-700 ring-slate-300 hover:bg-slate-50' => $content !== $value,
      ])>{{ $label }} <span class="tabular-nums opacity-80">{{ Format::number($count) }}</span></a>
    @endforeach
  </div>

  <form method="get" action="{{ $base }}" class="mt-4 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
    @if ($content !== null)
      <input type="hidden" name="content" value="{{ $content }}">
    @endif
    <div class="min-w-64 grow">
      <label for="f-q" class="block text-xs font-medium text-slate-600">Szukaj grupy</label>
      <input id="f-q" name="q" type="search" value="{{ $q }}" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
    </div>
    <div>
      <label for="f-status" class="block text-xs font-medium text-slate-600">Status</label>
      <select id="f-status" name="status" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <option value="" @selected($status === '')>Bez odrzuconych</option>
        <option value="all" @selected($status === 'all')>Wszystkie</option>
        @foreach (GapStatus::cases() as $option)
          <option value="{{ $option->value }}" @selected($status === $option->value)>{{ $option->label() }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label for="f-sort" class="block text-xs font-medium text-slate-600">Sortuj</label>
      <select id="f-sort" name="sort" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        @foreach (['priority' => 'Priorytet', 'volume' => 'Wolumen luk', 'keywords' => 'Liczba fraz', 'competitor_rank' => 'Pozycja konkurenta'] as $value => $label)
          <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
        @endforeach
      </select>
    </div>
    <x-panel.button type="submit">Filtruj</x-panel.button>
  </form>

  <div class="mt-6">
    @if ($result['total'] === 0)
      <x-panel.empty-state title="Brak grup dla wybranych filtrów"
        description="Grupy powstają z luk fraz (wspólne adresy konkurentów, temat frazy u dostawcy, strona projektu) po imporcie i przeliczeniu." />
    @else
      <form method="post" action="{{ PanelUrl::project($project->publicId, 'gaps/bulk') }}" x-data="{ selected: [] }">
        <x-panel.nonce />
        <input type="hidden" name="kind" value="cluster">
        <input type="hidden" name="return" value="{{ http_build_query($query) }}">
        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
          <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <tr>
                @if ($canManage)
                  <th class="w-8 px-3 py-2"><span class="sr-only">Zaznacz</span></th>
                @endif
                <th class="px-3 py-2">Grupa</th>
                <th class="px-3 py-2 text-center">Priorytet</th>
                <th class="px-3 py-2">Luka treści</th>
                <th class="px-3 py-2 text-right" title="Frazy z luką / wszystkie frazy grupy">Frazy</th>
                <th class="px-3 py-2 text-right" title="Suma wolumenu fraz z luką">Wolumen luk</th>
                <th class="px-3 py-2 text-right" title="Konkurenci rankujący podstronami (nie stroną główną)">Konkurenci</th>
                <th class="px-3 py-2">Strona projektu</th>
                <th class="px-3 py-2">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              @foreach ($result['rows'] as $cluster)
                <tr class="hover:bg-slate-50">
                  @if ($canManage)
                    <td class="px-3 py-2"><input type="checkbox" name="ids[]" value="{{ $cluster['public_id'] }}" x-model="selected" aria-label="Zaznacz {{ $cluster['label'] }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"></td>
                  @endif
                  <td class="min-w-48 max-w-xs px-3 py-2">
                    <a href="{{ GapContentController::clusterUrl($project->publicId, $cluster['public_id']) }}" class="break-words font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $cluster['label'] }}</a>
                    @if ($cluster['best_competitor_rank'] !== null)
                      <span class="block text-xs text-slate-500">najlepiej: {{ $cluster['competitor_name'] ?? '—' }} #{{ $cluster['best_competitor_rank'] }} (Labs)</span>
                    @endif
                  </td>
                  <td class="px-3 py-2 text-center"><x-panel.score :value="$cluster['priority']" size="sm" /></td>
                  <td class="px-3 py-2">
                    <x-panel.content-gap :value="$cluster['content_gap']" />
                    <span class="mt-1 block text-xs text-slate-500">pewność: {{ ['low' => 'niska', 'medium' => 'średnia', 'high' => 'wysoka'][$cluster['confidence']] ?? '—' }}</span>
                  </td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ Format::number((int) $cluster['gap_keywords_count']) }} / {{ Format::number((int) $cluster['keywords_count']) }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ Format::number((int) $cluster['gap_volume']) }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ $cluster['competitor_pages'] }}</td>
                  <td class="max-w-xs px-3 py-2 text-xs">
                    @if ($safeUrl($cluster['target_url']))
                      <a href="{{ $cluster['target_url'] }}" rel="noopener noreferrer" target="_blank" class="break-all text-brand-600 hover:underline">{{ $cluster['target_url'] }}</a>
                    @else
                      <span class="text-slate-500">Brak przypisanej strony</span>
                    @endif
                  </td>
                  <td class="px-3 py-2"><x-panel.candidate-status :status="$cluster['status']" /></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>

        @if ($canManage)
          <div class="mt-3 flex flex-wrap items-center gap-2 text-sm" x-show="selected.length > 0" x-cloak>
            <span class="text-slate-600">Zaznaczone: <span class="font-medium" x-text="selected.length"></span></span>
            <label for="bulk-status" class="sr-only">Nowy status</label>
            <select id="bulk-status" name="status" class="rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
              @foreach (GapStatus::cases() as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
              @endforeach
            </select>
            <x-panel.button type="submit" variant="secondary">Zmień status</x-panel.button>
          </div>
        @endif
      </form>

      <nav class="mt-4 flex items-center justify-between text-sm" aria-label="Strony">
        <span class="text-slate-600">{{ Format::number($result['total']) }} {{ Text::plural($result['total'], 'grupa', 'grupy', 'grup') }}@if ($pages > 1) · strona {{ $pageNumber }} z {{ $pages }}@endif</span>
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

  <div class="mt-8 space-y-1 text-xs text-slate-500">
    <p><strong class="font-medium text-slate-600">Potencjalna luka treści</strong> — projekt nie ma przekonującej strony dla grupy (albo tylko stronę główną), a konkurenci rankują dedykowanymi podstronami. <strong class="font-medium text-slate-600">Istniejąca strona — do wzmocnienia</strong> — projekt ma stronę docelową (pomiar SERP, GSC, Labs albo adres), ale widoczność grupy jest słaba. <strong class="font-medium text-slate-600">Bez luki treści</strong> — strona i porównywalna widoczność. <strong class="font-medium text-slate-600">Niejasne</strong> — za mało danych albo sprzeczne sygnały.</p>
    <p>To heurystyka bez AI — sygnał do sprawdzenia, a nie twierdzenie, że projekt „potrzebuje nowej strony”.</p>
  </div>
@endsection
