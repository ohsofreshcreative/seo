@extends('panel.layouts.app', ['active' => 'competitors'])

@section('title', 'Konkurenci · ' . $project->name)

@php
  use App\Http\Controllers\Panel\CompetitorsController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Serp\Competitor;

  $base = CompetitorsController::url($project->publicId);
  $statusLabels = [Competitor::ACTIVE => 'aktywny', Competitor::INACTIVE => 'wstrzymany', Competitor::ARCHIVED => 'w archiwum'];
@endphp

@section('content')
  <x-panel.page-header title="Konkurenci"
    :description="$project->name . ' · pozycje konkurentów odczytane z pełnych wyników Google zapisanych przy pomiarach monitorowanych fraz — bez dodatkowych kosztów'">
    <x-slot:actions>
      <x-panel.button variant="secondary" :href="$base . '/organic'">Konkurenci organiczni</x-panel.button>
    </x-slot:actions>
  </x-panel.page-header>

  @if ($canManage)
    <x-panel.card class="mb-6 max-w-3xl">
      <h2 class="text-base font-semibold text-slate-900">Dodaj konkurenta</h2>
      <form method="post" action="{{ $base }}" class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-start">
        <x-panel.nonce />
        <x-panel.field name="domain" label="Domena" :value="$old['domain'] ?? ''" :error="$errors['domain'] ?? null" placeholder="konkurent.pl" required help="Subdomeny (np. sklep.konkurent.pl) liczą się do domeny." />
        <x-panel.field name="name" label="Nazwa (opcjonalnie)" :value="$old['name'] ?? ''" :error="$errors['name'] ?? null" maxlength="190" />
        <div class="sm:pt-6"><x-panel.button type="submit">Dodaj</x-panel.button></div>
      </form>
    </x-panel.card>
  @endif

  @if ($items === [])
    <x-panel.empty-state title="Brak konkurentów"
      description="Dodaj domeny konkurentów albo wybierz je z listy konkurentów organicznych (domeny najczęściej obecne w wynikach Twoich fraz). Historia pozycji zostanie odtworzona z zapisanych pomiarów.">
      <x-panel.button variant="secondary" :href="$base . '/organic'">Konkurenci organiczni</x-panel.button>
    </x-panel.empty-state>
  @else
    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
          <tr>
            <th class="px-3 py-2.5">Konkurent</th>
            <th class="px-3 py-2.5">Status</th>
            <th class="px-3 py-2.5 text-right" title="Liczba monitorowanych fraz, w których domena jest w ostatnim pomiarze">Frazy w wynikach</th>
            <th class="px-3 py-2.5 text-right">TOP 3</th>
            <th class="px-3 py-2.5 text-right">TOP 10</th>
            <th class="px-3 py-2.5 text-right">TOP 20</th>
            <th class="px-3 py-2.5 text-right" title="Średnia najlepszej Pozycji SERP, tylko z fraz, w których domena występuje">Średnia pozycja SERP</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 tabular-nums">
          @foreach ($items as $item)
            @php([$competitor, $stats] = [$item['competitor'], $item['stats']])
            <tr class="hover:bg-slate-50">
              <td class="px-3 py-2">
                <a href="{{ CompetitorsController::url($project->publicId, $competitor->publicId) }}" class="font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $competitor->name }}</a>
                <span class="block text-xs text-slate-500">{{ $competitor->domain }}</span>
              </td>
              <td class="px-3 py-2 text-xs {{ $competitor->status === Competitor::ACTIVE ? 'text-emerald-700' : 'text-slate-500' }}">{{ $statusLabels[$competitor->status] ?? $competitor->status }}</td>
              <td class="px-3 py-2 text-right">{{ Format::number($stats['found']) }} <span class="text-xs text-slate-400">/ {{ Format::number($stats['checked']) }}</span></td>
              <td class="px-3 py-2 text-right">{{ Format::number($stats['top3']) }}</td>
              <td class="px-3 py-2 text-right">{{ Format::number($stats['top10']) }}</td>
              <td class="px-3 py-2 text-right">{{ Format::number($stats['top20']) }}</td>
              <td class="px-3 py-2 text-right">{{ Format::position($stats['avg_rank']) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif

  <p class="mt-3 text-sm">
    @if ($archived)
      <a href="{{ $base }}" class="text-brand-600 hover:underline">Ukryj archiwum</a>
    @else
      <a href="{{ $base }}?archived=1" class="text-brand-600 hover:underline">Pokaż także archiwum</a>
    @endif
  </p>

  <div class="mt-6 space-y-1 text-xs text-slate-500">
    <p>Liczby pochodzą z ostatniego pomiaru każdej monitorowanej frazy (fakty, bez ocen): w ilu frazach domena konkurenta jest w sprawdzonych wynikach i na jakich pozycjach. Wstrzymanie lub archiwizacja nie usuwa danych.</p>
  </div>
@endsection
