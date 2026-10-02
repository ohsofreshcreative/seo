@extends('panel.layouts.app', ['active' => 'gaps'])

@section('title', 'Import fraz konkurentów · ' . $project->name)

@php
  use App\Http\Controllers\Panel\GapsController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Gap\GapConfig;
  use OsfSeo\Gap\GapPlan;
  use OsfSeo\Gap\GapRun;
  use OsfSeo\Gap\PlannedTarget;
  use OsfSeo\Opportunities\Text;

  $base = PanelUrl::project($project->publicId, 'gaps');
  $coverage = $request->coverage;
  $preset = 'custom';

  foreach (GapConfig::PRESETS as $name => [$rank, $volume, $rows]) {
    if ($coverage->maxRank === $rank && $coverage->minVolume === $volume && $coverage->maxRows === $rows) {
      $preset = $name;
    }
  }

  $presets = [
    'quick' => ['Szybki', 'TOP10, wolumen ≥ 50, do 2 000 fraz na domenę — najtaniej, tylko najmocniejsze frazy'],
    'standard' => ['Standardowy (zalecany)', 'TOP30, wolumen ≥ 10, do 10 000 fraz na domenę'],
    'full' => ['Pełny', 'TOP100, każdy wolumen, do 10 000 fraz na domenę — najdrożej'],
    'custom' => ['Własny zakres', 'wybierz próg pozycji, minimalny wolumen i limit fraz'],
  ];
  $selected = $request->competitors;
  $skipReasons = [
    GapPlan::UNSUPPORTED_MARKET => 'rynek projektu nie jest obsługiwany przez dostawcę',
    GapPlan::NO_COMPETITORS => 'brak aktywnych konkurentów (dodaj ich w module „Konkurenci”)',
    GapPlan::NO_TARGETS => 'nie wybrano żadnej domeny',
  ];
  $skippedReasons = ['invalid_domain' => 'nieprawidłowa domena', 'project_domain' => 'to domena projektu', 'duplicate_domain' => 'ta sama domena co inna pozycja'];
@endphp

