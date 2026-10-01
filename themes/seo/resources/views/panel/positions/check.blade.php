@extends('panel.layouts.app', ['active' => 'positions'])

@section('title', 'Sprawdź pozycje · ' . $project->name)

@php
  use App\Http\Controllers\Panel\PositionsController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Market\CostBudget;
  use OsfSeo\Opportunities\Text;
  use OsfSeo\Serp\SerpPlan;

  $base = PanelUrl::project($project->publicId, 'positions');
  $skipReasons = [
    SerpPlan::NOT_CONFIGURED => 'DataForSEO nie jest skonfigurowane (stałe w wp-config.php)',
    SerpPlan::UNSUPPORTED_MARKET => 'rynek projektu nie jest obsługiwany — zmień kraj i język projektu',
    SerpPlan::NO_KEYWORDS => 'projekt nie ma monitorowanych fraz',
    SerpPlan::NOTHING_TO_DO => 'wszystkie wybrane frazy były zlecone w ciągu ostatnich godzin — nie ma czego sprawdzać',
  ];
@endphp

@section('content')
  <p class="mb-2 text-sm"><a href="{{ $base }}" class="text-brand-600 hover:underline">← Pozycje</a></p>
  <x-panel.page-header title="Sprawdź pozycje teraz"
    :description="$project->name . ' · podgląd jest bezpłatny — nic nie zostanie wysłane do DataForSEO bez potwierdzenia'" />

  @if ($active !== null)
    <div class="mb-6">
      @include('panel.positions.partials.progress', ['progress' => $active])
    </div>
  @endif

  <x-panel.card>
    <h2 class="text-base font-semibold text-slate-900">Podgląd pomiaru</h2>

    @if ($plan->skipReason !== null)
      <p class="mt-2 text-sm text-amber-700">Nie można uruchomić: {{ $skipReasons[$plan->skipReason] ?? $plan->skipReason }}.</p>
      @if ($plan->recent > 0)
        <p class="mt-1 text-sm text-slate-600">Pominięto {{ Format::number($plan->recent) }} {{ Text::plural($plan->recent, 'frazę', 'frazy', 'fraz') }} zleconych niedawno (ochrona przed podwójnym pomiarem).</p>
      @endif
    @else
      <dl class="mt-3 grid grid-cols-2 gap-4 text-sm md:grid-cols-6">
        <div><dt class="text-slate-500">Rynek</dt><dd class="mt-1 font-medium text-slate-900">{{ $plan->market?->label() }}</dd></div>
        <div><dt class="text-slate-500">Urządzenie / głębokość</dt><dd class="mt-1 font-medium text-slate-900">{{ $plan->context?->device->label() }}, TOP{{ $plan->context?->depth }}</dd></div>
        <div><dt class="text-slate-500">Frazy</dt><dd class="mt-1 font-medium tabular-nums text-slate-900">{{ Format::number($plan->tasks()) }}@if ($only !== null) <span class="font-normal text-slate-500">(zaznaczone)</span>@endif</dd></div>
        <div><dt class="text-slate-500">Zadania / zlecenia</dt><dd class="mt-1 font-medium tabular-nums text-slate-900">{{ Format::number($plan->tasks()) }} / {{ Format::number($plan->posts()) }}</dd></div>
        <div><dt class="text-slate-500">Koszt zadania (maks.)</dt><dd class="mt-1 font-medium tabular-nums text-slate-900">{{ Format::usd($plan->costPerTask, 5) }}</dd></div>
        <div><dt class="text-slate-500">Szacowany maksymalny koszt</dt><dd class="mt-1 text-lg font-semibold tabular-nums text-slate-900">{{ Format::usd($plan->estimatedCost(), 4) }}</dd></div>
      </dl>
      <p class="mt-3 text-sm text-slate-600">
        Pozostały limit (wspólny z danymi rynkowymi i Nowymi frazami): dziś {{ Format::usd($plan->remainingToday(), 4) }}, w tym miesiącu {{ Format::usd($plan->remainingMonth(), 4) }}.
        Rozstrzygający jest koszt zgłoszony przez DataForSEO po wykonaniu — trafi do rejestru kosztów.
        @if ($plan->recent > 0) Pominięto {{ Format::number($plan->recent) }} {{ Text::plural($plan->recent, 'frazę', 'frazy', 'fraz') }} zleconych niedawno. @endif
      </p>

      @if ($paused !== null)
        <p class="mt-4 text-sm text-amber-700">Płatne wywołania DataForSEO są wstrzymane po błędzie konta (do {{ Format::datetime($paused['until']) }}) — sprawdź stronę „Dane rynkowe”.</p>
      @elseif ($plan->blockedBy() !== null)
        <p class="mt-4 text-sm text-amber-700">Pomiar przekracza wspólny limit kosztów ({{ CostBudget::label((string) $plan->blockedBy()) }}). Wybierz mniej fraz albo poczekaj na odnowienie limitu — nie wykonujemy częściowych pomiarów.</p>
      @else
        <form method="post" action="{{ PositionsController::checkUrl($project->publicId) }}" class="mt-4"
          x-data="{ sending: false }"
          @submit="if (sending || ! confirm(@js('Zlecić ' . $plan->tasks() . ' ' . Text::plural($plan->tasks(), 'płatne zadanie', 'płatne zadania', 'płatnych zadań') . ' SERP (TOP' . $plan->context?->depth . ') w DataForSEO? Szacowany maksymalny koszt: ' . Format::usd($plan->estimatedCost(), 4) . '.'))) { $event.preventDefault(); return; } sending = true">
          <x-panel.nonce />
          @foreach ($only ?? [] as $id)
            <input type="hidden" name="ids[]" value="{{ $id }}">
          @endforeach
          <input type="hidden" name="expected_tasks" value="{{ $plan->tasks() }}">
          <input type="hidden" name="expected_cost" value="{{ sprintf('%.6F', $plan->estimatedCost()) }}">
          <x-panel.button type="submit" x-bind:disabled="sending">Zleć pomiar ({{ Format::usd($plan->estimatedCost(), 4) }} maks.)</x-panel.button>
          <span class="ml-2 text-xs text-slate-500">Pomiar działa w tle — wyniki zwykle w ciągu kilku do kilkudziesięciu minut. Ponowne uruchomienie możliwe po 15 minutach.</span>
        </form>
      @endif
    @endif
  </x-panel.card>

  <div class="mt-6 space-y-1 text-xs text-slate-500">
    <p>Kolejka Standard DataForSEO (bez priorytetu i bez płatnych dodatków). Jedno zadanie = jedna fraza, pełne wyniki organiczne do wybranej głębokości (TOP{{ $plan->context?->depth ?? 100 }} = {{ $plan->context?->pages() ?? 10 }} {{ Text::plural($plan->context?->pages() ?? 10, 'strona', 'strony', 'stron') }} wyników).</p>
    <p>Frazy zlecone w ciągu ostatnich godzin (np. przez harmonogram) są pomijane — ta sama fraza nie zostanie opłacona dwa razy.</p>
  </div>
@endsection
