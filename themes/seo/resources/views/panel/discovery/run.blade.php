@extends('panel.layouts.app', ['active' => 'discovery'])

@section('title', 'Wyszukiwanie fraz · ' . $project->name)

@php
  use App\Http\Controllers\Panel\DiscoveryController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Discovery\DiscoveryRun;
  use OsfSeo\Market\ProviderErrorCategory;
  use OsfSeo\Opportunities\Text;

  $base = PanelUrl::project($project->publicId, 'discovery');
  $seedStatuses = ['pending' => 'czeka', 'running' => 'w trakcie', 'done' => 'pobrany', 'cached' => 'z cache (bez kosztu)', 'failed' => 'błąd', 'cancelled' => 'anulowany'];
  $sources = ['manual' => 'ręcznie', 'gsc' => 'GSC', 'opportunity' => 'szansa SEO'];
  $errorLabel = fn (?string $code): ?string => $code === null ? null : ($code === 'interrupted' ? 'przerwane' : (ProviderErrorCategory::tryFrom($code)?->label() ?? $code));
@endphp

@section('content')
  <p class="mb-4 text-sm"><a href="{{ $base }}" class="text-brand-600 hover:underline">← Nowe frazy</a></p>

  <x-panel.page-header title="Wyszukiwanie fraz"
    :description="Format::datetime($run->createdAt) . ' · ' . $run->method->label() . ($run->depth !== null ? ' (zasięg ' . $run->depth . ')' : '') . ' · ' . $run->seedsCount . ' ' . Text::plural($run->seedsCount, 'seed', 'seedy', 'seedów')">
    @if ($canManage && $run->isActive())
      <x-slot:actions>
        <form method="post" action="{{ DiscoveryController::runUrl($project->publicId, $run->publicId) }}/cancel"
          x-data @submit="if (! confirm('Anulować wyszukiwanie? Wysłane już żądania pozostają w rejestrze kosztów.')) $event.preventDefault()">
          <x-panel.nonce />
          <x-panel.button type="submit" variant="danger">Anuluj</x-panel.button>
        </form>
      </x-slot:actions>
    @endif
  </x-panel.page-header>

  @if ($run->isActive())
    <div class="mb-6">
      @include('panel.discovery.partials.progress', ['progress' => $progress])
    </div>
  @endif

  <div class="grid gap-6 lg:grid-cols-2">
    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Podsumowanie</h2>
      <dl class="mt-2 divide-y divide-slate-100 text-sm">
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Stan</dt><dd @class(['font-medium', 'text-amber-700' => in_array($run->status, [DiscoveryRun::PARTIAL, DiscoveryRun::FAILED], true), 'text-slate-900' => ! in_array($run->status, [DiscoveryRun::PARTIAL, DiscoveryRun::FAILED], true)])>{{ $run->statusLabel() }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Nowe frazy</dt><dd class="font-medium tabular-nums">{{ Format::number($run->candidatesNew) }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Znalezione ponownie</dt><dd class="tabular-nums">{{ Format::number($run->candidatesSeen) }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Frazy od dostawcy</dt><dd class="tabular-nums">{{ Format::number($run->itemsReceived) }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Limit fraz</dt><dd class="tabular-nums">{{ Format::number($run->maxCandidates) }} ({{ Format::number($run->seedLimit) }} na seed)</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Filtry</dt><dd class="text-right">min. wolumen {{ Format::number($run->minVolume) }}, maks. trudność SEO {{ $run->maxDifficulty ?? '—' }}{{ $run->skipsOtherLanguage() ? ', bez innych języków' : '' }}{{ $run->forced ? ', pobrane ponownie' : '' }}</dd></div>
        @if ($run->rejected !== [])
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Odrzucone</dt>
            <dd class="text-right">
              @foreach ($run->rejected as $reason => $count)
                <span class="block">{{ DiscoveryRun::rejectedLabel($reason) }}: {{ Format::number($count) }}</span>
              @endforeach
            </dd>
          </div>
        @endif
        @if ($canManage)
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Żądania do API</dt><dd class="tabular-nums">{{ $run->tasksDone }} z {{ $run->tasksPlanned }}</dd></div>
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Koszt</dt><dd class="tabular-nums">{{ Format::usd($run->cost, 4) }} <span class="text-slate-500">(maks. {{ Format::usd($run->estimatedCost, 4) }})</span></dd></div>
        @endif
        @if ($run->errorCode)
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Ostatni błąd</dt><dd class="text-right text-amber-700">{{ $errorLabel($run->errorCode) }}</dd></div>
        @endif
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Zakończono</dt><dd>{{ Format::datetime($run->finishedAt) }}</dd></div>
      </dl>
    </x-panel.card>

    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Seedy</h2>
      <div class="mt-2 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
              <th class="py-2 pr-4 font-medium">Seed</th>
              <th class="py-2 pr-4 font-medium">Stan</th>
              <th class="py-2 pr-4 text-right font-medium">Frazy</th>
              <th class="py-2 pr-4 text-right font-medium">Nowe</th>
              @if ($canManage)<th class="py-2 text-right font-medium">Koszt</th>@endif
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 tabular-nums">
            @foreach ($seeds as $seed)
              <tr>
                <td class="py-2 pr-4 text-slate-900">{{ $seed['seed'] }} <span class="block text-xs text-slate-500">{{ $sources[$seed['source']] ?? $seed['source'] }}</span></td>
                <td @class(['py-2 pr-4', 'text-amber-700' => $seed['status'] === 'failed', 'text-slate-600' => $seed['status'] !== 'failed'])>
                  {{ $seedStatuses[$seed['status']] ?? $seed['status'] }}
                  @if ($seed['error_code'])<span class="block text-xs">{{ $errorLabel($seed['error_code']) }}</span>@endif
                </td>
                <td class="py-2 pr-4 text-right">{{ Format::number((int) $seed['items']) }}</td>
                <td class="py-2 pr-4 text-right">{{ Format::number((int) $seed['candidates_new']) }}</td>
                @if ($canManage)<td class="py-2 text-right">{{ Format::usd((float) $seed['cost'], 4) }}</td>@endif
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </x-panel.card>
  </div>

  <p class="mt-6 text-sm"><a href="{{ $base }}?status=all&amp;visibility=all&amp;sort=discovered" class="text-brand-600 hover:underline">Zobacz frazy (najnowsze najpierw)</a></p>
@endsection
