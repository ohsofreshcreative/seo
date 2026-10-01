@extends('panel.layouts.app', ['active' => 'positions'])

@section('title', 'Ustawienia pozycji · ' . $project->name)

@php
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Market\CostBudget;
  use OsfSeo\Serp\SerpConfig;
  use OsfSeo\Serp\SerpDevice;
  use OsfSeo\Serp\SerpFrequency;

  $base = PanelUrl::project($project->publicId, 'positions');
  $enabling = $enabled && ! $settings->enabled;
@endphp

@section('content')
  <p class="mb-2 text-sm"><a href="{{ $base }}" class="text-brand-600 hover:underline">← Pozycje</a></p>
  <x-panel.page-header title="Ustawienia pomiarów pozycji"
    :description="$project->name . ' · automatyczne pomiary są płatne i domyślnie wyłączone; koszt zawsze widać przed włączeniem'" />

  @if (! $configured)
    <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">DataForSEO nie jest skonfigurowane — pomiarów nie można włączyć.</div>
  @endif
  @if ($paused !== null)
    <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Płatne wywołania DataForSEO są wstrzymane po błędzie konta do {{ Format::datetime($paused['until']) }}.</div>
  @endif
  @if ($settings->lastSkipReason !== null && $settings->lastSkipReason !== 'nothing_to_do')
    <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      Ostatni zaplanowany pomiar ({{ Format::datetime($settings->lastSkipAt) }}) został pominięty: {{ \OsfSeo\Serp\SerpRun::skipLabel($settings->lastSkipReason) }}.
      @if ($settings->retryAfter !== null) Kolejna próba po {{ Format::datetime($settings->retryAfter) }}. @endif
    </div>
  @endif

  <form method="get" action="{{ $base }}/settings" class="grid gap-6 lg:grid-cols-2" x-data>
    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Harmonogram</h2>
      <label class="mt-4 flex items-start gap-2 text-sm">
        <input type="checkbox" name="enabled" value="1" @checked($enabled) @change="$el.form.submit()" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
        <span>Automatyczne pomiary <span class="block text-xs text-slate-500">Wszystkie monitorowane frazy w wybranym rytmie. Wyłączone = bez kosztów (pomiar ręczny nadal dostępny).</span></span>
      </label>

      <fieldset class="mt-4">
        <legend class="text-sm font-medium text-slate-700">Częstotliwość</legend>
        <div class="mt-2 flex flex-wrap gap-3 text-sm">
          @foreach (SerpFrequency::cases() as $option)
            <label class="inline-flex items-center gap-2">
              <input type="radio" name="frequency" value="{{ $option->value }}" @checked($frequency === $option) @change="$el.form.submit()" class="border-slate-300 text-brand-600 focus:ring-brand-500">
              {{ $option->label() }}
            </label>
          @endforeach
        </div>
      </fieldset>

      <fieldset class="mt-4">
        <legend class="text-sm font-medium text-slate-700">Urządzenie</legend>
        <div class="mt-2 flex flex-wrap gap-3 text-sm">
          @foreach (SerpDevice::cases() as $option)
            <label class="inline-flex items-center gap-2">
              <input type="radio" name="device" value="{{ $option->value }}" @checked($device === $option) @change="$el.form.submit()" class="border-slate-300 text-brand-600 focus:ring-brand-500">
              {{ $option->label() }}
            </label>
          @endforeach
        </div>
      </fieldset>

      <div class="mt-4">
        <label for="depth" class="block text-sm font-medium text-slate-700">Głębokość pomiaru</label>
        <select id="depth" name="depth" @change="$el.form.submit()" class="mt-1 block w-48 rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
          @foreach (SerpConfig::DEPTHS as $option)
            <option value="{{ $option }}" @selected($depth === $option)>TOP{{ $option }} ({{ Format::usd($pricing[$option], 5) }} / fraza)</option>
          @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">Zmiana urządzenia lub głębokości rozpoczyna nową serię pomiarów — zmian nie porównujemy z innymi ustawieniami.</p>
      </div>

      <noscript><div class="mt-4"><x-panel.button type="submit" variant="secondary">Przelicz koszt</x-panel.button></div></noscript>
    </x-panel.card>

    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Koszt (szacowany maksymalny)</h2>
      <dl class="mt-2 divide-y divide-slate-100 text-sm">
        <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-500">Monitorowane frazy</dt><dd class="font-medium tabular-nums text-slate-900">{{ Format::number($preview->tracked) }}</dd></div>
        <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-500">Koszt zadania (TOP{{ $depth }})</dt><dd class="font-medium tabular-nums text-slate-900">{{ Format::usd($preview->costPerTask, 5) }}</dd></div>
        <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-500">Jeden pełny pomiar</dt><dd class="font-medium tabular-nums text-slate-900">{{ Format::usd($preview->fullMeasurementCost(), 4) }}</dd></div>
        <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-500">Miesięcznie ({{ $frequency->label() }}, ≈ {{ Format::number($frequency->checksPerMonth(), 1) }} pomiaru)</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">{{ Format::usd($preview->monthlyCost(), 2) }}</dd></div>
        <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-500">Pozostały limit dziś / w miesiącu</dt><dd class="text-right tabular-nums text-slate-900">{{ Format::usd($preview->remainingToday(), 4) }} / {{ Format::usd($preview->remainingMonth(), 4) }}<span class="block text-xs text-slate-500">limity wspólne z danymi rynkowymi i Nowymi frazami</span></dd></div>
      </dl>
      @if ($preview->fullMeasurementCost() > $preview->budget['daily_limit'])
        <p class="mt-2 text-sm text-amber-700">Pełny pomiar ({{ Format::usd($preview->fullMeasurementCost(), 4) }}) przekracza dzienny limit kosztów ({{ Format::usd($preview->budget['daily_limit']) }}) — zaplanowane pomiary byłyby pomijane. Zmniejsz liczbę fraz lub głębokość albo podnieś limit (OSF_SEO_DATAFORSEO_DAILY_COST_LIMIT).</p>
      @endif
      <p class="mt-3 text-xs text-slate-500">To górny szacunek z cennika (pierwsza strona wyników + kolejne strony taniej). Rozstrzygający jest koszt zgłoszony przez DataForSEO — zapisujemy go po każdym zleceniu.</p>
    </x-panel.card>
  </form>

  <x-panel.card class="mt-6">
    <form method="post" action="{{ $base }}/settings" x-data="{ sending: false }" @submit="if (sending) { $event.preventDefault(); return; } sending = true">
      <x-panel.nonce />
      <input type="hidden" name="enabled" value="{{ $enabled ? '1' : '0' }}">
      <input type="hidden" name="frequency" value="{{ $frequency->value }}">
      <input type="hidden" name="device" value="{{ $device->value }}">
      <input type="hidden" name="depth" value="{{ $depth }}">
      <p class="text-sm text-slate-700">
        Do zapisania: <strong>{{ $enabled ? 'pomiary automatyczne ' . $frequency->label() : 'pomiary automatyczne wyłączone' }}</strong>, {{ $device->label() }}, TOP{{ $depth }}.
        @if ($settings->enabled && $settings->nextRunAt !== null) Obecnie najbliższy pomiar: {{ Format::datetime($settings->nextRunAt) }}. @endif
      </p>
      @if ($enabling)
        <label class="mt-3 flex items-start gap-2 text-sm">
          <input type="checkbox" name="confirm" value="1" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" required>
          <span>Rozumiem, że włączam płatne pomiary: do {{ Format::usd($preview->fullMeasurementCost(), 4) }} za pomiar, ok. {{ Format::usd($preview->monthlyCost(), 2) }} miesięcznie (w ramach wspólnych limitów). Pierwszy pomiar odbędzie się w tle wkrótce po zapisaniu.</span>
        </label>
      @endif
      @if (isset($errors['confirm']) || isset($errors['enabled']) || isset($errors['depth']))
        <p class="mt-2 text-sm text-red-700">{{ implode(' ', $errors) }}</p>
      @endif
      <div class="mt-4">
        <x-panel.button type="submit" x-bind:disabled="sending">Zapisz ustawienia</x-panel.button>
      </div>
    </form>
  </x-panel.card>
@endsection
