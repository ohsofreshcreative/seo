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

  <x-panel.card class="mt-6 max-w-3xl">
    <h2 class="text-base font-semibold text-slate-900">DataForSEO (dane rynkowe fraz)</h2>
    <p class="mt-1 text-xs text-slate-500">Zatwierdzony płatny dostawca: wolumen, trudność SEO, CPC i konkurencja Ads. Dane logowania wyłącznie w wp-config.php.</p>
    <dl class="mt-2 divide-y divide-slate-100 text-sm">
      <div class="flex justify-between gap-4 py-3">
        <dt class="text-slate-500">Konfiguracja</dt>
        <dd class="text-right font-medium {{ $market['configured'] ? 'text-emerald-700' : 'text-amber-700' }}">
          @if ($market['configured'])
            Skonfigurowano
          @else
            Brak konfiguracji
            @foreach ($market['missing'] as $name)
              <span class="block font-mono text-xs">{{ $name }}</span>
            @endforeach
          @endif
        </dd>
      </div>
      <div class="flex justify-between gap-4 py-3">
        <dt class="text-slate-500">Limity kosztów (dziś / miesiąc)</dt>
        <dd class="text-right font-medium tabular-nums text-slate-900">
          {{ \App\Panel\Format::usd($market['budget']['spent_today'], 4) }} / {{ \App\Panel\Format::usd($market['budget']['daily_limit']) }},
          {{ \App\Panel\Format::usd($market['budget']['spent_month'], 4) }} / {{ \App\Panel\Format::usd($market['budget']['monthly_limit']) }}
          <span class="block text-xs font-normal text-slate-500">{{ \OsfSeo\Market\CostBudget::label($market['budget']['status']) }}; maks. zadań na przebieg: {{ $market['budget']['max_tasks_per_run'] }}</span>
        </dd>
      </div>
      <div class="flex justify-between gap-4 py-3">
        <dt class="text-slate-500">Zużycie — ostatnie 30 dni</dt>
        <dd class="text-right font-medium tabular-nums text-slate-900">{{ \App\Panel\Format::usd($market['usage_30d']['cost'], 4) }} <span class="block text-xs font-normal text-slate-500">zadania: {{ $market['usage_30d']['tasks'] }} (błędy: {{ $market['usage_30d']['failed'] }}), oczekujące: {{ $market['pending_tasks'] }}</span></dd>
      </div>
      <div class="flex justify-between gap-4 py-3">
        <dt class="text-slate-500">Odświeżanie</dt>
        <dd class="text-right font-medium text-slate-900">
          wolumen co {{ $market['settings']['volume_ttl_days'] }} dni, trudność co {{ $market['settings']['difficulty_ttl_days'] }} dni;
          automatycznie: {{ $market['auto_refresh'] ? 'tak (projekty po pierwszej ręcznej synchronizacji)' : 'nie' }}
          @if ($market['paused'] !== null)
            <span class="block text-xs text-amber-700">wstrzymane do {{ \App\Panel\Format::datetime($market['paused']['until']) }}</span>
          @endif
        </dd>
      </div>
      <div class="flex justify-between gap-4 py-3">
        <dt class="text-slate-500">Wybór fraz</dt>
        <dd class="text-right font-medium text-slate-900">≥ {{ $market['settings']['min_impressions'] }} wyświetleń w {{ $market['settings']['window_days'] }} dni, maks. {{ $market['settings']['sync_limit'] }} fraz na synchronizację</dd>
      </div>
      <div class="flex justify-between gap-4 py-3">
        <dt class="text-slate-500">Wyszukiwanie nowych fraz</dt>
        <dd class="text-right font-medium text-slate-900">
          maks. {{ $discovery['max_seeds'] }} seedów i {{ \App\Panel\Format::number($discovery['max_candidates']) }} fraz na wyszukiwanie; seed sprawdzony w ciągu {{ $discovery['ttl_days'] }} dni nie jest pobierany ponownie
          <span class="block text-xs font-normal text-slate-500">widoczność GSC: ostatnie {{ $discovery['window_days'] }} dni, „już widoczna” = średnia pozycja (GSC) ≤ {{ \App\Panel\Format::number($discovery['visible_position']) }}; koszty wliczane do tych samych limitów</span>
        </dd>
      </div>
    </dl>
  </x-panel.card>
@endsection
