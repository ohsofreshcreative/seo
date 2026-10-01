@extends('panel.layouts.app', ['active' => 'dashboard'])

@section('title', 'Dashboard')

@section('content')
  <x-panel.page-header title="Dashboard" description="Twoje projekty SEO w jednym miejscu.">
    @if ($canManageProjects)
      <x-slot:actions>
        <x-panel.button :href="\App\Panel\PanelUrl::to('projects/create')">Nowy projekt</x-panel.button>
      </x-slot:actions>
    @endif
  </x-panel.page-header>

  <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
    <x-panel.card>
      <dt class="text-sm text-slate-500">Projekty</dt>
      <dd class="mt-1 text-3xl font-semibold text-slate-900">{{ count($projects) }}</dd>
    </x-panel.card>
    <x-panel.card>
      <dt class="text-sm text-slate-500">Aktywne</dt>
      <dd class="mt-1 text-3xl font-semibold text-slate-900">{{ $activeCount }}</dd>
    </x-panel.card>
    <x-panel.card>
      <dt class="text-sm text-slate-500">Wstrzymane</dt>
      <dd class="mt-1 text-3xl font-semibold text-slate-900">{{ $pausedCount }}</dd>
    </x-panel.card>
  </dl>

  <h2 class="mb-4 mt-10 text-base font-semibold text-slate-900">Projekty</h2>

  @if (empty($projects))
    <x-panel.empty-state title="Brak projektów"
      :description="$canManageProjects ? 'Dodaj pierwszy projekt, a potem połącz go z Google Search Console.' : 'Nie masz jeszcze dostępu do żadnego projektu.'">
      @if ($canManageProjects)
        <x-panel.button :href="\App\Panel\PanelUrl::to('projects/create')">Nowy projekt</x-panel.button>
      @endif
    </x-panel.empty-state>
  @else
    <ul class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
      @foreach ($projects as $project)
        <li>
          <a href="{{ \App\Panel\PanelUrl::project($project->publicId) }}" class="block rounded-lg border border-slate-200 bg-white p-5 shadow-sm hover:border-brand-200 hover:shadow">
            <div class="flex items-start justify-between gap-3">
              <p class="font-medium text-slate-900">{{ $project->name }}</p>
              <x-panel.badge :status="$project->status" />
            </div>
            <p class="mt-1 text-sm text-slate-500">{{ $project->domain }}</p>
            <p class="mt-4 text-xs text-slate-400">{{ $project->connectionId ? 'Search Console: połączono' : 'Search Console: niepołączono' }}</p>
          </a>
        </li>
      @endforeach
    </ul>
  @endif
@endsection
