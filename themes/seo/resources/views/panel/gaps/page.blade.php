@extends('panel.layouts.app', ['active' => 'gaps'])

@section('title', 'Strona konkurenta · ' . $project->name)

@php
  use App\Http\Controllers\Panel\GapKeywordsController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Discovery\CandidateRow;

  $safe = preg_match('#^https?://#i', (string) $page['url']) === 1;
@endphp

@section('content')
  <p class="mb-4 text-sm"><a href="{{ PanelUrl::project($project->publicId, 'gaps/pages') }}?competitor={{ rawurlencode($competitor->publicId) }}" class="text-brand-600 hover:underline">← Strony konkurencji</a></p>

  <div class="mb-8">
    <h1 class="break-words text-2xl font-semibold tracking-tight text-slate-900">{{ $page['title'] ?? $page['url'] }}</h1>
    <p class="mt-1 break-all text-sm text-slate-500">{{ $competitor->name }} ·
      @if ($safe)
        <a href="{{ $page['url'] }}" rel="noopener noreferrer" target="_blank" class="text-brand-600 hover:underline">{{ $page['url'] }}</a>
      @else
        {{ $page['url'] }}
      @endif
    </p>
  </div>

  <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
    <x-panel.stat label="Frazy (Labs)" :value="Format::number((int) $page['keywords'])" />
    <x-panel.stat label="W TOP10" :value="Format::number((int) $page['top10'])" />
    <x-panel.stat label="Wolumen" :value="Format::number((int) $page['total_volume'])" />
    <x-panel.stat label="Luki projektu" :value="Format::number((int) $page['gap_keywords'])" hint="brak, słaba albo nieznana widoczność projektu" />
    <x-panel.stat label="Wspólne" :value="Format::number((int) $page['overlap_keywords'])" hint="projekt porównywalnie albo wyżej" />
  </div>

  <x-panel.card class="mt-6">
    <h2 class="text-base font-semibold text-slate-900">Frazy strony</h2>
    <p class="mt-1 text-xs text-slate-500">Pozycje strony konkurenta w bazie DataForSEO Labs i widoczność projektu dla tej samej frazy.</p>
    <div class="mt-3 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
            <th class="py-2 pr-4 font-medium">Fraza</th>
            <th class="py-2 pr-4 text-right font-medium">Pozycja (Labs)</th>
            <th class="py-2 pr-4 text-right font-medium">Wolumen</th>
            <th class="py-2 pr-4 text-right font-medium">Trudność SEO</th>
            <th class="py-2 pr-4 font-medium">Luka</th>
            <th class="py-2 font-medium">Projekt</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          @foreach ($keywords as $row)
            <tr>
              <td class="max-w-xs py-2 pr-4">
                @if ($row['public_id'] !== null)
                  <a href="{{ GapKeywordsController::keywordUrl($project->publicId, $row['public_id']) }}" class="break-words font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $row['keyword'] }}</a>
                @else
                  <span class="break-words text-slate-900">{{ $row['keyword'] }}</span>
                @endif
                @if (CandidateRow::intentLabel($row['search_intent']) !== null)
                  <span class="ml-1 inline-flex rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">{{ CandidateRow::intentLabel($row['search_intent']) }}</span>
                @endif
              </td>
              <td class="py-2 pr-4 text-right tabular-nums">#{{ $row['rank_group'] }}</td>
              <td class="py-2 pr-4 text-right tabular-nums">{{ Format::number($row['search_volume'] === null ? null : (int) $row['search_volume']) }}</td>
              <td class="py-2 pr-4 text-right tabular-nums">{{ Format::number($row['keyword_difficulty'] === null ? null : (int) $row['keyword_difficulty']) }}</td>
              <td class="py-2 pr-4">
                @if ($row['gap_type'] === null)
                  <span class="text-xs text-slate-400" title="Poza progiem znaczącej pozycji konkurenta w ustawieniach Luk SEO">—</span>
                @elseif ($row['listed'] !== '1')
                  <span class="text-xs text-amber-700">odfiltrowana</span>
                @else
                  <x-panel.gap-type :value="$row['gap_type']" />
                @endif
              </td>
              <td class="py-2">@if ($row['visibility'] !== null)<x-panel.project-visibility :value="$row['visibility']" :source="$row['visibility_source']" :position="$row['project_position']" />@else<span class="text-slate-400">—</span>@endif</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </x-panel.card>

  @include('panel.gaps.partials.disclaimer')
@endsection
