@extends('panel.layouts.app', ['active' => 'gaps'])

@section('title', 'Import fraz konkurentów · ' . $project->name)

@php
  use App\Http\Controllers\Panel\GapsController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Gap\GapRun;
  use OsfSeo\Market\ProviderErrorCategory;

  $base = PanelUrl::project($project->publicId, 'gaps');
  $targetStatuses = ['pending' => 'czeka', 'running' => 'w toku', 'cached' => 'z pamięci (bez kosztu)', 'done' => 'pobrany', 'partial' => 'niepełny', 'failed' => 'błąd', 'cancelled' => 'anulowany'];
  $errorLabel = fn (?string $code): ?string => $code === null ? null : (in_array($code, ['interrupted', 'expired', 'cancelled', 'missing_dataset', ...GapRun::UNRELIABLE_REASONS], true) ? GapRun::reasonLabel($code) : (ProviderErrorCategory::tryFrom($code)?->label() ?? $code));
  $triggers = ['manual' => 'ręcznie', 'cli' => 'WP-CLI', 'schedule' => 'harmonogram'];
@endphp

@section('content')
  <p class="mb-4 text-sm"><a href="{{ $base }}" class="text-brand-600 hover:underline">← Luki SEO</a></p>

  <x-panel.page-header title="Import fraz konkurentów"
    :description="Format::datetime($run->createdAt) . ' · TOP' . $run->coverage->maxRank . ', wolumen ≥ ' . Format::number($run->coverage->minVolume) . ', maks. ' . Format::number($run->coverage->maxRows) . ' fraz na domenę · ' . ($triggers[$run->trigger] ?? $run->trigger) . ($run->forced ? ' · pobrane ponownie' : '')">
    @if ($canManage && $run->isActive())
      <x-slot:actions>
        <form method="post" action="{{ GapsController::runUrl($project->publicId, $run->publicId) }}/cancel"
          x-data @submit="if (! confirm('Anulować import? Pobrane strony wyników zostają, wysłane żądania pozostają w rejestrze kosztów.')) $event.preventDefault()">
          <x-panel.nonce />
          <x-panel.button type="submit" variant="danger">Anuluj</x-panel.button>
        </form>
      </x-slot:actions>
    @endif
  </x-panel.page-header>

  @if ($run->isActive())
    <div class="mb-6">
      @include('panel.gaps.partials.progress', ['progress' => $progress])
    </div>
  @endif

  <div class="grid gap-6 lg:grid-cols-3">
    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Podsumowanie</h2>
      <dl class="mt-2 divide-y divide-slate-100 text-sm">
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Stan</dt><dd @class(['text-right font-medium', 'text-amber-700' => in_array($run->status, [GapRun::PARTIAL, GapRun::FAILED, GapRun::PAUSED], true), 'text-slate-900' => ! in_array($run->status, [GapRun::PARTIAL, GapRun::FAILED, GapRun::PAUSED], true)])>{{ $run->statusLabel() }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Domeny</dt><dd class="tabular-nums">{{ $run->targetsDone }} z {{ $run->targetsPlanned }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Strony wyników</dt><dd class="tabular-nums">{{ $run->requestsDone }} (maks. {{ $run->requestsPlanned }})</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Pobrane frazy</dt><dd class="tabular-nums">{{ Format::number($run->rowsReceived) }}</dd></div>
        @if ($canManage)
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Koszt</dt><dd class="tabular-nums">{{ Format::usd($run->cost, 4) }} <span class="text-xs text-slate-500">(maks. {{ Format::usd($run->estimatedCost, 4) }})</span></dd></div>
        @endif
        @if ($run->errorCode !== null)
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Problem</dt><dd class="text-right text-amber-700">{{ $errorLabel($run->errorCode) }}</dd></div>
        @endif
        @if ($run->finishedAt !== null)
          <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Zakończony</dt><dd>{{ Format::datetime($run->finishedAt) }}</dd></div>
        @endif
      </dl>
    </x-panel.card>

    <x-panel.card class="lg:col-span-2">
      <h2 class="text-base font-semibold text-slate-900">Domeny</h2>
      <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
              <th class="py-2 pr-4 font-medium">Domena</th>
              <th class="py-2 pr-4 font-medium">Stan</th>
              <th class="py-2 pr-4 text-right font-medium">Strony</th>
              <th class="py-2 pr-4 text-right font-medium">Frazy</th>
              <th class="py-2 pr-4 text-right font-medium" title="Nowe / utracone względem poprzedniego importu zbioru">Nowe / utracone</th>
              @if ($canManage)
                <th class="py-2 text-right font-medium">Koszt</th>
              @endif
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 tabular-nums">
            @foreach ($targets as $target)
              <tr>
                <td class="py-2 pr-4"><span class="text-slate-900">{{ $target['competitor'] ?? $project->name }}</span> <span class="block text-xs text-slate-500">{{ $target['domain'] }}{{ $target['role'] === 'project' ? ' · projekt' : '' }}</span></td>
                <td class="py-2 pr-4 text-slate-600">{{ $targetStatuses[$target['status']] ?? $target['status'] }}@if ($target['error_code'] !== null)<span class="block text-xs text-amber-700">{{ $errorLabel($target['error_code']) }}</span>@endif
                  @if ($target['unreliable'] !== null && $target['unreliable'] !== $target['error_code'])<span class="block text-xs text-amber-700">{{ GapRun::reasonLabel($target['unreliable']) }}</span>@endif
                </td>
                <td class="py-2 pr-4 text-right">{{ $target['pages_done'] }}</td>
                <td class="py-2 pr-4 text-right">{{ Format::number($target['rows_unique']) }}@if ($target['total_count'] !== null && $target['total_count'] > $target['rows_unique']) <span class="block text-xs text-slate-500">z {{ Format::number($target['total_count']) }} u dostawcy</span>@endif</td>
                <td class="py-2 pr-4 text-right">{{ Format::number($target['rows_new']) }} / {{ Format::number($target['rows_lost']) }}</td>
                @if ($canManage)
                  <td class="py-2 text-right">{{ Format::usd($target['cost'], 4) }}</td>
                @endif
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <p class="mt-3 text-xs text-slate-500">Domena „z pamięci” użyła świeżego zbioru (także pobranego przez inny projekt) — bez kosztu. „Niepełny” = część stron pobrana (limit kosztów, błąd albo anulowanie); brak frazy poza pobranym zakresem nie jest traktowany jako utrata.</p>
    </x-panel.card>
  </div>
@endsection
