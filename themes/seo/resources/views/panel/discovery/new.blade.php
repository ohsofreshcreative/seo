@extends('panel.layouts.app', ['active' => 'discovery'])

@section('title', 'Znajdź nowe frazy · ' . $project->name)

@php
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Discovery\DiscoveryConfig;
  use OsfSeo\Discovery\DiscoveryPlan;
  use OsfSeo\Discovery\SeedList;
  use OsfSeo\Market\CostBudget;
  use OsfSeo\Opportunities\Text;

  $base = PanelUrl::project($project->publicId, 'discovery');
  $input = $request?->toInput() ?? [];
  $manual = $input['seeds'] ?? '';
  $chosen = [...($input['seeds_gsc'] ?? []), ...($input['seeds_opportunity'] ?? [])];
  $method = $input['method'] ?? 'related';
  $depth = (int) ($input['depth'] ?? DiscoveryConfig::DEFAULT_DEPTH);
  $maxCandidates = (int) ($input['max_candidates'] ?? DiscoveryConfig::DEFAULT_CANDIDATES);
  $minVolume = $input['min_volume'] ?? (string) $config->minVolume();
  $maxKd = $input['max_kd'] ?? '';
  $skipReasons = [
    DiscoveryPlan::UNSUPPORTED_MARKET => 'rynek projektu nie jest obsługiwany przez dostawcę',
    DiscoveryPlan::NOT_CONFIGURED => 'DataForSEO nie jest skonfigurowane (stałe w wp-config.php)',
    DiscoveryPlan::NO_SEEDS => 'podaj co najmniej jedną poprawną frazę startową',
  ];
  $opportunityLabels = ['near_top' => 'blisko TOP', 'weak_position' => 'słaba pozycja', 'low_ctr' => 'niski CTR'];
  $depthLabels = [1 => 'wąsko — do ok. 8 fraz na seed', 2 => 'standardowo — do ok. 72 fraz na seed', 3 => 'szeroko — do ok. 584 fraz na seed'];
@endphp

