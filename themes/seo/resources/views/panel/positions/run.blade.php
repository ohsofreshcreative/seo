@extends('panel.layouts.app', ['active' => 'positions'])

@section('title', 'Pomiar pozycji · ' . $project->name)

@php
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Serp\SerpRun;

  $base = PanelUrl::project($project->publicId, 'positions');
  $triggerLabel = match ($run->trigger) {
    'schedule' => 'harmonogram',
    'analysis' => 'analiza SERP Strategii',
    default => 'uruchomiony ręcznie',
  };
@endphp

@section('content')
  <p class="mb-2 text-sm"><a href="{{ $base }}" class="text-brand-600 hover:underline">← Pozycje</a></p>
  <x-panel.page-header title="Pomiar pozycji"
    :description="Format::datetime($run->createdAt) . ' · ' . $triggerLabel" />

  @include('panel.positions.partials.progress', ['progress' => $progress])

  <x-panel.card class="mt-6">
    <dl class="grid grid-cols-2 gap-4 text-sm md:grid-cols-4">
      <div><dt class="text-slate-500">Stan</dt><dd class="mt-1 font-medium text-slate-900">{{ $run->statusLabel() }}@if ($run->status === SerpRun::SKIPPED) — {{ SerpRun::skipLabel($run->skipReason) }}@endif</dd></div>
      <div><dt class="text-slate-500">Frazy w pomiarze</dt><dd class="mt-1 font-medium tabular-nums text-slate-900">{{ Format::number($run->keywordsPlanned) }}@if ($run->keywordsSkipped > 0) <span class="font-normal text-slate-500">(pominięte jako niedawno zlecone: {{ Format::number($run->keywordsSkipped) }})</span>@endif</dd></div>
      <div><dt class="text-slate-500">Zakończone / błędy</dt><dd class="mt-1 font-medium tabular-nums text-slate-900">{{ Format::number($run->tasksCompleted) }} / {{ Format::number($run->tasksFailed) }}</dd></div>
      <div><dt class="text-slate-500">Zakończono</dt><dd class="mt-1 font-medium text-slate-900">{{ Format::datetime($run->finishedAt) }}</dd></div>
      @if ($canManage)
        <div><dt class="text-slate-500">Szacowany maksymalny koszt</dt><dd class="mt-1 font-medium tabular-nums text-slate-900">{{ Format::usd($run->estimatedCost, 4) }}</dd></div>
        <div><dt class="text-slate-500">Koszt (zgłoszony / szacowany)</dt><dd class="mt-1 font-medium tabular-nums text-slate-900">{{ Format::usd($run->cost, 4) }}</dd></div>
      @endif
      @if ($run->errorCode !== null)
        <div class="col-span-2"><dt class="text-slate-500">Ostatni błąd</dt><dd class="mt-1 text-amber-700">{{ \OsfSeo\Market\ProviderErrorCategory::tryFrom($run->errorCode)?->label() ?? $run->errorCode }}</dd></div>
      @endif
    </dl>

    @if ($canManage && $run->isActive())
      <form method="post" action="{{ \App\Http\Controllers\Panel\PositionsController::runUrl($project->publicId, $run->publicId) }}/cancel" class="mt-4"
        @submit="if (! confirm('Anulować niewysłane zadania? Zadania już zlecone zostaną odebrane i pozostaną w kosztach.')) { $event.preventDefault(); }">
        <x-panel.nonce />
        <x-panel.button type="submit" variant="danger">Anuluj niewysłane zadania</x-panel.button>
      </form>
    @endif
  </x-panel.card>
@endsection
