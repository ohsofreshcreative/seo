@extends('panel.layouts.app', ['active' => 'strategy'])

@section('title', $candidate->keyword . ' · SERP Intelligence · ' . $project->name)

@php
  use App\Http\Controllers\Panel\StrategySerpController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Discovery\CandidateRow;

  $base = PanelUrl::project($project->publicId, 'strategy');
@endphp

@section('content')
  <p class="mb-4 text-sm"><a href="{{ $base }}/serp" class="text-brand-600 hover:underline">← SERP Intelligence</a></p>

  <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
    <div class="min-w-0">
      <h1 class="break-words text-2xl font-semibold tracking-tight text-slate-900">{{ $candidate->keyword }}</h1>
      <p class="mt-1 text-sm text-slate-500">
        Wolumen {{ Format::number($candidate->searchVolume) }} · trudność SEO {{ Format::number($candidate->keywordDifficulty) }}
        @if (CandidateRow::intentLabel($candidate->intent) !== null) · intencja (dostawca): {{ CandidateRow::intentLabel($candidate->intent) }} @endif
        · średnia pozycja (GSC) {{ Format::position($candidate->gscPosition) }}
      </p>
      <div class="mt-2">
        @include('panel.strategy.partials.topic-link', ['topic' => $topic, 'projectId' => $project->publicId])
        @if (! $candidate->active)
          <span class="ml-2 inline-flex rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">nieaktywny kandydat Strategii</span>
        @endif
      </div>
    </div>
    @if ($canAnalyze && $candidate->active)
      <x-panel.button variant="secondary" :href="StrategySerpController::analysisUrl($project->publicId, [$candidate->publicId])">Analiza SERP tej frazy (podgląd kosztu)</x-panel.button>
    @endif
  </div>

  <x-panel.card>
    <h2 class="text-base font-semibold text-slate-900">Najnowszy zgodny pomiar SERP</h2>
    @if ($detail === null)
      <p class="mt-2 text-sm text-slate-500">Brak zgodnego pomiaru tej frazy w kontekście projektu (rynek, urządzenie, głębokość ≥ TOP20). Pomiary pochodzą z modułu Pozycje i z analizy SERP Strategii.</p>
    @else
      <div class="mt-3">
        @include('panel.strategy.partials.serp-results', ['detail' => $detail])
      </div>
    @endif
  </x-panel.card>

  @include('panel.strategy.partials.disclaimer')
@endsection
