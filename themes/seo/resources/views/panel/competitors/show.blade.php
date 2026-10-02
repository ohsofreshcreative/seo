@extends('panel.layouts.app', ['active' => 'competitors'])

@section('title', $competitor->name . ' · Konkurenci · ' . $project->name)

@php
  use App\Http\Controllers\Panel\CompetitorsController;
  use App\Http\Controllers\Panel\PositionsController;
  use App\Panel\Format;
  use OsfSeo\Serp\Competitor;

  $base = CompetitorsController::url($project->publicId);
  $self = CompetitorsController::url($project->publicId, $competitor->publicId);
  $host = fn (?string $url) => $url === null ? '' : preg_replace('#^https?://(www\.)?#i', '', $url);
@endphp

@section('content')
  <p class="mb-2 text-sm"><a href="{{ $base }}" class="text-brand-600 hover:underline">← Konkurenci</a></p>
  <x-panel.page-header :title="$competitor->name" :description="$competitor->domain . ' (z subdomenami) · ' . ['active' => 'aktywny', 'inactive' => 'monitorowanie wstrzymane', 'archived' => 'w archiwum'][$competitor->status]" />

  <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
    <x-panel.stat label="Frazy w wynikach" :value="Format::number($stats['found']) . ' / ' . Format::number($stats['checked'])" hint="ostatnie pomiary monitorowanych fraz" />
    <x-panel.stat label="TOP 3" :value="Format::number($stats['top3'])" />
    <x-panel.stat label="TOP 10" :value="Format::number($stats['top10'])" />
    <x-panel.stat label="TOP 20" :value="Format::number($stats['top20'])" />
    <x-panel.stat label="Średnia pozycja SERP" :value="Format::position($stats['avg_rank'])" hint="tylko frazy z domeną" />
  </div>

  <div class="mt-6 overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
      <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
        <tr>
          <th class="px-3 py-2.5">Fraza</th>
          <th class="px-3 py-2.5 text-right">Wolumen</th>
          <th class="px-3 py-2.5 text-right">Konkurent</th>
          <th class="px-3 py-2.5 text-right">Zmiana</th>
          <th class="px-3 py-2.5">URL konkurenta</th>
          <th class="px-3 py-2.5 text-right">Projekt</th>
          <th class="px-3 py-2.5">Pomiar</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        @forelse ($rows as $row)
          <tr class="hover:bg-slate-50">
            <td class="max-w-xs px-3 py-2"><a href="{{ PositionsController::keywordUrl($project->publicId, $row['tracked_id']) }}" class="break-words font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $row['keyword'] }}</a></td>
            <td class="px-3 py-2 text-right tabular-nums">{{ Format::number($row['search_volume']) }}</td>
            <td class="px-3 py-2 text-right"><x-panel.serp-rank :rank="$row['rank']" :found="true" :depth="$row['depth']" /></td>
            <td class="px-3 py-2 text-right"><x-panel.rank-change :type="$row['change']->type" :value="$row['change']->value" :top10="$row['change']->top10" :depth="$row['depth']" class="items-end" /></td>
            <td class="max-w-xs px-3 py-2 text-xs">
              @if ($row['url'] !== null && preg_match('#^https?://#i', $row['url']))
                <a href="{{ $row['url'] }}" target="_blank" rel="noopener noreferrer nofollow" class="break-all text-brand-600 hover:underline">{{ $host($row['url']) }}</a>
                @foreach ($row['other_urls'] as $other)
                  <span class="block break-all text-slate-500">#{{ $other['rank'] }} {{ $host($other['url']) }}</span>
                @endforeach
              @else
                <span class="text-slate-400">—</span>
              @endif
            </td>
            <td class="px-3 py-2 text-right"><x-panel.serp-rank :rank="$row['project_rank']" :found="$row['project_found']" :depth="$row['depth']" /></td>
            <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-600">{{ Format::datetime($row['checked_at']) }}</td>
          </tr>
        @empty
          <tr><td colspan="7" class="px-3 py-6 text-center text-sm text-slate-500">Brak pomiarów monitorowanych fraz — pozycje pojawią się po pierwszym pomiarze.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if ($urls !== [])
    <x-panel.card class="mt-6">
      <h2 class="text-base font-semibold text-slate-900">Adresy konkurenta w wynikach</h2>
      <ul class="mt-3 divide-y divide-slate-100 text-sm">
        @foreach (array_slice($urls, 0, 30, true) as $url => $count)
          <li class="flex justify-between gap-4 py-1.5">
            <span class="break-all text-slate-700">{{ $host((string) $url) }}</span>
            <span class="whitespace-nowrap tabular-nums text-slate-500">{{ $count }} {{ \OsfSeo\Opportunities\Text::plural($count, 'fraza', 'frazy', 'fraz') }}</span>
          </li>
        @endforeach
      </ul>
    </x-panel.card>
  @endif

  @if ($canManage)
    <x-panel.card class="mt-6 max-w-3xl">
      <h2 class="text-base font-semibold text-slate-900">Edytuj</h2>
      <form method="post" action="{{ $self }}" class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-start">
        <x-panel.nonce />
        <x-panel.field name="name" label="Nazwa" :value="$competitor->name" maxlength="190" required />
        <x-panel.field name="domain" label="Domena" :value="$competitor->domain" required help="Zmiana domeny przelicza pozycje z zapisanych pomiarów." />
        <div class="sm:pt-6"><x-panel.button type="submit">Zapisz</x-panel.button></div>
      </form>
      <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
        @foreach (array_filter([
          Competitor::ACTIVE => $competitor->status !== Competitor::ACTIVE ? 'Przywróć (aktywny)' : null,
          Competitor::INACTIVE => $competitor->status === Competitor::ACTIVE ? 'Wstrzymaj monitorowanie' : null,
          Competitor::ARCHIVED => $competitor->status !== Competitor::ARCHIVED ? 'Przenieś do archiwum' : null,
        ]) as $status => $label)
          <form method="post" action="{{ $self }}">
            <x-panel.nonce />
            <input type="hidden" name="status" value="{{ $status }}">
            <x-panel.button type="submit" :variant="$status === Competitor::ARCHIVED ? 'danger' : 'secondary'">{{ $label }}</x-panel.button>
          </form>
        @endforeach
      </div>
      <p class="mt-2 text-xs text-slate-500">Wstrzymany konkurent nie jest pokazywany w pozycjach; dane i historia zostają (archiwum też niczego nie usuwa).</p>
    </x-panel.card>
  @endif
@endsection
