@extends('panel.layouts.app', ['active' => 'pages'])

@section('title', 'Zlecenie pobrania · ' . $project->name)

@php
  use App\Http\Controllers\Panel\PagesController;
  use App\Http\Controllers\Panel\StrategyTopicsController;
  use App\Panel\Format;
  use App\Panel\PageLabels;
  use App\Panel\PanelUrl;

  $projectId = $project->publicId;
  $statusUrl = PagesController::jobUrl($projectId, $job['id']) . '/status';
@endphp

@section('content')
  <p class="mb-4 flex flex-wrap gap-x-4 text-sm">
    @if ($job['topic'] !== null)
      <a href="{{ StrategyTopicsController::topicUrl($projectId, $job['topic']) }}#analiza-ai" class="text-brand-600 hover:underline">← Temat</a>
    @endif
    <a href="{{ PanelUrl::project($projectId, 'pages') }}" class="text-brand-600 hover:underline">Strony</a>
  </p>

  <x-panel.page-header title="Zlecenie pobrania stron" :description="'Zlecone ' . Format::datetime($job['created_at']) . '. Pobiera przetwarzanie w tle — możesz opuścić tę stronę.'" />

  <div x-data="runProgress(@js($statusUrl), @js(['active' => $job['active'], 'status_label' => PageLabels::jobStatus($job['status']), 'items_done' => $job['items_done'], 'items_total' => $job['items_total'], 'stuck' => false]))"
    @class(['mb-6 rounded-lg border px-4 py-3 text-sm', 'border-sky-200 bg-sky-50 text-sky-900' => $job['active'], 'border-slate-200 bg-white text-slate-800' => ! $job['active']]) role="status">
    <p class="font-semibold">Status: <span x-text="status_label">{{ PageLabels::jobStatus($job['status']) }}</span>
      · <span class="tabular-nums" x-text="items_done + ' z ' + items_total">{{ $job['items_done'] }} z {{ $job['items_total'] }}</span> przetworzonych</p>
    @if ($job['active'])
      <p class="mt-1 text-xs">Strony tej samej witryny pobieramy po kolei, z odstępami — przy odstępie zlecenie czeka na kolejny krok przetwarzania w tle.</p>
    @endif
    <p x-show="stuck" x-cloak class="mt-2 rounded bg-amber-100 px-2 py-1 text-xs text-amber-900">Zlecenie czeka dłużej niż 15 minut, a przetwarzanie w tle nie działa — sprawdź cron serwera (wp osf-seo sync:run). Możesz anulować zlecenie; po 24 h czekania wygasa.</p>
    @if ($job['status'] === 'queued')
      <form method="post" action="{{ PagesController::jobUrl($projectId, $job['id']) }}/cancel" class="mt-2 print:hidden">
        <x-panel.nonce />
        <button type="submit" class="text-xs font-medium text-sky-800 underline">Anuluj zlecenie</button>
      </form>
    @endif
    @if ($job['error'] !== null)
      <p class="mt-1 text-xs text-red-700">{{ PageLabels::error($job['error']) }}</p>
    @endif
  </div>

  <x-panel.card class="p-0 sm:p-0">
    <ul class="divide-y divide-slate-100">
      @foreach ($job['items'] as $item)
        <li class="px-4 py-3 text-sm">
          <p class="break-all font-medium text-slate-900">{{ $item['url'] ?? '—' }}</p>
          <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-600">
            @if (($item['kind'] ?? null) !== null)<span class="rounded bg-slate-100 px-1.5 py-0.5">{{ PageLabels::kind($item['kind']) }}</span>@endif
            @if (($item['state'] ?? null) === 'pending')
              <span class="text-sky-800">{{ (int) ($item['attempts'] ?? 0) > 0 ? 'Czeka na kolejną próbę' : 'Oczekuje' }}</span>
              @if (($item['error'] ?? null) !== null)<span>({{ PageLabels::error($item['error']) }})</span>@endif
            @else
              <span @class(['font-medium', 'text-emerald-700' => in_array($item['outcome'] ?? null, \OsfSeo\PageIntelligence\PageJob::OK_OUTCOMES, true), 'text-red-700' => ! in_array($item['outcome'] ?? null, \OsfSeo\PageIntelligence\PageJob::OK_OUTCOMES, true)])>{{ PageLabels::outcome($item['outcome'] ?? null) }}</span>
              @if (($item['error'] ?? null) !== null)<span>{{ PageLabels::error($item['error']) }}</span>@endif
              @if (($item['http_status'] ?? null) !== null)<span>HTTP {{ $item['http_status'] }}</span>@endif
            @endif
          </div>
          @if (($item['page'] ?? null) !== null)
            <a href="{{ PagesController::pageUrl($projectId, $item['page']) }}" class="mt-1 inline-block text-xs text-brand-600 hover:underline">Zapisane kopie strony</a>
          @endif
        </li>
      @endforeach
    </ul>
  </x-panel.card>
  <p class="mt-3 text-xs text-slate-500">Nieudane pobranie nie usuwa ostatniej poprawnej kopii strony i nie dowodzi, że strona nie istnieje. Odmowy (robots.txt, prośba witryny o przerwę, limit dzienny) nie są ponawiane automatycznie.</p>
@endsection
