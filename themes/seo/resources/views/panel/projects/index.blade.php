@extends('panel.layouts.app', ['active' => 'projects'])

@section('title', 'Projekty')

@section('content')
  <x-panel.page-header title="Projekty" :description="$archived ? 'Projekty zarchiwizowane.' : 'Aktywne i wstrzymane projekty.'">
    <x-slot:actions>
      @if ($canViewArchive)
        <x-panel.button variant="secondary" :href="$archived ? \App\Panel\PanelUrl::to('projects') : add_query_arg('status', 'archived', \App\Panel\PanelUrl::to('projects'))">
          {{ $archived ? 'Aktywne projekty' : 'Archiwum' }}
        </x-panel.button>
      @endif
      @if ($canManageProjects && ! $archived)
        <x-panel.button :href="\App\Panel\PanelUrl::to('projects/create')">Nowy projekt</x-panel.button>
      @endif
    </x-slot:actions>
  </x-panel.page-header>

  @if (empty($projects))
    <x-panel.empty-state :title="$archived ? 'Archiwum jest puste' : 'Brak projektów'" />
  @else
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
          <tr>
            <th scope="col" class="px-4 py-3">Projekt</th>
            <th scope="col" class="px-4 py-3">Domena</th>
            <th scope="col" class="px-4 py-3">Status</th>
            <th scope="col" class="hidden px-4 py-3 md:table-cell">Utworzono</th>
            @if ($canManageProjects)
              <th scope="col" class="px-4 py-3"><span class="sr-only">Akcje</span></th>
            @endif
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          @foreach ($projects as $project)
            <tr>
              <td class="px-4 py-3 font-medium text-slate-900">
                <a href="{{ \App\Panel\PanelUrl::project($project->publicId) }}" class="hover:text-brand-600">{{ $project->name }}</a>
              </td>
              <td class="px-4 py-3 text-slate-600">{{ $project->domain }}</td>
              <td class="px-4 py-3"><x-panel.badge :status="$project->status" /></td>
              <td class="hidden px-4 py-3 text-slate-500 md:table-cell">{{ wp_date('Y-m-d', $project->createdAt->getTimestamp()) }}</td>
              @if ($canManageProjects)
                <td class="px-4 py-3 text-right">
                  @if ($archived)
                    <form method="post" action="{{ \App\Panel\PanelUrl::project($project->publicId, 'restore') }}">
                      <x-panel.nonce />
                      <button type="submit" class="font-medium text-brand-600 hover:text-brand-700">Przywróć</button>
                    </form>
                  @else
                    <a href="{{ \App\Panel\PanelUrl::project($project->publicId, 'edit') }}" class="font-medium text-brand-600 hover:text-brand-700">Edytuj</a>
                  @endif
                </td>
              @endif
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
@endsection
