@extends('panel.layouts.app', ['active' => 'pages'])

@section('title', 'Strony · ' . $project->name)

@php
  use App\Http\Controllers\Panel\PagesController;
  use App\Http\Controllers\Panel\StrategyTopicsController;
  use App\Panel\Format;
  use App\Panel\PageLabels;
  use App\Panel\PanelUrl;

  $projectId = $project->publicId;
  $base = PanelUrl::project($projectId, 'pages');
  $query = static fn (array $changes) => $base . '?' . http_build_query(array_filter(array_merge($filters, $changes), static fn ($value) => $value !== null && $value !== ''));
  $counts = $list['counts'];
@endphp

@section('content')
  <x-panel.page-header title="Strony" description="Zapisane kopie stron projektu i konkurencji (bez surowego HTML). Ta lista niczego nie pobiera — pobranie tylko na jawne zlecenie.">
    @if ($canFetch)
      <x-slot:actions>
        <x-panel.button :href="PanelUrl::project($projectId, 'pages/fetch')">Pobierz strony</x-panel.button>
      </x-slot:actions>
    @endif
  </x-panel.page-header>

  @if ($jobs !== [])
    <div class="mb-4 rounded-md border border-slate-200 bg-white px-4 py-3 text-sm">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ostatnie zlecenia pobrania</p>
      <ul class="mt-1 space-y-1">
        @foreach ($jobs as $job)
          <li class="text-xs text-slate-600">
            <a href="{{ PagesController::jobUrl($projectId, $job['id']) }}" class="text-brand-600 hover:underline">{{ Format::datetime($job['created_at']) }}</a>:
            {{ mb_strtolower(PageLabels::jobStatus($job['status'])) }}, pobrane {{ $job['items_succeeded'] }} z {{ $job['items_total'] }}
          </li>
        @endforeach
      </ul>
    </div>
  @endif

  <div class="mb-3 flex flex-wrap gap-2 text-sm">
    @foreach (['' => 'Wszystkie (' . Format::number(array_sum($counts)) . ')', 'project' => 'Projekt (' . Format::number($counts['project'] ?? 0) . ')', 'competitor' => 'Konkurencja (' . Format::number($counts['competitor'] ?? 0) . ')'] as $kind => $label)
      <a href="{{ $query(['kind' => $kind === '' ? null : $kind, 'page' => null]) }}" @class(['rounded-md px-3 py-1.5', 'bg-brand-700 text-white' => ($filters['kind'] ?? '') === $kind, 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50' => ($filters['kind'] ?? '') !== $kind])>{{ $label }}</a>
    @endforeach
  </div>

  <form method="get" action="{{ $base }}" class="mb-4 flex flex-wrap items-end gap-3">
    @if ($filters['kind'] ?? null)<input type="hidden" name="kind" value="{{ $filters['kind'] }}">@endif
    <label class="min-w-0 flex-1 text-sm sm:max-w-xs">
      <span class="block text-xs text-slate-500">Szukaj (adres albo tytuł)</span>
      <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="200" class="mt-1 w-full rounded-md border-slate-300 text-sm">
    </label>
    <label class="text-sm">
      <span class="block text-xs text-slate-500">Kopia</span>
      <select name="cache" class="mt-1 rounded-md border-slate-300 text-sm">
        <option value="">Wszystkie</option>
        @foreach (['fresh' => 'Aktualna', 'stale' => 'Starsza', 'missing' => 'Brak kopii'] as $value => $label)
          <option value="{{ $value }}" @selected(($filters['cache'] ?? null) === $value)>{{ $label }}</option>
        @endforeach
      </select>
    </label>
    <label class="text-sm">
      <span class="block text-xs text-slate-500">Jakość treści</span>
      <select name="quality" class="mt-1 rounded-md border-slate-300 text-sm">
        <option value="">Wszystkie</option>
        @foreach (PageLabels::QUALITY as $value => $label)
          <option value="{{ $value }}" @selected(($filters['quality'] ?? null) === $value)>{{ $label }}</option>
        @endforeach
      </select>
    </label>
    <label class="text-sm">
      <span class="block text-xs text-slate-500">Ostatnia próba</span>
      <select name="status" class="mt-1 rounded-md border-slate-300 text-sm">
        <option value="">Wszystkie</option>
        @foreach (PageLabels::STATUSES as $value => $label)
          <option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>
        @endforeach
      </select>
    </label>
    <x-panel.button type="submit" variant="secondary">Filtruj</x-panel.button>
  </form>

  @if ($list['rows'] === [])
    <x-panel.empty-state title="Brak stron" :description="array_filter($filters) !== [] ? 'Żadna strona nie pasuje do filtrów.' : 'Strony pojawią się po pierwszym pobraniu — np. strony docelowej tematu Strategii albo stron konkurencji z zapisanego pomiaru SERP.'">
      @if ($canFetch && array_filter($filters) === [])
        <x-panel.button :href="PanelUrl::project($projectId, 'strategy/topics')">Wybierz temat Strategii</x-panel.button>
      @endif
    </x-panel.empty-state>
  @else
    <x-panel.card class="p-0 sm:p-0">
      <ul class="divide-y divide-slate-100">
        @foreach ($list['rows'] as $row)
          @php($path = (string) (parse_url($row['url'], PHP_URL_PATH) ?: '/'))
          <li class="grid grid-cols-1 gap-2 px-4 py-3 text-sm md:grid-cols-12 md:items-center">
            <div class="min-w-0 md:col-span-5">
              <a href="{{ PagesController::pageUrl($projectId, $row['id']) }}" class="block truncate font-medium text-brand-600 hover:underline" title="{{ $row['url'] }}">{{ $row['snapshot']['title'] ?? $path }}</a>
              <p class="truncate text-xs text-slate-500" title="{{ $row['url'] }}">{{ $row['host'] }}{{ $path }}</p>
              <p class="mt-0.5 flex flex-wrap gap-x-2 text-xs text-slate-500">
                <span>{{ PageLabels::kind($row['kind']) }}</span>
                @if ($row['topic'] !== null)
                  <span>· temat <a href="{{ StrategyTopicsController::topicUrl($projectId, $row['topic']['id']) }}" class="text-brand-600 hover:underline">{{ $row['topic']['label'] ?? '—' }}</a></span>
                @endif
              </p>
            </div>
            <div class="flex flex-wrap items-center gap-2 md:col-span-4">
              <x-panel.page-cache :cache="$row['cache']" />
              @if ($row['snapshot'] !== null)
                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ PageLabels::qualityTone($row['snapshot']['content_quality']) }}">Treść: {{ mb_strtolower(PageLabels::quality($row['snapshot']['content_quality'])) }}</span>
                <span class="text-xs tabular-nums text-slate-500">{{ Format::number($row['snapshot']['word_count']) }} słów</span>
              @endif
              @if ($row['status'] === 'failed' || $row['status'] === 'blocked')
                <span class="text-xs text-red-700">{{ PageLabels::status($row['status']) }}</span>
              @endif
            </div>
            <div class="text-xs text-slate-500 md:col-span-3 md:text-right">
              @if ($row['snapshot'] !== null)
                Pobrana {{ Format::datetime($row['snapshot']['fetched_at']) }}
                <span class="block">kopii: {{ Format::number($row['snapshots']) }}</span>
              @elseif ($row['last_attempt_at'] !== null)
                Próba {{ Format::datetime($row['last_attempt_at']) }}
              @else
                Jeszcze nie pobrana
              @endif
            </div>
          </li>
        @endforeach
      </ul>
    </x-panel.card>

    @if ($list['pages'] > 1)
      <nav class="mt-4 flex items-center justify-between text-sm" aria-label="Strony listy">
        <span class="text-slate-500">Strona {{ $list['page'] }} z {{ $list['pages'] }} ({{ Format::number($list['total']) }})</span>
        <span class="flex gap-2">
          @if ($list['page'] > 1)
            <x-panel.button variant="secondary" :href="$query(['page' => $list['page'] - 1])">Poprzednia</x-panel.button>
          @endif
          @if ($list['page'] < $list['pages'])
            <x-panel.button variant="secondary" :href="$query(['page' => $list['page'] + 1])">Następna</x-panel.button>
          @endif
        </span>
      </nav>
    @endif
  @endif
@endsection
