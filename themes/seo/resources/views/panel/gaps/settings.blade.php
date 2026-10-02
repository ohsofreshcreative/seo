@extends('panel.layouts.app', ['active' => 'gaps'])

@section('title', 'Ustawienia Luk SEO · ' . $project->name)

@php
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Gap\BrandMatcher;
  use OsfSeo\Gap\GapConfig;
  use OsfSeo\Serp\DomainFamily;

  $base = PanelUrl::project($project->publicId, 'gaps');
  $value = fn (string $key, mixed $current) => array_key_exists($key, $old) ? (string) $old[$key] : (string) $current;
  $skipReasons = [
    'no_competitors' => 'brak aktywnych konkurentów',
    'unsupported_market' => 'rynek nieobsługiwany',
    'already_running' => 'trwał inny import',
    'not_configured' => 'DataForSEO nie jest skonfigurowane',
    'daily_limit' => 'dzienny limit kosztów',
    'monthly_limit' => 'miesięczny limit kosztów',
    'paused' => 'płatne wywołania wstrzymane po błędzie konta',
  ];
  $competitors = array_values(array_filter($datasets, static fn (array $entry): bool => $entry['role'] === 'competitor'));
@endphp

@section('content')
  <x-panel.page-header title="Ustawienia Luk SEO" :description="$project->name . ' · progi analizy (bez kosztów), domyślny zakres importu, harmonogram i warianty marki'" />

  @include('panel.gaps.partials.tabs', ['tab' => 'settings', 'canManage' => true])

  <form method="post" action="{{ $base }}/settings" class="grid gap-6 lg:grid-cols-2">
    <x-panel.nonce />

    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Analiza luk</h2>
      <p class="mt-1 text-xs text-slate-500">Zmiana nic nie kosztuje — luki przeliczą się z zapisanych danych w tle.</p>
      <div class="mt-4 space-y-4">
        <div>
          <label for="competitor-max-rank" class="block text-sm font-medium text-slate-700">Znacząca pozycja konkurenta</label>
          <select id="competitor-max-rank" name="competitor_max_rank" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            @foreach (GapConfig::COMPETITOR_RANKS as $rank)
              <option value="{{ $rank }}" @selected((int) $value('competitor_max_rank', $settings->competitorMaxRank) === $rank)>TOP{{ $rank }}</option>
            @endforeach
          </select>
          <p class="mt-1 text-xs text-slate-500">Luką jest fraza, na którą co najmniej jeden aktywny konkurent rankuje w tym progu (pozycja z DataForSEO Labs).</p>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <x-panel.field name="min_volume" label="Min. wolumen" type="number" min="0" :value="$value('min_volume', $settings->minVolume)" :error="$errors['min_volume'] ?? null" />
          <x-panel.field name="max_difficulty" label="Maks. trudność SEO" type="number" min="0" max="100" placeholder="bez limitu" :value="$value('max_difficulty', $settings->maxDifficulty ?? '')" :error="$errors['max_difficulty'] ?? null" />
        </div>
        <div>
          <label for="include-terms" class="block text-sm font-medium text-slate-700">Słowa tematyczne (opcjonalnie)</label>
          <textarea id="include-terms" name="include_terms" rows="3" placeholder="np. strony&#10;sklep*" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">{{ $value('include_terms', $settings->includeTerms) }}</textarea>
          <p class="mt-1 text-xs text-slate-500">Gdy podane, lukami są tylko frazy zawierające któreś z tych słów (całe słowa; <code>*</code> na końcu = dowolna końcówka). Puste = wszystkie frazy.</p>
        </div>
        <div>
          <label for="brand-terms" class="block text-sm font-medium text-slate-700">Warianty marki projektu</label>
          <textarea id="brand-terms" name="brand_terms" rows="2" placeholder="np. ohsofresh&#10;oh so fresh" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">{{ $value('brand_terms', $settings->brandTerms) }}</textarea>
          <p class="mt-1 text-xs text-slate-500">Frazy z marką projektu nie są lukami. Nazwa i domena projektu są rozpoznawane automatycznie ({{ implode(', ', BrandMatcher::build([(string) DomainFamily::normalize($project->domain)], [$project->name])->labels()) }}).</p>
        </div>
        <p class="text-xs text-slate-500">Wykluczone słowa są wspólne z modułem <a href="{{ PanelUrl::project($project->publicId, 'discovery') }}" class="text-brand-600 hover:underline">Nowe frazy</a> — zmienisz je tam.</p>
      </div>
    </x-panel.card>

    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Domyślny zakres importu</h2>
      <p class="mt-1 text-xs text-slate-500">Używany przez formularz importu i harmonogram. Zmiana nie wysyła żadnych żądań.</p>
      <div class="mt-4 space-y-4">
        <div class="grid grid-cols-3 gap-3">
          <div>
            <label for="fetch-max-rank" class="block text-sm font-medium text-slate-700">Pozycje</label>
            <select id="fetch-max-rank" name="fetch_max_rank" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
              @foreach (GapConfig::FETCH_RANKS as $rank)
                <option value="{{ $rank }}" @selected((int) $value('fetch_max_rank', $settings->fetchMaxRank) === $rank)>TOP{{ $rank }}</option>
              @endforeach
            </select>
          </div>
          <x-panel.field name="fetch_min_volume" label="Min. wolumen" type="number" min="0" :value="$value('fetch_min_volume', $settings->fetchMinVolume)" :error="$errors['fetch_min_volume'] ?? null" />
          <x-panel.field name="max_rows" label="Maks. fraz / domenę" type="number" min="100" :max="$maxRows" step="100" :value="$value('max_rows', $settings->maxRows)" :error="$errors['max_rows'] ?? null" />
        </div>
        <x-panel.field name="refresh_days" label="Odświeżanie zbiorów co (dni)" type="number" min="7" max="180" :value="$value('refresh_days', $settings->refreshDays)" :error="$errors['refresh_days'] ?? null" help="Dotyczy harmonogramu; zbiory młodsze niż ten okres są używane z pamięci." />
      </div>
      <div class="mt-6">
        <x-panel.button type="submit">Zapisz ustawienia</x-panel.button>
      </div>
    </x-panel.card>
  </form>

  <x-panel.card class="mt-6">
    <h2 class="text-base font-semibold text-slate-900">Harmonogram odświeżania</h2>
    <p class="mt-1 text-sm text-slate-600">
      Stan: <strong class="font-medium">{{ $settings->scheduleEnabled ? 'włączony' : 'wyłączony' }}</strong>
      @if ($settings->scheduleEnabled && $settings->nextRefreshAt !== null)
        · następne sprawdzenie {{ Format::datetime($settings->nextRefreshAt) }}
      @endif
      @if ($settings->lastSkipReason !== null)
        · ostatnio pominięte: {{ $skipReasons[$settings->lastSkipReason] ?? $settings->lastSkipReason }} ({{ Format::datetime($settings->lastSkipAt) }})
      @endif
    </p>
    <p class="mt-1 text-xs text-slate-500">Co {{ $settings->refreshDays }} dni import nieświeżych zbiorów domen w domyślnym zakresie — w tle, tylko gdy oczekiwany koszt (liczba fraz z poprzedniego importu) mieści się w dzisiejszym i miesięcznym limicie. Szacowany maksymalny koszt: <strong class="font-medium text-slate-700">{{ Format::usd($monthly, 2) }} miesięcznie</strong> (zbiory wspólne z innymi projektami bywają darmowe).</p>
    @if ($settings->scheduleEnabled)
      <form method="post" action="{{ $base }}/schedule" class="mt-4">
        <x-panel.nonce />
        <input type="hidden" name="enabled" value="0">
        <x-panel.button type="submit" variant="secondary">Wyłącz harmonogram</x-panel.button>
      </form>
    @else
      <form method="post" action="{{ $base }}/schedule" class="mt-4 space-y-3">
        <x-panel.nonce />
        <input type="hidden" name="enabled" value="1">
        <label class="flex items-start gap-2 text-sm">
          <input type="checkbox" name="confirm" value="1" required class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
          <span>Rozumiem, że harmonogram wysyła płatne żądania do DataForSEO (szacunkowo do {{ Format::usd($monthly, 2) }} miesięcznie, w ramach wspólnych limitów).</span>
        </label>
        <x-panel.button type="submit">Włącz harmonogram</x-panel.button>
      </form>
    @endif
  </x-panel.card>

  @if ($competitors !== [])
    <x-panel.card class="mt-6">
      <h2 class="text-base font-semibold text-slate-900">Warianty marki konkurentów</h2>
      <p class="mt-1 text-xs text-slate-500">Frazy markowe konkurenta (np. „nazwa firmy opinie”) nie są lukami. Nazwa i domena są rozpoznawane automatycznie przy intencji nawigacyjnej albo zapisie łącznym; tutaj dopisz inne warianty — pasują zawsze.</p>
      <div class="mt-4 grid gap-4 md:grid-cols-2">
        @foreach ($competitors as $entry)
          <form method="post" action="{{ $base }}/competitors/{{ rawurlencode((string) $entry['competitor_public_id']) }}/brand" class="rounded-md border border-slate-200 p-3">
            <x-panel.nonce />
            <label for="brand-{{ $entry['competitor_public_id'] }}" class="block text-sm font-medium text-slate-700">{{ $entry['label'] }} <span class="font-normal text-slate-500">{{ $entry['domain'] }}</span></label>
            <textarea id="brand-{{ $entry['competitor_public_id'] }}" name="brand_terms" rows="2" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">{{ $entry['brand_terms'] }}</textarea>
            <p class="mt-1 text-xs text-slate-500">Automatycznie: {{ implode(', ', BrandMatcher::build([$entry['domain']], [$entry['label']])->labels()) }}</p>
            <x-panel.button type="submit" variant="secondary" class="mt-2">Zapisz warianty</x-panel.button>
          </form>
        @endforeach
      </div>
    </x-panel.card>
  @endif

  <div class="mt-8 space-y-1 text-xs text-slate-500">
    <p>Progi analizy z konfiguracji serwera: okno GSC {{ $config['window_days'] }} dni, min. {{ $config['min_impressions'] }} wyświetleń, „widoczna” = pozycja ≤ {{ Format::number($config['visible_position']) }}, pomiar SERP uznawany przez {{ $config['serp_fresh_days'] }} dni, świeżość zbiorów {{ $config['ttl_days'] }} dni, grupowanie do {{ Format::number($config['max_cluster_keywords']) }} fraz.</p>
  </div>
@endsection
