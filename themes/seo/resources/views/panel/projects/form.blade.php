@extends('panel.layouts.app', ['active' => $project ? 'overview' : 'projects'])

@section('title', $project ? 'Edycja projektu' : 'Nowy projekt')

@section('content')
  <x-panel.page-header :title="$project ? 'Edycja projektu' : 'Nowy projekt'"
    :description="$project ? $project->name : 'Dodaj stronę, którą chcesz monitorować w Google Search Console.'" />

  <div class="grid max-w-3xl gap-6">
    <x-panel.card>
      @if (! empty($errors))
        <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">Popraw zaznaczone pola.</div>
      @endif

      <form method="post" action="{{ $project ? \App\Panel\PanelUrl::project($project->publicId) : \App\Panel\PanelUrl::to('projects') }}" class="space-y-5">
        <x-panel.nonce />

        <x-panel.field name="name" label="Nazwa projektu" :value="$values['name'] ?? ''" :error="$errors['name'] ?? null" maxlength="190" required />
        <x-panel.field name="domain" label="Domena" :value="$values['domain'] ?? ''" :error="$errors['domain'] ?? null"
          help="Np. example.pl — adres zostanie znormalizowany (bez https://, www. i ścieżki)." required />

        @php
          $selectedMarket = null;
          foreach ($markets as $candidate) {
            if ($candidate->country === strtolower((string) ($values['country'] ?? '')) && $candidate->language === substr(strtolower((string) ($values['language'] ?? '')), 0, 2)) {
              $selectedMarket = $candidate;
            }
          }
        @endphp
        <div class="grid gap-5 sm:grid-cols-2">
          <x-panel.field name="country" label="Kraj (kod ISO)" :value="$values['country'] ?? 'pl'" :error="$errors['country'] ?? null" maxlength="2" list="market-countries" />
          <x-panel.field name="language" label="Język" :value="$values['language'] ?? 'pl'" :error="$errors['language'] ?? null" maxlength="10" list="market-languages" />
        </div>
        <datalist id="market-countries">
          @foreach (collect($markets)->unique('country') as $option)
            <option value="{{ $option->country }}">{{ $option->countryLabel }}</option>
          @endforeach
        </datalist>
        <datalist id="market-languages">
          @foreach (collect($markets)->unique('language') as $option)
            <option value="{{ $option->language }}">{{ $option->languageLabel }}</option>
          @endforeach
        </datalist>
        <p class="-mt-2 text-xs text-slate-500">
          Rynek SEO (dane rynkowe DataForSEO):
          @if ($selectedMarket)
            <span class="font-medium text-slate-700">{{ $selectedMarket->label() }}</span> (lokalizacja {{ $selectedMarket->locationCode }}, język {{ $selectedMarket->languageCode }}).
          @else
            <span class="font-medium text-amber-700">nieobsługiwany</span> — wolumen i trudność SEO nie będą pobierane.
          @endif
          Obsługiwane: {{ implode(', ', array_map(fn ($m) => $m->label() . ' (' . $m->country . ', ' . $m->language . ')', $markets)) }}.
        </p>

        <div class="flex gap-3">
          <x-panel.button type="submit">{{ $project ? 'Zapisz zmiany' : 'Utwórz projekt' }}</x-panel.button>
          <x-panel.button variant="secondary" :href="$project ? \App\Panel\PanelUrl::project($project->publicId) : \App\Panel\PanelUrl::to('projects')">Anuluj</x-panel.button>
        </div>
      </form>
    </x-panel.card>

    @if ($project)
      <x-panel.card>
        <h2 class="text-base font-semibold text-slate-900">Status projektu</h2>
        <p class="mt-1 text-sm text-slate-500">Wstrzymany projekt nie będzie synchronizowany. Zarchiwizowany znika z list klientów (dane zostają).</p>
        <div class="mt-4 flex flex-wrap gap-3">
          @if ($project->status === \OsfSeo\Projects\ProjectStatus::Active)
            <form method="post" action="{{ \App\Panel\PanelUrl::project($project->publicId, 'pause') }}">
              <x-panel.nonce />
              <x-panel.button type="submit" variant="secondary">Wstrzymaj</x-panel.button>
            </form>
          @else
            <form method="post" action="{{ \App\Panel\PanelUrl::project($project->publicId, 'restore') }}">
              <x-panel.nonce />
              <x-panel.button type="submit" variant="secondary">{{ $project->status === \OsfSeo\Projects\ProjectStatus::Archived ? 'Przywróć z archiwum' : 'Wznów' }}</x-panel.button>
            </form>
          @endif
          @if ($project->status !== \OsfSeo\Projects\ProjectStatus::Archived)
            <form method="post" action="{{ \App\Panel\PanelUrl::project($project->publicId, 'archive') }}"
              x-data @submit="if (! confirm('Zarchiwizować projekt? Klienci przestaną go widzieć.')) $event.preventDefault()">
              <x-panel.nonce />
              <x-panel.button type="submit" variant="danger">Archiwizuj</x-panel.button>
            </form>
          @endif
        </div>
      </x-panel.card>
    @endif
  </div>
@endsection