@section('content')
  <p class="mb-4 text-sm"><a href="{{ $base }}" class="text-brand-600 hover:underline">← Luki SEO</a></p>

  <x-panel.page-header title="Import fraz konkurentów"
    :description="$project->name . ' · ' . ($market?->label() ?? 'rynek nieobsługiwany') . ' · DataForSEO Labs Ranked Keywords (wyniki organiczne) — najpierw bezpłatny podgląd kosztu, płatny import dopiero po potwierdzeniu'" />

  @if ($active !== null)
    <div class="mb-6">
      @include('panel.gaps.partials.progress', ['progress' => $active])
    </div>
  @endif

  @if ($paused !== null)
    <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      Płatne wywołania DataForSEO są wstrzymane po błędzie konta (logowanie lub środki) — sprawdź stronę <a href="{{ PanelUrl::project($project->publicId, 'market-data') }}" class="font-medium underline">Dane rynkowe</a>.
    </div>
  @endif

  <form method="post" action="{{ $base }}/preview" class="grid gap-6 lg:grid-cols-3" x-data="{ preset: @js($preset) }">
    <x-panel.nonce />

    <x-panel.card class="lg:col-span-2">
      <h2 class="text-base font-semibold text-slate-900">1. Domeny</h2>
      @if ($competitors === [])
        <p class="mt-2 text-sm text-amber-700">Brak aktywnych konkurentów — dodaj ich w module <a href="{{ PanelUrl::project($project->publicId, 'competitors') }}" class="underline">Konkurenci</a>.</p>
      @else
        <div class="mt-3 space-y-2 text-sm">
          @foreach ($competitors as $entry)
            <label class="flex items-start gap-2">
              <input type="checkbox" name="competitors[]" value="{{ $entry['competitor_public_id'] }}" @checked($selected === [] || in_array($entry['competitor_public_id'], $selected, true)) class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
              <span>{{ $entry['label'] }} <span class="text-slate-500">{{ $entry['domain'] }}</span>
                <span class="block text-xs text-slate-500">{{ $entry['status_label'] }}@if ($entry['dataset'] !== null && $entry['dataset']['imported_at'] !== null) · import {{ Format::datetime($entry['dataset']['imported_at']) }}{{ $entry['fresh'] ? ' (świeży — z pamięci, bez kosztu, jeśli obejmuje zakres)' : ' (do odświeżenia)' }}@endif</span>
              </span>
            </label>
          @endforeach
        </div>
      @endif
      <label class="mt-4 flex items-start gap-2 text-sm">
        <input type="hidden" name="baseline" value="0">
        <input type="checkbox" name="baseline" value="1" @checked($request->baseline) class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
        <span>Punkt odniesienia: frazy domeny projektu ({{ $project->domain }}, TOP100)
          <span class="block text-xs text-slate-500">Pozwala odróżnić „brak widoczności” od „nieznanej” dla fraz bez danych w GSC i bez pomiaru SERP. Bez niego takie frazy pozostaną „Nieznane”.</span></span>
      </label>
    </x-panel.card>

    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">2. Zakres</h2>
      <fieldset class="mt-3 space-y-2">
        <legend class="sr-only">Zakres importu</legend>
        @foreach ($presets as $value => [$label, $description])
          <label class="flex items-start gap-2 text-sm">
            <input type="radio" name="preset" value="{{ $value }}" x-model="preset" class="mt-0.5 border-slate-300 text-brand-600 focus:ring-brand-500">
            <span>{{ $label }} <span class="block text-xs text-slate-500">{{ $description }}</span></span>
          </label>
        @endforeach
      </fieldset>
      <div class="mt-3 grid grid-cols-3 gap-2" x-show="preset === 'custom'" x-cloak>
        <div>
          <label for="max-rank" class="block text-xs font-medium text-slate-700">Pozycje</label>
          <select id="max-rank" name="max_rank" x-bind:disabled="preset !== 'custom'" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            @foreach (GapConfig::FETCH_RANKS as $rank)
              <option value="{{ $rank }}" @selected($coverage->maxRank === $rank)>TOP{{ $rank }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label for="min-volume" class="block text-xs font-medium text-slate-700">Min. wolumen</label>
          <input id="min-volume" name="min_volume" type="number" min="0" max="100000" value="{{ $coverage->minVolume }}" x-bind:disabled="preset !== 'custom'" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        </div>
        <div>
          <label for="max-rows" class="block text-xs font-medium text-slate-700">Maks. fraz</label>
          <input id="max-rows" name="max_rows" type="number" min="100" max="{{ $maxRows }}" step="100" value="{{ $coverage->maxRows }}" x-bind:disabled="preset !== 'custom'" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        </div>
      </div>
      <p class="mt-2 text-xs text-slate-500">Limit fraz na domenę: maks. {{ Format::number($maxRows) }} (najmocniejsze frazy wg wolumenu). Przy większych domenach import jest przycinany — opis ograniczenia w podglądzie.</p>
      <label class="mt-4 flex items-start gap-2 text-sm">
        <input type="checkbox" name="force" value="1" @checked($request->force) class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
        <span>Pobierz ponownie <span class="block text-xs text-slate-500">Świeże zbiory domen (do {{ $ttlDays }} dni) są domyślnie używane z pamięci — bez kosztu, także między projektami.</span></span>
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

      @foreach ($plan->skipped as $skipped)
        <p class="mt-2 text-sm text-amber-800">Pominięto {{ $skipped['label'] }} ({{ $skipped['domain'] }}) — {{ $skippedReasons[$skipped['reason']] ?? $skipped['reason'] }}.</p>
      @endforeach

      @if ($plan->skipReason !== null)
        <p class="mt-2 text-sm text-amber-700">Nie można uruchomić: {{ $skipReasons[$plan->skipReason] ?? $plan->skipReason }}.</p>
      @else
        <dl class="mt-3 grid grid-cols-2 gap-4 text-sm md:grid-cols-5">
          <div><dt class="text-slate-500">Zakres</dt><dd class="mt-1 font-medium text-slate-900">TOP{{ $plan->request->coverage->maxRank }}, wolumen ≥ {{ Format::number($plan->request->coverage->minVolume) }}</dd></div>
          <div><dt class="text-slate-500">Domeny do pobrania</dt><dd class="mt-1 font-medium tabular-nums text-slate-900">{{ count($plan->imports()) }} z {{ count($plan->targets) }}</dd></div>
          <div><dt class="text-slate-500">Żądania (maks.)</dt><dd class="mt-1 font-medium tabular-nums text-slate-900">{{ Format::number($plan->requests()) }}</dd></div>
          <div><dt class="text-slate-500">Oczekiwany koszt</dt><dd class="mt-1 font-medium tabular-nums text-slate-900">{{ Format::usd($plan->expectedCost(), 4) }}</dd></div>
          <div><dt class="text-slate-500">Maksymalny koszt</dt><dd class="mt-1 text-lg font-semibold tabular-nums text-slate-900">{{ Format::usd($plan->estimatedCost(), 4) }}</dd></div>
        </dl>
        <p class="mt-3 text-sm text-slate-600">
          Cena DataForSEO: {{ Format::usd($plan->requestPrice, 3) }} za żądanie + {{ Format::usd($plan->itemPrice, 5) }} za każdą zwróconą frazę (strona = do 1 000 fraz).
          Maksimum zakłada pełny limit fraz każdej domeny; koszt zgłoszony przez dostawcę rozstrzyga i zwykle jest niższy.
          Pozostały limit (wspólny dla wszystkich modułów DataForSEO): dziś {{ Format::usd($plan->remainingToday(), 4) }}, w tym miesiącu {{ Format::usd($plan->remainingMonth(), 4) }}.
        </p>

        <div class="mt-4 overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                <th class="py-2 pr-4 font-medium">Domena</th>
                <th class="py-2 pr-4 font-medium">Rola</th>
                <th class="py-2 pr-4 font-medium">Stan</th>
                <th class="py-2 pr-4 font-medium">Zakres</th>
                <th class="py-2 pr-4 text-right font-medium" title="Liczba fraz z ostatniego importu o tych samych filtrach">Znana liczba fraz</th>
                <th class="py-2 pr-4 text-right font-medium">Żądania (maks.)</th>
                <th class="py-2 pr-4 text-right font-medium">Oczekiwany</th>
                <th class="py-2 text-right font-medium">Maks. koszt</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 tabular-nums">
              @foreach ($plan->targets as $target)
                <tr>
                  <td class="py-2 pr-4 text-slate-900">{{ $target->label }} <span class="block text-xs text-slate-500">{{ $target->domain }}</span></td>
                  <td class="py-2 pr-4 text-slate-600">{{ $target->role === PlannedTarget::ROLE_PROJECT ? 'projekt' : 'konkurent' }}</td>
                  <td class="py-2 pr-4 text-slate-600">{{ $target->stateLabel() }}</td>
                  <td class="py-2 pr-4 text-xs text-slate-600">TOP{{ $target->coverage->maxRank }}, ≥ {{ Format::number($target->coverage->minVolume) }}, maks. {{ Format::number($target->coverage->maxRows) }}</td>
                  @if ($target->needsImport())
                    <td class="py-2 pr-4 text-right">{{ $target->knownTotal === null ? '—' : Format::number($target->knownTotal) }}</td>
                    <td class="py-2 pr-4 text-right">{{ $target->maxRequests }}</td>
                    <td class="py-2 pr-4 text-right">{{ Format::usd($target->expectedCost, 4) }}</td>
                    <td class="py-2 text-right">{{ Format::usd($target->maxCost, 4) }}</td>
                  @else
                    <td colspan="4" class="py-2 text-right text-slate-500">bez kosztu</td>
                  @endif
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>

        @if (! $configured)
          <p class="mt-4 text-sm text-amber-700">Uruchomienie niedostępne: DataForSEO nie jest skonfigurowane (stałe w wp-config.php).</p>
        @elseif ($active !== null)
          <p class="mt-4 text-sm text-amber-700">W tym projekcie trwa już import — poczekaj na jego zakończenie albo go anuluj.</p>
        @elseif ($plan->blockedBy() !== null)
          <p class="mt-4 text-sm text-amber-700">Nie można uruchomić ({{ GapRun::reasonLabel((string) $plan->blockedBy()) }}): oczekiwany koszt nie mieści się w pozostałym limicie. Zmniejsz zakres albo liczbę domen.</p>
        @elseif ($plan->requests() === 0)
          <form method="post" action="{{ $base }}/runs" class="mt-4">
            <x-panel.nonce />
            @foreach ($plan->request->toInput() as $name => $value)
              @if (is_array($value))
                @foreach ($value as $item)
                  <input type="hidden" name="{{ $name }}[]" value="{{ $item }}">
                @endforeach
              @else
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
              @endif
            @endforeach
            <input type="hidden" name="expected_requests" value="0">
            <input type="hidden" name="expected_cost" value="0">
            <p class="text-sm text-slate-600">Wszystkie domeny są świeże — nic nie trzeba pobierać.</p>
            <x-panel.button type="submit" variant="secondary" class="mt-2">Przelicz luki z pamięci (bez kosztów)</x-panel.button>
          </form>
        @else
          @if ($plan->spansDays())
            <p class="mt-4 text-sm text-amber-700">Oczekiwany koszt przekracza dzisiejszy pozostały limit — import będzie wstrzymywany i wznawiany automatycznie w kolejnych dniach (ok. {{ $plan->daysEstimate() ?? '?' }} {{ Text::plural($plan->daysEstimate() ?? 2, 'dzień', 'dni', 'dni') }}). Pobrane strony zostają.</p>
          @endif
          <form method="post" action="{{ $base }}/runs" class="mt-4"
            x-data="{ sending: false }"
            @submit="if (sending || ! confirm(@js('Wysłać do DataForSEO maks. ' . $plan->requests() . ' ' . Text::plural($plan->requests(), 'płatne żądanie', 'płatne żądania', 'płatnych żądań') . '? Maksymalny koszt: ' . Format::usd($plan->estimatedCost(), 4) . ' (oczekiwany ' . Format::usd($plan->expectedCost(), 4) . ').'))) { $event.preventDefault(); return; } sending = true">
            <x-panel.nonce />
            @foreach ($plan->request->toInput() as $name => $value)
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
            <x-panel.button type="submit" x-bind:disabled="sending">Uruchom import</x-panel.button>
            <span class="ml-2 text-xs text-slate-500">Import działa w tle, strona po stronie, pod wspólnymi limitami kosztów — wyniki pojawią się w „Lukach SEO”.</span>
          </form>
        @endif
      @endif
    </x-panel.card>
  @endif

  <div class="mt-6 space-y-1 text-xs text-slate-500">
    <p>Import pobiera frazy, na które domena rankuje organicznie w bazie DataForSEO Labs (migawka Google, nie nasz pomiar SERP), razem z wolumenem, trudnością SEO, CPC i intencją — bez dodatkowych płatnych zapytań. Zbiory domen są wspólne dla projektów na tym samym rynku.</p>
    <p>Ograniczenie: dostawca zwraca maks. {{ Format::number($maxRows) }} fraz na domenę (stronicowanie limit + offset). Dla większych domen pobieramy najmocniejsze frazy według wolumenu, a brak frazy poza pobranym zakresem nie jest traktowany jako brak widoczności.</p>
  </div>
@endsection
