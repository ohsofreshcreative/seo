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
            <dt class="text-slate-500">Status</dt>
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
            <dd class="mt-1 font-medium text-slate-900">{{ $project->gscProperty ?? 'nie wybrano (kolejny etap)' }}</dd>
          </div>
          <div>
            <dt class="text-slate-500">Ostatnie odświeżenie dostępu</dt>
            <dd class="mt-1 font-medium text-slate-900">{{ $connection->lastRefreshedAt ? wp_date('Y-m-d H:i', $connection->lastRefreshedAt->getTimestamp()) : '—' }}</dd>
          </div>
        </dl>

        @if (! $active)
          <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" role="alert">
            Google cofnął dostęp albo autoryzacja wygasła (w trybie testowym aplikacji OAuth po 7 dniach). Połącz projekt ponownie.
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
            <form method="post" action="{{ \App\Panel\PanelUrl::project($project->publicId, 'search-console/disconnect') }}"
              x-data @submit="if (! confirm('Odłączyć projekt od Google Search Console?')) $event.preventDefault()">
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
  </div>
@endsection
