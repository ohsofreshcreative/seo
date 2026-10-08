@extends('panel.layouts.app', ['active' => 'pages'])

@php
  use App\Http\Controllers\Panel\PagesController;
  use App\Http\Controllers\Panel\StrategyTopicsController;
  use App\Panel\Format;
  use App\Panel\PageLabels;
  use App\Panel\PanelUrl;

  $projectId = $project->publicId;
  $data = (array) ($snapshot['data'] ?? []);
  $meta = (array) ($data['meta'] ?? []);
  $headings = (array) ($data['headings'] ?? []);
  $content = (array) ($data['content'] ?? []);
  $links = (array) ($data['links'] ?? []);
  $technical = (array) ($data['technical'] ?? []);
  $quality = (array) ($data['quality'] ?? []);
  $limits = (array) ($data['limits'] ?? []);
  $isLatest = $snapshot === null || $snapshot['id'] === $latest;
  $title = $snapshot['title'] ?? null;
  $retryUrl = PagesController::planUrl($projectId, ['mode' => 'page', 'page' => $page['id'], 'force' => $cache === 'fresh']);
@endphp

@section('title', ($title ?? $page['url']) . ' · Strony · ' . $project->name)

@section('content')
  <p class="mb-4 text-sm"><a href="{{ PanelUrl::project($projectId, 'pages') }}" class="text-brand-600 hover:underline">← Strony</a></p>

  <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
    <div class="min-w-0">
      <h1 class="break-words text-2xl font-semibold tracking-tight text-slate-900">{{ $title ?? 'Strona bez zapisanej kopii' }}</h1>
      @if (PageLabels::safeUrl($page['url']))
        <a href="{{ $page['url'] }}" target="_blank" rel="noopener noreferrer" class="mt-1 block break-all text-sm text-brand-600 hover:underline">{{ $page['url'] }}</a>
      @else
        <p class="mt-1 break-all text-sm text-slate-600">{{ $page['url'] }}</p>
      @endif
      <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-slate-700">{{ PageLabels::kind($page['kind']) }}</span>
        <span>{{ PageLabels::source($page['source']) }}</span>
        <x-panel.page-cache :cache="$cache" />
        @if ($topic !== null)
          <span>· temat <a href="{{ StrategyTopicsController::topicUrl($projectId, $topic['id']) }}" class="text-brand-600 hover:underline">{{ $topic['label'] ?? '—' }}</a></span>
        @endif
      </div>
    </div>
    @if ($can_fetch)
      <div class="flex shrink-0 flex-wrap gap-2">
        <x-panel.button variant="secondary" :href="$retryUrl">{{ $snapshot === null ? 'Pobierz stronę' : 'Pobierz ponownie' }}</x-panel.button>
      </div>
    @endif
  </div>

  @if (! $isLatest)
    <div class="mb-4 rounded-md border border-sky-200 bg-sky-50 px-4 py-2 text-sm text-sky-900">
      Oglądasz starszą kopię z {{ Format::datetime($snapshot['fetched_at']) }}. <a href="{{ PagesController::pageUrl($projectId, $page['id']) }}" class="font-medium underline">Przejdź do najnowszej</a>
    </div>
  @endif

  @if ($page['status'] === 'failed' || $page['status'] === 'blocked')
    <div class="mb-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900">
      Ostatnia próba pobrania ({{ Format::datetime($page['last_attempt_at']) }}) nie powiodła się{{ $page['last_error'] !== null ? ': ' . PageLabels::error($page['last_error']) : '.' }}
      @if ($snapshot !== null) Pokazujemy ostatnią poprawną kopię. @endif
      To nie dowód, że strona nie istnieje.
    </div>
  @endif

  @if ($snapshot === null)
    <x-panel.empty-state title="Brak zapisanej kopii strony" description="Strona nie została jeszcze pobrana albo żadna próba się nie powiodła. Otwarcie tej strony niczego nie pobiera.">
      @if ($can_fetch)
        <x-panel.button :href="$retryUrl">Zaplanuj pobranie</x-panel.button>
      @endif
    </x-panel.empty-state>
  @else
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <div class="min-w-0 space-y-6 lg:col-span-2">
        <x-panel.card>
          <h2 class="text-base font-semibold text-slate-900">Dane strony</h2>
          <dl class="mt-3 divide-y divide-slate-100 text-sm">
            @foreach ([
              'Adres końcowy' => $snapshot['final_url'],
              'Kod odpowiedzi HTTP' => (string) $snapshot['http_status'],
              'Tytuł (title)' => $meta['title'] ?? null,
              'Opis meta' => $meta['description'] ?? null,
              'Język' => $meta['lang'] ?? null,
            ] as $label => $value)
              <div class="grid grid-cols-1 gap-1 py-2 sm:grid-cols-3">
                <dt class="text-slate-500">{{ $label }}</dt>
                <dd class="break-words text-slate-900 sm:col-span-2">{{ $value === null || $value === '' ? 'W pobranym HTML nie wykryto' : $value }}</dd>
              </div>
            @endforeach
            <div class="grid grid-cols-1 gap-1 py-2 sm:grid-cols-3">
              <dt class="text-slate-500">Canonical</dt>
              <dd class="break-words text-slate-900 sm:col-span-2">
                {{ PageLabels::canonical($snapshot['canonical_status']) }}
                @if (($meta['canonical'] ?? null) !== null && $snapshot['canonical_status'] !== 'self')
                  <span class="block break-all text-xs text-slate-600">{{ $meta['canonical'] }}</span>
                @endif
              </dd>
            </div>
            <div class="grid grid-cols-1 gap-1 py-2 sm:grid-cols-3">
              <dt class="text-slate-500">Indeksowanie (dyrektywy)</dt>
              <dd class="text-slate-900 sm:col-span-2">
                {{ PageLabels::indexability($snapshot['indexability']) }}
                <span class="block text-xs text-slate-500">Tylko na podstawie dyrektyw robots w HTML i nagłówkach — to nie informacja, czy Google zaindeksował stronę.</span>
              </dd>
            </div>
            <div class="grid grid-cols-1 gap-1 py-2 sm:grid-cols-3">
              <dt class="text-slate-500">Pobrano</dt>
              <dd class="text-slate-900 sm:col-span-2">{{ Format::datetime($snapshot['fetched_at']) }}<span class="block text-xs text-slate-500">ostatnio potwierdzona bez zmian: {{ Format::datetime($snapshot['last_seen_at']) }}; rozmiar {{ Format::number((int) round($snapshot['bytes'] / 1024)) }} KB</span></dd>
            </div>
          </dl>
        </x-panel.card>

        <x-panel.card>
          <h2 class="text-base font-semibold text-slate-900">Nagłówki</h2>
          <p class="mt-1 text-xs text-slate-500">
            @foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $level)
              {{ strtoupper($level) }}: {{ (int) ($headings['counts'][$level] ?? 0) }}@if (! $loop->last) · @endif
            @endforeach
          </p>
          @php($outline = (array) ($headings['outline'] ?? []))
          @if (($outline['missing_h1'] ?? false) || ($outline['multiple_h1'] ?? false) || (int) ($outline['skipped_levels'] ?? 0) > 0)
            <ul class="mt-2 list-disc space-y-0.5 pl-5 text-xs text-amber-800">
              @if ($outline['missing_h1'] ?? false)<li>W pobranym HTML nie wykryto nagłówka H1.</li>@endif
              @if ($outline['multiple_h1'] ?? false)<li>Kilka nagłówków H1.</li>@endif
              @if ((int) ($outline['skipped_levels'] ?? 0) > 0)<li>Pominięte poziomy nagłówków: {{ (int) $outline['skipped_levels'] }}.</li>@endif
            </ul>
          @endif
          @if (($headings['list'] ?? []) === [])
            <p class="mt-2 text-sm text-slate-500">W pobranym HTML nie wykryto nagłówków.</p>
          @else
            <ul class="mt-3 space-y-1 text-sm">
              @foreach (array_slice((array) $headings['list'], 0, 100) as $heading)
                <li class="flex min-w-0 gap-2" style="padding-left: {{ max(0, ((int) $heading['level'] - 1) * 12) }}px">
                  <span class="shrink-0 rounded bg-slate-100 px-1.5 text-xs font-semibold text-slate-600">H{{ (int) $heading['level'] }}</span>
                  <span class="min-w-0 break-words text-slate-800">{{ $heading['text'] }}</span>
                </li>
              @endforeach
            </ul>
          @endif
        </x-panel.card>

        <x-panel.card>
          <h2 class="text-base font-semibold text-slate-900">Treść ({{ Format::number((int) $snapshot['word_count']) }} słów)</h2>
          <p class="mt-1 text-xs text-slate-500">Tekst wyodrębniony z pobranego HTML (bez uruchamiania JavaScriptu); sekcje według nagłówków.</p>
          @if (($content['sections'] ?? []) === [])
            <p class="mt-2 text-sm text-slate-500">W pobranym HTML nie wykryto treści głównej.</p>
          @else
            <div class="mt-3 space-y-2">
              @foreach ((array) $content['sections'] as $section)
                <details class="rounded-md border border-slate-200 px-3 py-2 text-sm">
                  <summary class="cursor-pointer break-words">
                    @if ($section['level'] !== null)<span class="mr-1 text-xs font-semibold text-slate-500">H{{ (int) $section['level'] }}</span>@endif
                    <span class="font-medium text-slate-800">{{ $section['heading'] ?? 'Wstęp (bez nagłówka)' }}</span>
                    <span class="text-xs text-slate-500">· {{ Format::number((int) $section['words']) }} słów</span>
                  </summary>
                  <p class="mt-2 whitespace-pre-line break-words text-slate-700">{{ $section['text'] }}</p>
                </details>
              @endforeach
            </div>
          @endif
        </x-panel.card>

        <x-panel.card>
          <h2 class="text-base font-semibold text-slate-900">Linki</h2>
          @php($linkCounts = (array) ($links['counts'] ?? []))
          <p class="mt-1 text-xs text-slate-500">Wewnętrzne: {{ (int) ($linkCounts['internal'] ?? 0) }} · zewnętrzne: {{ (int) ($linkCounts['external'] ?? 0) }} · nofollow: {{ (int) ($linkCounts['nofollow'] ?? 0) }} · w treści głównej: {{ (int) ($linkCounts['in_main'] ?? 0) }}</p>
          @foreach (['internal' => 'Wewnętrzne', 'external' => 'Zewnętrzne'] as $key => $label)
            @if (($links[$key] ?? []) !== [])
              <details class="mt-3 text-sm">
                <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }} ({{ count($links[$key]) }})</summary>
                <ul class="mt-2 space-y-1">
                  @foreach (array_slice((array) $links[$key], 0, 100) as $link)
                    <li class="min-w-0 text-xs">
                      <span class="break-words text-slate-800">{{ $link['anchor'] !== '' ? $link['anchor'] : '(bez tekstu)' }}</span>
                      <span class="block break-all text-slate-500">{{ $link['url'] }}{{ ($link['nofollow'] ?? false) ? ' · nofollow' : '' }}</span>
                    </li>
                  @endforeach
                </ul>
              </details>
            @endif
          @endforeach
        </x-panel.card>
      </div>

      <div class="min-w-0 space-y-6">
        <x-panel.card>
          <h2 class="text-base font-semibold text-slate-900">Jakość ekstrakcji</h2>
          <p class="mt-2"><span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ PageLabels::qualityTone($snapshot['content_quality']) }}">{{ PageLabels::quality($snapshot['content_quality']) }}</span></p>
          @if (($quality['reasons'] ?? []) !== [])
            <ul class="mt-2 list-disc space-y-0.5 pl-5 text-xs text-slate-700">
              @foreach ((array) $quality['reasons'] as $reason)
                <li>{{ PageLabels::qualityReason((string) $reason) }}</li>
              @endforeach
            </ul>
          @endif
          @if (($quality['js_markers'] ?? []) !== [])
            <p class="mt-2 text-xs text-slate-600">Ślady aplikacji JavaScript: {{ count($quality['js_markers']) }}. Część treści może być widoczna dopiero po uruchomieniu skryptów.</p>
          @endif
          @if ($snapshot['content_quality'] !== 'good')
            <p class="mt-2 text-xs text-amber-800">W pobranym HTML mogło zabraknąć części treści (np. ładowanej JavaScriptem). Brak tekstu w kopii nie oznacza braku na stronie.</p>
          @endif
          @if (($limits['truncated'] ?? []) !== [])
            <ul class="mt-2 list-disc space-y-0.5 pl-5 text-xs text-slate-600">
              @foreach ((array) $limits['truncated'] as $item)
                <li>{{ PageLabels::truncated((string) $item) }}</li>
              @endforeach
            </ul>
          @endif
        </x-panel.card>

        <x-panel.card>
          <h2 class="text-base font-semibold text-slate-900">Historia kopii</h2>
          <ul class="mt-2 divide-y divide-slate-100 text-sm">
            @foreach ($snapshots as $item)
              <li class="py-2">
                @if ($item['id'] === $snapshot['id'])
                  <span class="font-medium text-slate-900">{{ Format::datetime($item['fetched_at']) }}</span> <span class="text-xs text-slate-500">(oglądana)</span>
                @else
                  <a href="{{ PagesController::pageUrl($projectId, $page['id'], $item['id']) }}" class="text-brand-600 hover:underline">{{ Format::datetime($item['fetched_at']) }}</a>
                @endif
                <span class="block text-xs text-slate-500">HTTP {{ $item['http_status'] }} · {{ Format::number((int) $item['word_count']) }} słów · treść {{ mb_strtolower(PageLabels::quality($item['content_quality'])) }}</span>
              </li>
            @endforeach
          </ul>
          <p class="mt-2 text-xs text-slate-500">Nowa kopia powstaje tylko, gdy zmieni się treść strony.</p>
        </x-panel.card>

        @if ($serp !== [])
          <x-panel.card>
            <h2 class="text-base font-semibold text-slate-900">Wyniki SERP</h2>
            <ul class="mt-2 space-y-1 text-sm">
              @foreach ($serp as $link)
                <li class="break-words text-slate-700">„{{ $link['keyword'] ?? '—' }}” — #{{ $link['rank_group'] }} <span class="text-xs text-slate-500">(pomiar {{ Format::date($link['serp_checked_at']) }})</span></li>
              @endforeach
            </ul>
          </x-panel.card>
        @endif

        @if ($fetches !== [])
          <x-panel.card>
            <h2 class="text-base font-semibold text-slate-900">Historia pobrań</h2>
            <ul class="mt-2 divide-y divide-slate-100 text-xs">
              @foreach ($fetches as $fetch)
                <li class="py-2">
                  <span class="font-medium text-slate-800">{{ Format::datetime($fetch['started_at']) }}</span>
                  <span class="text-slate-500">· {{ $fetch['trigger'] === 'panel' ? 'panel' : 'WP-CLI' }}</span>
                  <span class="block text-slate-700">{{ PageLabels::outcome($fetch['outcome']) }}{{ $fetch['http_status'] !== null ? ' (HTTP ' . $fetch['http_status'] . ')' : '' }}</span>
                  @if ($fetch['error'] !== null)<span class="block text-slate-500">{{ PageLabels::error($fetch['error']) }}</span>@endif
                </li>
              @endforeach
            </ul>
          </x-panel.card>
        @endif
      </div>
    </div>
  @endif
@endsection
