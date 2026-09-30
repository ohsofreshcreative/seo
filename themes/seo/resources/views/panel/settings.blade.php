@extends('panel.layouts.app', ['active' => 'settings'])

@section('title', 'Ustawienia')

@section('content')
  <x-panel.page-header title="Ustawienia" description="Stan aplikacji. Sekrety (np. klucze Google) konfiguruje się wyłącznie w wp-config.php." />

  <x-panel.card class="max-w-3xl">
    <dl class="divide-y divide-slate-100 text-sm">
      @foreach ([
        'Wersja pluginu' => $pluginVersion,
        'Schemat bazy' => $schemaVersion . ' (najnowszy: ' . $schemaLatest . ')',
        'Środowisko' => $environment,
        'PHP' => $phpVersion,
        'WordPress' => $wpVersion,
      ] as $label => $value)
        <div class="flex justify-between gap-4 py-3">
          <dt class="text-slate-500">{{ $label }}</dt>
          <dd class="font-medium text-slate-900">{{ $value }}</dd>
        </div>
      @endforeach
    </dl>
  </x-panel.card>

  <x-panel.card class="mt-6 max-w-3xl">
    <h2 class="text-base font-semibold text-slate-900">Google Search Console (OAuth)</h2>
    <dl class="mt-2 divide-y divide-slate-100 text-sm">
      <div class="flex justify-between gap-4 py-3">
        <dt class="text-slate-500">Konfiguracja</dt>
        <dd class="text-right font-medium {{ empty($googleProblems) ? 'text-emerald-700' : 'text-amber-700' }}">
          @if (empty($googleProblems))
            Skonfigurowano
          @else
            @foreach ($googleProblems as $problem)
              <span class="block font-mono text-xs">{{ $problem }}</span>
            @endforeach
          @endif
        </dd>
      </div>
      <div class="flex justify-between gap-4 py-3">
        <dt class="text-slate-500">Authorized redirect URI</dt>
        <dd class="break-all text-right font-mono text-xs text-slate-900">{{ $googleRedirectUri }}</dd>
      </div>
      <div class="flex justify-between gap-4 py-3">
        <dt class="text-slate-500">Zakresy (scopes)</dt>
        <dd class="text-right font-mono text-xs text-slate-900">{{ implode(' ', $googleScopes) }}</dd>
      </div>
      <div class="flex justify-between gap-4 py-3">
        <dt class="text-slate-500">Połączenia</dt>
        <dd class="text-right font-medium text-slate-900">
          aktywne: {{ $googleConnections['active'] ?? 0 }}, do ponownej autoryzacji: {{ $googleConnections['needs_reauth'] ?? 0 }}
        </dd>
      </div>
    </dl>
  </x-panel.card>
@endsection
