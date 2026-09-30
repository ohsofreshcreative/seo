@extends('panel.layouts.app', ['active' => 'overview'])

@section('title', $project->name)

@section('content')
  <x-panel.page-header :title="$project->name" :description="$project->domain">
    <x-slot:actions>
      <x-panel.badge :status="$project->status" />
      @if ($canManageProjects)
        <x-panel.button variant="secondary" :href="\App\Panel\PanelUrl::project($project->publicId, 'edit')">Edytuj</x-panel.button>
      @endif
    </x-slot:actions>
  </x-panel.page-header>

  @if (! $project->connectionId)
    <x-panel.empty-state title="Brak danych z Google Search Console"
      description="Połącz projekt z Google Search Console, aby automatycznie pobrać frazy, kliknięcia, wyświetlenia, CTR i średnią pozycję (GSC).">
      <x-panel.button :href="\App\Panel\PanelUrl::project($project->publicId, 'search-console')">Przejdź do Search Console</x-panel.button>
    </x-panel.empty-state>
  @else
    <x-panel.empty-state title="Dane w przygotowaniu" description="Import danych z Google Search Console pojawi się w kolejnym etapie." />
  @endif

  <x-panel.card class="mt-8">
    <h2 class="text-base font-semibold text-slate-900">Informacje</h2>
    <dl class="mt-4 grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
      <div><dt class="text-slate-500">Domena</dt><dd class="mt-1 font-medium text-slate-900">{{ $project->domain }}</dd></div>
      <div><dt class="text-slate-500">Kraj / język</dt><dd class="mt-1 font-medium text-slate-900">{{ strtoupper($project->country) }} / {{ $project->language }}</dd></div>
      <div><dt class="text-slate-500">Property GSC</dt><dd class="mt-1 font-medium text-slate-900">{{ $project->gscProperty ?? '—' }}</dd></div>
    </dl>
  </x-panel.card>
@endsection
