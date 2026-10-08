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

  <x-panel.card id="wyglad" class="mt-6 max-w-3xl scroll-mt-20">
    <h2 class="text-base font-semibold text-slate-900">Wygląd aplikacji</h2>
    <p class="mt-1 text-xs text-slate-500">
      Logo w lewym menu i na ekranie logowania — obraz z biblioteki mediów WordPressa (PNG, JPG albo WebP, do {{ \App\Panel\Format::number($logoMaxBytes / 1048576) }} MB;
      proporcje zachowane, w menu najwyżej 80 px wysokości). Bez logo panel pokazuje napis „Whack-a-mole”.
    </p>

    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
      <div>
        <p class="text-xs font-medium text-slate-500">Podgląd w menu</p>
        <div class="mt-2 flex h-20 w-64 max-w-full items-center overflow-hidden rounded-md bg-brand-900 px-6" data-logo-preview>
          <x-panel.brand :logo="$logo" />
        </div>
      </div>
      <div>
        <p class="text-xs font-medium text-slate-500">Bieżące logo</p>
        <p class="mt-2 break-all text-sm text-slate-900">{{ $logo ? $logo->title : 'Brak — napis „Whack-a-mole”' }}</p>
        @if ($logo)
          <p class="text-xs text-slate-500">Tekst alternatywny: {{ $logo->alt }}</p>
          <form method="post" action="{{ \App\Panel\PanelUrl::to('settings/logo/remove') }}" class="mt-3">
            <x-panel.nonce />
            <x-panel.button type="submit" variant="danger">Usuń logo</x-panel.button>
          </form>
        @endif
      </div>
    </div>

    <form method="post" action="{{ \App\Panel\PanelUrl::to('settings/logo') }}" enctype="multipart/form-data" class="mt-6 border-t border-slate-100 pt-4">
      <x-panel.nonce />
      <label for="logo-file" class="block text-sm font-medium text-slate-700">Prześlij nowy obraz</label>
      <div class="mt-2 flex flex-wrap items-center gap-3">
        <input id="logo-file" type="file" name="logo" accept="image/png,image/jpeg,image/webp" required
          class="block w-full max-w-sm text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200">
        <x-panel.button type="submit">Prześlij i ustaw</x-panel.button>
      </div>
      <p class="mt-1 text-xs text-slate-500">Obraz trafi do biblioteki mediów; SVG i inne formaty są odrzucane.</p>
    </form>

    <form method="post" action="{{ \App\Panel\PanelUrl::to('settings/logo/select') }}" class="mt-6 border-t border-slate-100 pt-4">
      <x-panel.nonce />
      <fieldset>
        <legend class="text-sm font-medium text-slate-700">Wybierz z biblioteki mediów</legend>
        @if ($logoLibrary === [])
          <p class="mt-2 text-sm text-slate-500">Biblioteka mediów nie zawiera jeszcze obrazów PNG, JPG ani WebP.</p>
        @else
          <div class="mt-2 grid grid-cols-3 gap-3 sm:grid-cols-6">
            @foreach ($logoLibrary as $image)
              <label class="group relative flex cursor-pointer flex-col items-center gap-1 rounded-md border border-slate-200 p-2 text-center hover:border-brand-500 has-[:checked]:border-brand-600 has-[:checked]:ring-2 has-[:checked]:ring-brand-500 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-brand-700">
                <input type="radio" name="attachment_id" value="{{ $image->id }}" @checked($logo && $logo->id === $image->id) class="sr-only" required>
                <img src="{{ $image->thumbnailUrl }}" alt="" class="h-12 w-full object-contain" loading="lazy" decoding="async">
                <span class="w-full truncate text-xs text-slate-600">{{ $image->title }}</span>
              </label>
            @endforeach
          </div>
          <x-panel.button type="submit" variant="secondary" class="mt-3">Ustaw wybrany obraz</x-panel.button>
        @endif
      </fieldset>
    </form>
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
        <dd class="min-w-0 break-all text-right font-mono text-xs text-slate-900">{{ implode(' ', $googleScopes) }}</dd>
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
      <div class="flex justify-between gap-4 py-3">
        <dt class="text-slate-500">Pozycje SERP</dt>
        <dd class="text-right font-medium text-slate-900">
          zalecany limit {{ \App\Panel\Format::number($serp['config']['max_keywords']) }} monitorowanych fraz na projekt; ta sama fraza nie jest zlecana ponownie przez {{ $serp['config']['min_recheck_hours'] }} h
          <span class="block text-xs font-normal text-slate-500">
            szacowany maks. koszt frazy (kolejka Standard):
            {{ implode(', ', array_map(fn ($depth, $price) => 'TOP' . $depth . ' ' . \App\Panel\Format::usd($price, 5), array_keys($serp['pricing']), $serp['pricing'])) }};
            maks. {{ $serp['config']['max_posts_per_run'] }} zleceń (po 100 zadań) na przebieg tła, wyniki nieodebrane po {{ $serp['config']['expire_hours'] }} h wygasają; koszty wliczane do tych samych limitów
          </span>
        </dd>
      </div>
    </dl>
  </x-panel.card>

  <x-panel.card id="ai" class="mt-6 max-w-3xl scroll-mt-20">
    <h2 class="text-base font-semibold text-slate-900">Analizy AI i pobieranie stron</h2>
    <p class="mt-1 text-xs text-slate-500">
      Konfiguracja wyłącznie w wp-config.php (stałe OSF_SEO_AI_*, OSF_SEO_OPENAI_API_KEY, OSF_SEO_PAGES_*) — panel pokazuje tylko stan, nigdy wartości kluczy.
      Płatne analizy działają dopiero po jawnym włączeniu i podaniu modelu, cen i limitów. Budżet AI jest oddzielny od DataForSEO.
    </p>
    @php($aiConfig = $ai['config'])
    <dl class="mt-2 divide-y divide-slate-100 text-sm">
      <div class="flex justify-between gap-4 py-3">
        <dt class="text-slate-500">Płatne analizy AI</dt>
        <dd class="text-right font-medium {{ $aiConfig['enabled'] ? 'text-emerald-700' : 'text-slate-700' }}">{{ $aiConfig['enabled'] ? 'Włączone' : 'Wyłączone (dostępny tylko dostawca testowy, bez kosztów)' }}</dd>
      </div>
      @foreach ($ai['providers'] as $aiProvider)
        <div class="flex justify-between gap-4 py-3">
          <dt class="text-slate-500">{{ $aiProvider['label'] }}</dt>
          <dd class="text-right font-medium text-slate-900">
            @if (! $aiProvider['paid'])
              zawsze dostępny (wynik przykładowy)
            @else
              {{ $aiProvider['configured'] ? 'wybrany w konfiguracji' : 'nie wybrany' }}; model: {{ $aiProvider['model'] ?? 'brak' }}; klucz API: {{ $aiProvider['api_key'] ? 'ustawiony' : 'brak' }}
              @foreach ($aiProvider['problems'] as $problem)
                <span class="block text-xs font-normal text-amber-700">{{ $problem }}</span>
              @endforeach
            @endif
          </dd>
        </div>
      @endforeach
      <div class="flex justify-between gap-4 py-3">
        <dt class="text-slate-500">Ceny modelu</dt>
        <dd class="text-right font-medium text-slate-900">{{ $ai['prices_configured'] ? 'ustawione' : 'brak — płatna analiza zostanie odrzucona' }}</dd>
      </div>
      <div class="flex justify-between gap-4 py-3">
        <dt class="text-slate-500">Budżet AI (dziś / miesiąc)</dt>
        <dd class="text-right font-medium tabular-nums text-slate-900">
          {{ \App\Panel\Format::usd($ai['budget']['spent']['today'], 4) }} / {{ \App\Panel\Format::usd($ai['budget']['limits']['daily']) }},
          {{ \App\Panel\Format::usd($ai['budget']['spent']['month'], 4) }} / {{ \App\Panel\Format::usd($ai['budget']['limits']['monthly']) }}
          <span class="block text-xs font-normal text-slate-500">limit projektu w miesiącu {{ \App\Panel\Format::usd($ai['budget']['limits']['project_monthly']) }}; maks. koszt jednej analizy {{ \App\Panel\Format::usd($ai['budget']['limits']['max_run_cost']) }}</span>
        </dd>
      </div>
      <div class="flex justify-between gap-4 py-3">
        <dt class="text-slate-500">Kolejka analiz AI</dt>
        <dd class="text-right font-medium text-slate-900">
          w kolejce: {{ $ai['runs']['queued'] ?? 0 }}, w trakcie: {{ ($ai['runs']['running'] ?? 0) + ($ai['runs']['reserved'] ?? 0) }}, niepewne: {{ $ai['runs']['uncertain'] ?? 0 }}
          <span class="block text-xs font-normal text-slate-500">wykonuje przetwarzanie w tle (WP-Cron albo wp osf-seo sync:run); bez automatycznego harmonogramu analiz i bez ponowień</span>
        </dd>
      </div>
      <div class="flex justify-between gap-4 py-3">
        <dt class="text-slate-500">Pobieranie stron</dt>
        <dd class="text-right font-medium text-slate-900">
          {{ $pages['transport'] ? 'bezpieczny transport dostępny' : 'niedostępne na tym serwerze (brak rozszerzenia cURL z przypinaniem adresu)' }};
          strony projektu {{ $pages['config']['project_enabled'] ? 'tak' : 'nie' }}, konkurencji {{ $pages['config']['competitors_enabled'] ? 'tak' : 'nie' }}
          <span class="block text-xs font-normal text-slate-500">
            kopia aktualna {{ $pages['config']['ttl_hours'] }} h; odstęp między żądaniami do witryny {{ $pages['config']['domain_interval'] }} s, limit {{ $pages['config']['domain_daily_limit'] }} na dobę;
            zlecenia z panelu: maks. {{ min(5, $pages['config']['max_urls']) }} adresów; w kolejce {{ $ai['page_jobs']['queued'] ?? 0 }}, w trakcie {{ $ai['page_jobs']['running'] ?? 0 }}
          </span>
        </dd>
      </div>
    </dl>
  </x-panel.card>
@endsection