@section('content')
  <p class="mb-4 text-sm"><a href="{{ $base }}" class="text-brand-600 hover:underline">← Nowe frazy</a></p>

  <x-panel.page-header title="Znajdź nowe frazy"
    :description="$project->name . ' · ' . ($status['market'] ?? 'rynek nieobsługiwany') . ' · najpierw bezpłatny podgląd kosztu, płatne wyszukiwanie dopiero po potwierdzeniu'" />

  @if ($status['active'] !== null)
    <div class="mb-6">
      @include('panel.discovery.partials.progress', ['progress' => $status['active']])
    </div>
  @endif

  <form method="post" action="{{ $base }}/preview" class="grid gap-6 lg:grid-cols-3">
    <x-panel.nonce />

    <x-panel.card class="lg:col-span-2">
      <h2 class="text-base font-semibold text-slate-900">1. Frazy startowe (seedy)</h2>
      <p class="mt-1 text-sm text-slate-600">Ogólne frazy opisujące usługi lub tematy — np. nazwy usług. Po jednej w wierszu (albo po przecinku), maks. {{ $config->maxSeeds() }}.</p>
      <label for="seeds" class="sr-only">Seedy</label>
      <textarea id="seeds" name="seeds" rows="6" class="mt-3 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500" placeholder="np. projektowanie stron internetowych&#10;sklepy internetowe">{{ $manual }}</textarea>

      @if ($suggestions['gsc'] !== [] || $suggestions['opportunity'] !== [])
        <div class="mt-4">
          <p class="text-sm font-medium text-slate-700">Podpowiedzi z danych projektu <span class="font-normal text-slate-500">(bez kosztów)</span></p>
          <div class="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
            @foreach ($suggestions['gsc'] as $item)
              <label class="inline-flex items-start gap-2">
                <input type="checkbox" name="seeds_gsc[]" value="{{ $item['seed'] }}" @checked(in_array($item['seed'], $chosen, true)) class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                <span>{{ $item['seed'] }} <span class="block text-xs text-slate-500">GSC: {{ Format::number($item['clicks']) }} kliknięć, śr. pozycja (GSC) {{ Format::position($item['position']) }}</span></span>
              </label>
            @endforeach
            @foreach ($suggestions['opportunity'] as $item)
              <label class="inline-flex items-start gap-2">
                <input type="checkbox" name="seeds_opportunity[]" value="{{ $item['seed'] }}" @checked(in_array($item['seed'], $chosen, true)) class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                <span>{{ $item['seed'] }} <span class="block text-xs text-slate-500">Szansa SEO: {{ $opportunityLabels[$item['type']] ?? $item['type'] }}, priorytet {{ $item['priority'] }}</span></span>
              </label>
            @endforeach
          </div>
        </div>
      @endif
    </x-panel.card>

    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">2. Ustawienia</h2>
      <fieldset class="mt-3">
        <legend class="text-sm font-medium text-slate-700">Metoda</legend>
        @foreach ($methods as $option)
          <label class="mt-2 flex items-start gap-2 text-sm">
            <input type="radio" name="method" value="{{ $option->value }}" @checked($method === $option->value) class="mt-0.5 border-slate-300 text-brand-600 focus:ring-brand-500">
            <span>{{ $option->label() }} <span class="block text-xs text-slate-500">{{ $option->description() }}</span></span>
          </label>
        @endforeach
      </fieldset>
      <div class="mt-4">
        <label for="depth" class="block text-sm font-medium text-slate-700">Zasięg powiązanych fraz</label>
        <select id="depth" name="depth" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
          @foreach (DiscoveryConfig::DEPTHS as $option)
            <option value="{{ $option }}" @selected($depth === $option)>{{ $depthLabels[$option] }}</option>
          @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">Dotyczy metody „Powiązane frazy”.</p>
      </div>
      <div class="mt-4">
        <label for="max-candidates" class="block text-sm font-medium text-slate-700">Limit fraz w wyszukiwaniu</label>
        <select id="max-candidates" name="max_candidates" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
          @foreach ($config->candidateOptions() as $option)
            <option value="{{ $option }}" @selected($maxCandidates === $option)>{{ Format::number($option) }}</option>
          @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">Dzielony między seedy — ogranicza liczbę zwracanych fraz i koszt.</p>
      </div>
      <div class="mt-4 grid grid-cols-2 gap-3">
        <div>
          <label for="min-volume" class="block text-sm font-medium text-slate-700">Min. wolumen</label>
          <input id="min-volume" name="min_volume" type="number" min="0" value="{{ $minVolume }}" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        </div>
        <div>
          <label for="max-kd" class="block text-sm font-medium text-slate-700">Maks. trudność SEO</label>
          <input id="max-kd" name="max_kd" type="number" min="0" max="99" value="{{ $maxKd }}" placeholder="bez limitu" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        </div>
      </div>
      <label class="mt-4 flex items-start gap-2 text-sm">
        <input type="checkbox" name="other_language" value="1" @checked(($input['other_language'] ?? '') === '1') class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
        <span>Uwzględnij frazy w innym języku <span class="block text-xs text-slate-500">Domyślnie pomijane frazy, które dostawca rozpoznał jako inny język niż język rynku.</span></span>
      </label>
      <label class="mt-3 flex items-start gap-2 text-sm">
        <input type="checkbox" name="force" value="1" @checked(($input['force'] ?? '') === '1') class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
        <span>Pobierz ponownie <span class="block text-xs text-slate-500">Seedy sprawdzone w ostatnich {{ $config->ttlDays() }} dniach są domyślnie pomijane (bez kosztu).</span></span>
      </label>
      <div class="mt-5">
        <x-panel.button type="submit" variant="secondary">Sprawdź koszt</x-panel.button>
        <p class="mt-1 text-xs text-slate-500">Podgląd jest bezpłatny — nic nie zostanie wysłane do DataForSEO.</p>
      </div>
    </x-panel.card>
  </form>

  @if ($plan !== null)
    <x-panel.card class="mt-6">
      <h2 class="text-base font-semibold text-slate-900">3. Podgląd kosztu</h2>

      @if ($plan->rejected !== [])
        <ul class="mt-2 space-y-0.5 text-sm text-amber-800">
          @foreach ($plan->rejected as $rejected)
            <li>Pominięto „{{ $rejected['seed'] }}” — {{ SeedList::reasonLabel($rejected['reason']) }}.</li>
          @endforeach
        </ul>
      @endif

      @if ($plan->skipReason !== null && $plan->skipReason !== DiscoveryPlan::NOT_CONFIGURED)
        <p class="mt-2 text-sm text-amber-700">Nie można uruchomić: {{ $skipReasons[$plan->skipReason] ?? $plan->skipReason }}.</p>
      @else
        <dl class="mt-3 grid grid-cols-2 gap-4 text-sm md:grid-cols-5">
          <div><dt class="text-slate-500">Rynek</dt><dd class="mt-1 font-medium text-slate-900">{{ $plan->market?->label() }}</dd></div>
          <div><dt class="text-slate-500">Seedy</dt><dd class="mt-1 font-medium tabular-nums text-slate-900">{{ count($plan->seeds) }}@if ($plan->cachedSeeds() > 0) <span class="font-normal text-slate-500">({{ $plan->cachedSeeds() }} z cache)</span>@endif</dd></div>
          <div><dt class="text-slate-500">Żądania do API</dt><dd class="mt-1 font-medium tabular-nums text-slate-900">{{ Format::number($plan->requests()) }}</dd></div>
          <div><dt class="text-slate-500">Maks. liczba fraz</dt><dd class="mt-1 font-medium tabular-nums text-slate-900">{{ Format::number($plan->maxItems()) }}</dd></div>
          <div><dt class="text-slate-500">Maksymalny koszt</dt><dd class="mt-1 text-lg font-semibold tabular-nums text-slate-900">{{ Format::usd($plan->estimatedCost(), 4) }}</dd></div>
        </dl>
        <p class="mt-3 text-sm text-slate-600">
          Pozostały limit (wspólny z danymi rynkowymi): dziś {{ Format::usd($plan->remainingToday(), 4) }}, w tym miesiącu {{ Format::usd($plan->remainingMonth(), 4) }}.
          Koszt rzeczywisty bywa niższy — DataForSEO nalicza opłatę za żądanie i za każdą zwróconą frazę.
        </p>

        <div class="mt-4 overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                <th class="py-2 pr-4 font-medium">Seed</th>
                <th class="py-2 pr-4 font-medium">Źródło</th>
                <th class="py-2 pr-4 text-right font-medium">Żądania</th>
                <th class="py-2 pr-4 text-right font-medium">Maks. frazy</th>
                <th class="py-2 text-right font-medium">Maks. koszt</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 tabular-nums">
              @foreach ($plan->seeds as $seed)
                <tr>
                  <td class="py-2 pr-4 text-slate-900">{{ $seed->seed }}</td>
                  <td class="py-2 pr-4 text-slate-600">{{ ['manual' => 'ręcznie', 'gsc' => 'GSC', 'opportunity' => 'szansa SEO'][$seed->source] ?? $seed->source }}</td>
                  @if ($seed->isCached())
                    <td colspan="3" class="py-2 text-right text-slate-500">sprawdzony {{ Format::datetime($seed->cachedAt) }} — bez kosztu</td>
                  @else
                    <td class="py-2 pr-4 text-right">{{ $seed->requests }}</td>
                    <td class="py-2 pr-4 text-right">{{ Format::number($seed->maxItems) }}</td>
                    <td class="py-2 text-right">{{ Format::usd($seed->estimatedCost, 4) }}</td>
                  @endif
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>

        @if ($plan->skipReason === DiscoveryPlan::NOT_CONFIGURED)
          <p class="mt-4 text-sm text-amber-700">Uruchomienie niedostępne: {{ $skipReasons[DiscoveryPlan::NOT_CONFIGURED] }}.</p>
        @elseif ($status['active'] !== null)
          <p class="mt-4 text-sm text-amber-700">W tym projekcie trwa już wyszukiwanie — poczekaj na jego zakończenie.</p>
        @elseif ($plan->requests() === 0)
          <p class="mt-4 text-sm text-slate-600">Wszystkie seedy były sprawdzone niedawno — nie ma czego wysyłać. Zaznacz „Pobierz ponownie”, aby odświeżyć wyniki.</p>
        @elseif ($plan->blockedBy() !== null)
          <p class="mt-4 text-sm text-amber-700">Maksymalny koszt przekracza pozostały limit ({{ CostBudget::label((string) $plan->blockedBy()) }}). Zmniejsz limit fraz lub liczbę seedów.</p>
        @else
          <form method="post" action="{{ $base }}/runs" class="mt-4"
            x-data="{ sending: false }"
            @submit="if (sending || ! confirm(@js('Wysłać ' . $plan->requests() . ' ' . Text::plural($plan->requests(), 'płatne żądanie', 'płatne żądania', 'płatnych żądań') . ' do DataForSEO? Maksymalny koszt: ' . Format::usd($plan->estimatedCost(), 4) . '.'))) { $event.preventDefault(); return; } sending = true">
            <x-panel.nonce />
            @foreach ($input as $name => $value)
              @if (is_array($value))
                @foreach ($value as $item)
                  <input type="hidden" name="{{ $name }}[]" value="{{ $item }}">
                @endforeach
              @else
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
              @endif
            @endforeach
            <input type="hidden" name="expected_requests" value="{{ $plan->requests() }}">
            <input type="hidden" name="expected_cost" value="{{ sprintf('%.6F', $plan->estimatedCost()) }}">
            <x-panel.button type="submit" x-bind:disabled="sending">Uruchom wyszukiwanie</x-panel.button>
            <span class="ml-2 text-xs text-slate-500">Wyszukiwanie działa w tle — wyniki pojawią się na liście „Nowe frazy”.</span>
          </form>
        @endif
      @endif
    </x-panel.card>
  @endif

  <div class="mt-6 space-y-1 text-xs text-slate-500">
    <p><strong class="font-medium text-slate-600">Powiązane frazy</strong> — wyszukiwania powiązane z seedem w Google (DataForSEO Labs Related Keywords). <strong class="font-medium text-slate-600">Frazy zawierające seed</strong> — długi ogon z bazy dostawcy (Keyword Suggestions).</p>
    <p>Każda znaleziona fraza dostaje od razu wolumen, trudność SEO, CPC, konkurencję Ads i intencję z tej samej odpowiedzi — bez dodatkowych płatnych zapytań. Frazy, które strona już pokrywa w GSC (TOP 10), są oznaczane jako „Już widoczne”.</p>
  </div>
@endsection
