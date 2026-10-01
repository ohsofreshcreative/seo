@extends('panel.layouts.app', ['active' => 'competitors'])

@section('title', 'Konkurenci organiczni · ' . $project->name)

@php
  use App\Http\Controllers\Panel\CompetitorsController;
  use App\Panel\Format;
  use OsfSeo\Opportunities\Text;

  $base = CompetitorsController::url($project->publicId);
  $organic = $base . '/organic';
  $pages = max(1, (int) ceil($data['total'] / 50));
  $sortUrl = fn (string $value) => $organic . '?' . http_build_query(['sort' => $value]);
  $columns = [
    ['keywords', 'Frazy', 'liczba monitorowanych fraz, w których domena jest w ostatnim pomiarze'],
    ['top3', 'TOP 3', null],
    ['top10', 'TOP 10', null],
    [null, 'TOP 20', null],
    ['avg_rank', 'Średnia pozycja SERP', 'średnia najlepszej pozycji, tylko frazy z domeną'],
    ['overlap', 'Wspólne z projektem', 'frazy, w których jest i ta domena, i projekt'],
    [null, 'Adresy', 'różne adresy domeny w wynikach'],
  ];
@endphp

@section('content')
  <p class="mb-2 text-sm"><a href="{{ $base }}" class="text-brand-600 hover:underline">← Konkurenci</a></p>
  <x-panel.page-header title="Konkurenci organiczni"
    :description="$project->name . ' · domeny najczęściej obecne w ostatnich wynikach Google ' . Format::number($data['keywords']) . ' ' . Text::plural($data['keywords'], 'monitorowanej frazy', 'monitorowanych fraz', 'monitorowanych fraz') . ' (bez domen projektu) — fakty, bez ocen'" />

  @if ($data['rows'] === [])
    <x-panel.empty-state title="Brak danych" description="Lista powstaje z zapisanych pomiarów monitorowanych fraz — pojawi się po pierwszym pomiarze." />
  @else
    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
          <tr>
            <th class="px-3 py-2.5">Domena</th>
            @foreach ($columns as [$value, $label, $title])
              <th class="px-3 py-2.5 text-right" @if ($title) title="{{ $title }}" @endif>
                @if ($value !== null)
                  <a href="{{ $sortUrl($value) }}" class="hover:text-slate-900">{{ $label }}{{ $sort === $value ? ($value === 'avg_rank' ? ' ▲' : ' ▼') : '' }}</a>
                @else
                  {{ $label }}
                @endif
              </th>
            @endforeach
            @if ($canManage)
              <th class="px-3 py-2.5"><span class="sr-only">Akcja</span></th>
            @endif
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 tabular-nums">
          @foreach ($data['rows'] as $row)
            <tr class="hover:bg-slate-50">
              <td class="px-3 py-2 font-medium text-slate-900">
                {{ $row['host'] }}
                @if ($row['competitor'] !== null)
                  <a href="{{ CompetitorsController::url($project->publicId, $row['competitor']->publicId) }}" class="ml-1 inline-flex rounded bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-900 hover:underline">{{ $row['competitor']->name }}</a>
                @endif
              </td>
              <td class="px-3 py-2 text-right">{{ Format::number($row['keywords']) }}</td>
              <td class="px-3 py-2 text-right">{{ Format::number($row['top3']) }}</td>
              <td class="px-3 py-2 text-right">{{ Format::number($row['top10']) }}</td>
              <td class="px-3 py-2 text-right">{{ Format::number($row['top20']) }}</td>
              <td class="px-3 py-2 text-right">{{ Format::position($row['avg_rank']) }}</td>
              <td class="px-3 py-2 text-right">{{ Format::number($row['overlap']) }}</td>
              <td class="px-3 py-2 text-right">{{ Format::number($row['urls']) }}</td>
              @if ($canManage)
                <td class="px-3 py-2 text-right">
                  @if ($row['competitor'] === null)
                    <form method="post" action="{{ $base }}">
                      <x-panel.nonce />
                      <input type="hidden" name="domain" value="{{ $row['host'] }}">
                      <input type="hidden" name="from" value="organic">
                      <button type="submit" class="whitespace-nowrap text-xs font-medium text-brand-600 hover:underline">Dodaj jako konkurenta</button>
                    </form>
                  @endif
                </td>
              @endif
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    <nav class="mt-4 flex items-center justify-between text-sm" aria-label="Strony">
      <span class="text-slate-600">{{ Format::number($data['total']) }} {{ Text::plural($data['total'], 'domena', 'domeny', 'domen') }}@if ($pages > 1) · strona {{ $page }} z {{ $pages }}@endif</span>
      @if ($pages > 1)
        <div class="flex gap-2">
          @if ($page > 1)
            <x-panel.button variant="secondary" :href="$organic . '?' . http_build_query(['sort' => $sort, 'page' => $page - 1])">Poprzednia</x-panel.button>
          @endif
          @if ($page < $pages)
            <x-panel.button variant="secondary" :href="$organic . '?' . http_build_query(['sort' => $sort, 'page' => $page + 1])">Następna</x-panel.button>
          @endif
        </div>
      @endif
    </nav>
  @endif

  <div class="mt-6 space-y-1 text-xs text-slate-500">
    <p>Zestawienie z ostatniego pomiaru każdej monitorowanej frazy: dla domeny liczy się najlepsza pozycja w danej frazie (subdomeny są osobnymi hostami). To fakty z wyników Google, nie ocena siły konkurenta.</p>
  </div>
@endsection
