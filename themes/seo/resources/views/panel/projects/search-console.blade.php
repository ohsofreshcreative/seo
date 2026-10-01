@extends('panel.layouts.app', ['active' => 'search-console'])

@section('title', 'Search Console · ' . $project->name)

@section('content')
  <x-panel.page-header title="Google Search Console" :description="$project->name . ' · ' . $project->domain" />

  <div class="grid max-w-3xl gap-6">
    @if (! $configured)
      <x-panel.card>
        <h2 class="text-base font-semibold text-slate-900">Integracja z Google nie jest skonfigurowana</h2>
        @if ($canManage)
          <p class="mt-2 text-sm text-slate-600">Dane OAuth i klucz szyfrowania ustawia się wyłącznie w <code>wp-config.php</code> (nigdy w repozytorium ani w bazie):</p>
          <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-700">
            @foreach ($configProblems as $problem)
              <li><code>{{ $problem }}</code></li>
            @endforeach
          </ul>
          <p class="mt-4 text-sm text-slate-600">Authorized redirect URI w Google Cloud:</p>
          <p class="mt-1 break-all rounded-md bg-slate-50 px-3 py-2 font-mono text-sm text-slate-800">{{ $redirectUri }}</p>
        @else
          <p class="mt-2 text-sm text-slate-600">Administrator agencji jeszcze nie skonfigurował integracji z Google.</p>
        @endif
      </x-panel.card>
    @endif

    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Połączenie</h2>

      @if ($connection)
        @php($active = $connection->status === \OsfSeo\Google\ConnectionStatus::Active)
        <dl class="mt-4 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-slate-500">Konto Google</dt>
            <dd class="mt-1 font-medium text-slate-900">{{ $connection->email !== '' ? $connection->email : '—' }}</dd>
          </div>
          <div>
            <dt class="text-slate-500">Status połączenia</dt>
            <dd class="mt-1">
              <span @class([
                'inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset',
                'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $active,
                'bg-amber-50 text-amber-700 ring-amber-600/20' => ! $active,
              ])>{{ $connection->status->label() }}</span>
            </dd>
          </div>
          <div>
            <dt class="text-slate-500">Property GSC</dt>
            <dd class="mt-1 break-all font-medium text-slate-900">{{ $project->gscProperty ?? 'nie wybrano' }}</dd>
          </div>
          <div>
            <dt class="text-slate-500">Uprawnienia do property</dt>
            <dd class="mt-1 font-medium text-slate-900">{{ \OsfSeo\Gsc\PermissionLevel::labelFor($project->gscPermission) }}</dd>
          </div>
          <div>
            <dt class="text-slate-500">Ostatnie odświeżenie dostępu</dt>
            <dd class="mt-1 font-medium text-slate-900">{{ $connection->lastRefreshedAt ? wp_date('Y-m-d H:i', $connection->lastRefreshedAt->getTimestamp()) : '—' }}</dd>
          </div>
        </dl>

        @if (! $active)
          <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" role="alert">
            Google cofnął dostęp albo autoryzacja wygasła (w trybie testowym aplikacji OAuth po 7 dniach). Połącz projekt ponownie —
            synchronizacja jest wstrzymana do czasu ponownej autoryzacji.
          </div>
        @endif

        @if ($canManage)
          <div class="mt-6 flex flex-wrap gap-3">
            @if (! $active && $configured)
              <form method="post" action="{{ \App\Panel\PanelUrl::project($project->publicId, 'search-console/connect') }}">
                <x-panel.nonce />
                <x-panel.button type="submit">Połącz ponownie</x-panel.button>
              </form>
            @endif
            @if ($active && $project->gscProperty && ! $choosing)
              <x-panel.button variant="secondary" :href="\App\Panel\PanelUrl::project($project->publicId, 'search-console') . '?change=1'">Zmień property</x-panel.button>
            @endif
            <form method="post" action="{{ \App\Panel\PanelUrl::project($project->publicId, 'search-console/disconnect') }}"
              x-data @submit="if (! confirm('Odłączyć projekt od Google Search Console? Zapisane dane zostaną zachowane.')) $event.preventDefault()">
              <x-panel.nonce />
              <x-panel.button type="submit" variant="danger">Odłącz</x-panel.button>
            </form>
          </div>
        @endif
      @else
        <p class="mt-2 text-sm text-slate-600">Projekt nie jest połączony z Google Search Console.</p>

        @if ($canManage && $configured)
          <form method="post" action="{{ \App\Panel\PanelUrl::project($project->publicId, 'search-console/connect') }}" class="mt-6">
            <x-panel.nonce />
            <x-panel.button type="submit">Połącz z Google Search Console</x-panel.button>
          </form>
          <p class="mt-3 text-xs text-slate-500">
            Aplikacja prosi wyłącznie o odczyt danych Search Console (<code>webmasters.readonly</code>) oraz adres e-mail konta Google
            do rozpoznania połączenia. Dostęp można odłączyć w dowolnej chwili.
          </p>
        @elseif (! $canManage)
          <p class="mt-2 text-sm text-slate-500">Połączenie konfiguruje administrator agencji.</p>
        @endif
      @endif
    </x-panel.card>

    @if ($choosing)
      <x-panel.card>
        <h2 class="text-base font-semibold text-slate-900">Wybierz property Search Console</h2>
        <p class="mt-1 text-sm text-slate-600">
          Lista pochodzi z konta Google {{ $connection->email }}. Dane będą pobierane wyłącznie z wybranej property.
        </p>

        @if ($propertiesError)
          <div class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ $propertiesError }}</div>
        @elseif ($properties && $properties->properties === [])
          <p class="mt-4 text-sm text-slate-600">To konto Google nie ma dostępu do żadnej property Search Console. Dodaj użytkownika w Search Console albo połącz inne konto.</p>
        @elseif ($properties)
          @php($checked = $project->gscProperty ?? $properties->suggested)
          <form method="post" action="{{ \App\Panel\PanelUrl::project($project->publicId, 'search-console/property') }}" class="mt-4 space-y-4">
            <x-panel.nonce />
            <fieldset class="space-y-2">
              <legend class="sr-only">Property</legend>
              @foreach ($properties->properties as $property)
                @php($usable = $property->canQuerySearchAnalytics())
                <label @class([
                  'flex items-start gap-3 rounded-md border px-4 py-3',
                  'cursor-pointer border-slate-200 hover:bg-slate-50' => $usable,
                  'cursor-not-allowed border-slate-100 bg-slate-50 opacity-70' => ! $usable,
                ])>
                  <input type="radio" name="property" value="{{ $property->siteUrl }}" class="mt-1 text-brand-700 focus:ring-brand-500"
                    @checked($usable && $property->siteUrl === $checked) @disabled(! $usable) required>
                  <span class="min-w-0">
                    <span class="block break-all font-mono text-sm text-slate-900">{{ $property->siteUrl }}</span>
                    <span class="mt-0.5 block text-xs text-slate-500">
                      {{ $property->typeLabel() }} · {{ \OsfSeo\Gsc\PermissionLevel::labelFor($property->permissionLevel) }}
                      @if ($property->siteUrl === $project->gscProperty)
                        · <span class="font-medium text-slate-700">obecnie wybrana</span>
                      @endif
                    </span>
                    @if ($property->siteUrl === $properties->suggested)
                      <span class="mt-1 inline-flex rounded-md bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700 ring-1 ring-inset ring-sky-600/20">Sugerowana dla {{ $project->domain }}</span>
                    @endif
                    @if ($usable && $properties->requiresReset($property->siteUrl))
                      <span class="mt-1 block text-xs text-amber-700">Wybór wymaga usunięcia dotychczasowych danych projektu.</span>
                    @endif
                  </span>
                </label>
              @endforeach
            </fieldset>

            @if ($properties->hasData)
              <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <p>
                  Projekt ma już zapisane dane
                  @if ($properties->dataProperty)
                    z property <span class="break-all font-mono">{{ $properties->dataProperty }}</span>.
                  @else
                    z nieustalonej property.
                  @endif
                  Dane dwóch properties nie są mieszane: wybór innej property usuwa dotychczasowe dane GSC projektu i importuje je od nowa.
                </p>
                <label class="mt-3 flex items-start gap-2">
                  <input type="checkbox" name="reset_data" value="1" class="mt-0.5 rounded text-red-600 focus:ring-red-500">
                  <span>Rozumiem — przy zmianie property usuń dotychczasowe dane GSC projektu i zaimportuj je od nowa.</span>
                </label>
              </div>
            @endif

            <div class="flex flex-wrap gap-3">
              <x-panel.button type="submit">Zapisz property</x-panel.button>
              @if ($project->gscProperty)
                <x-panel.button variant="secondary" :href="\App\Panel\PanelUrl::project($project->publicId, 'search-console')">Anuluj</x-panel.button>
              @endif
            </div>
          </form>
        @endif
      </x-panel.card>
    @elseif ($connection && ! $project->gscProperty && ! $canManage)
      <x-panel.card>
        <p class="text-sm text-slate-600">Property Search Console nie została jeszcze wybrana — zrobi to administrator agencji.</p>
      </x-panel.card>
    @endif
  </div>
@endsection
