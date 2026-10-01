@extends('panel.layouts.base')

@php($active = $active ?? '')

@section('body')
  <div x-data="{ sidebarOpen: false }" class="min-h-full">
    {{-- Sidebar --}}
    <div x-cloak x-show="sidebarOpen" x-transition.opacity class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" @click="sidebarOpen = false"></div>

    <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
      class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-brand-900 text-slate-200 transition-transform lg:translate-x-0">
      <div class="flex h-16 items-center px-6">
        <a href="{{ \App\Panel\PanelUrl::to() }}" class="text-lg font-semibold tracking-tight text-white">OSF SEO</a>
      </div>

      <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4" aria-label="Menu główne">
        <div class="space-y-1">
          <x-panel.nav-link :href="\App\Panel\PanelUrl::to()" :active="$active === 'dashboard'">Dashboard</x-panel.nav-link>
          <x-panel.nav-link :href="\App\Panel\PanelUrl::to('projects')" :active="$active === 'projects'">Projekty</x-panel.nav-link>
          @if ($canManageSettings)
            <x-panel.nav-link :href="\App\Panel\PanelUrl::to('settings')" :active="$active === 'settings'">Ustawienia</x-panel.nav-link>
          @endif
        </div>

        @if ($currentProject)
          <div>
            <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $currentProject->name }}</p>
            <div class="space-y-1">
              <x-panel.nav-link :href="\App\Panel\PanelUrl::project($currentProject->publicId)" :active="$active === 'overview'">Przegląd</x-panel.nav-link>
              <x-panel.nav-link :href="\App\Panel\PanelUrl::project($currentProject->publicId, 'keywords')" :active="$active === 'keywords'">Frazy</x-panel.nav-link>
              <x-panel.nav-link :href="\App\Panel\PanelUrl::project($currentProject->publicId, 'opportunities')" :active="$active === 'opportunities'">Szanse SEO</x-panel.nav-link>
              <x-panel.nav-link :href="\App\Panel\PanelUrl::project($currentProject->publicId, 'pages')" :active="$active === 'pages'">Strony</x-panel.nav-link>
              <x-panel.nav-link :href="\App\Panel\PanelUrl::project($currentProject->publicId, 'search-console')" :active="$active === 'search-console'">Search Console</x-panel.nav-link>
              <x-panel.nav-link :href="\App\Panel\PanelUrl::project($currentProject->publicId, 'audit')" :active="$active === 'audit'">Audyt</x-panel.nav-link>
            </div>
          </div>
        @endif
      </nav>
    </aside>

    <div class="lg:pl-64">
      {{-- Topbar --}}
      <header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-slate-200 bg-white px-4 sm:px-6">
        <button type="button" class="rounded-md p-2 text-slate-500 hover:bg-slate-100 lg:hidden" @click="sidebarOpen = true">
          <span class="sr-only">Otwórz menu</span>
          <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
          </svg>
        </button>

        <div x-data="{ open: false }" class="relative" @keydown.escape="open = false">
          <button type="button" @click="open = !open" :aria-expanded="open.toString()"
            class="inline-flex items-center gap-2 rounded-md border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
            {{ $currentProject ? $currentProject->name : 'Wybierz projekt' }}
            <svg class="h-4 w-4 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
              <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
            </svg>
          </button>
          <div x-cloak x-show="open" @click.outside="open = false"
            class="absolute left-0 z-30 mt-2 max-h-80 w-72 overflow-y-auto rounded-md border border-slate-200 bg-white py-1 shadow-lg">
            @forelse ($navProjects as $navProject)
              <a href="{{ \App\Panel\PanelUrl::project($navProject->publicId) }}" class="block px-4 py-2 text-sm hover:bg-slate-50">
                <span class="font-medium text-slate-800">{{ $navProject->name }}</span>
                <span class="block text-xs text-slate-500">{{ $navProject->domain }}</span>
              </a>
            @empty
              <p class="px-4 py-2 text-sm text-slate-500">Brak projektów.</p>
            @endforelse
          </div>
        </div>

        <div class="ml-auto flex items-center gap-4">
          <span class="hidden text-sm text-slate-600 sm:block">{{ $panelUser['name'] }}</span>
          <form method="post" action="{{ \App\Panel\PanelUrl::to('logout') }}">
            <x-panel.nonce />
            <button type="submit" class="text-sm font-medium text-slate-600 hover:text-slate-900">Wyloguj</button>
          </form>
        </div>
      </header>

      <main class="px-4 py-8 sm:px-6 lg:px-8">
        <x-panel.flash :messages="$flashMessages" />

        @yield('content')
      </main>
    </div>
  </div>
@endsection
