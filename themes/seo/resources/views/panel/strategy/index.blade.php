@extends('panel.layouts.app', ['active' => 'strategy'])

@section('title', 'Strategia · ' . $project->name)

@php
  use App\Http\Controllers\Panel\StrategyTopicsController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use App\Panel\StrategyLabels;
  use OsfSeo\Opportunities\Text;
  use OsfSeo\Strategy\Decision\PriorityModel;
  use OsfSeo\Strategy\Decision\StrategyAction;

  $base = PanelUrl::project($project->publicId, 'strategy');
  $topicsUrl = fn (array $query = []) => $base . '/topics' . ($query === [] ? '' : '?' . http_build_query($query));
  $hasTopics = $counts['open'] + $counts['completed'] + (int) $counts['dismissed'] + $counts['inactive'] > 0;
  $eventText = static function (array $event): string {
    return match ($event['type']) {
      'action_changed', 'created' => $event['to'] !== null ? StrategyLabels::action($event['to']) : '',
      'status_changed' => StrategyLabels::status($event['from']) . ' → ' . StrategyLabels::status($event['to']),
      'serp_band_changed' => StrategyLabels::serpBand($event['from']) . ' → ' . StrategyLabels::serpBand($event['to']),
      'priority_band_changed', 'confidence_band_changed' => StrategyLabels::level($event['from']) . ' → ' . StrategyLabels::level($event['to']),
      default => '',
    };
  };
@endphp

@section('content')
  <x-panel.page-header title="Strategia"
    :description="$project->name . ' · backlog SEO łączący Szanse SEO, Nowe frazy, Luki SEO, GSC i SERP Intelligence — tematy, działania i priorytety do sprawdzenia'">
    <x-slot:actions>
      <x-panel.button variant="secondary" :href="$topicsUrl()">Backlog</x-panel.button>
      @if ($canManage && $state['supported'])
        <form method="post" action="{{ $base }}/refresh">
          <x-panel.nonce />
          <x-panel.button type="submit" variant="secondary">Zleć przeliczenie</x-panel.button>
        </form>
      @endif
    </x-slot:actions>
  </x-panel.page-header>

  @include('panel.strategy.partials.tabs', ['tab' => 'overview'])
  @include('panel.strategy.partials.state', ['back' => $base])

  @if (! $hasTopics)
    <x-panel.empty-state class="mt-6" title="Brak tematów Strategii"
      :description="$state['candidates']['active'] > 0
        ? 'Kandydaci są zapisani, ale tematy powstaną dopiero po przeliczeniu Strategii.'
        : 'Strategia zbiera kandydatów z GSC, Szans SEO, Nowych fraz, Luk SEO, Pozycji i wpisów ręcznych. Gdy moduły mają dane, przelicz Strategię (bez kosztów).'">
      @if ($canManage)
        <x-panel.button variant="secondary" :href="$base . '/settings'">Dodaj frazy ręcznie</x-panel.button>
      @endif
    </x-panel.empty-state>
  @else
    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <a href="{{ $topicsUrl(['include_monitor' => '1']) }}" class="block hover:opacity-90">
        <x-panel.stat label="Otwarte tematy" :value="Format::number($counts['open'])" hint="Nowe, do przeglądu, zaplanowane i w realizacji (z monitorowaniem)." />
      </a>
      <a href="{{ $topicsUrl(['min_priority' => PriorityModel::HIGH_BAND]) }}" class="block hover:opacity-90">
        <x-panel.stat label="Wysoki priorytet (≥ {{ PriorityModel::HIGH_BAND }})" :value="Format::number($counts['high'])" hint="Otwarte tematy wymagające działania (bez monitorowania)." />
      </a>
      <a href="{{ $topicsUrl(['serp' => 'nofresh']) }}" class="block hover:opacity-90">
        <x-panel.stat label="Bez świeżego pomiaru SERP" :value="Format::number($counts['no_fresh_serp'])" :hint="'W tym ' . Format::number($counts['serp_required']) . ' z działaniem „potrzebny świeży pomiar SERP”.'" />
      </a>
      <a href="{{ $topicsUrl(['changed' => '1', 'status' => 'all']) }}" class="block hover:opacity-90">
        <x-panel.stat label="Zmiana po decyzji" :value="Format::number($counts['changed'])" hint="Dowody zmieniły działanie albo stronę docelową po Twojej decyzji (także odrzucone i zrealizowane)." />
      </a>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
      <x-panel.card>
        <h2 class="text-base font-semibold text-slate-900">Działania (otwarte tematy)</h2>
        <ul class="mt-3 divide-y divide-slate-100 text-sm">
          @foreach (StrategyAction::cases() as $action)
            <li class="flex items-center justify-between gap-3 py-2">
              <a href="{{ $topicsUrl(['action' => $action->value]) }}" class="hover:underline"><x-panel.strategy-action :action="$action" /></a>
              <span class="tabular-nums text-slate-700">{{ Format::number($counts['actions'][$action->value] ?? 0) }}</span>
            </li>
          @endforeach
        </ul>
        <p class="mt-3 text-xs text-slate-500">
          <a href="{{ $topicsUrl(['status' => 'completed']) }}" class="text-brand-600 hover:underline">Zrealizowane: {{ Format::number($counts['completed']) }}</a>
          @if ($counts['dismissed'] !== null)
            · <a href="{{ $topicsUrl(['status' => 'dismissed']) }}" class="text-brand-600 hover:underline">Odrzucone: {{ Format::number($counts['dismissed']) }}</a>
          @endif
          @if ($counts['inactive'] > 0)
            · <a href="{{ $topicsUrl(['state' => 'inactive', 'status' => 'all']) }}" class="text-brand-600 hover:underline">Nieaktywne: {{ Format::number($counts['inactive']) }}</a>
          @endif
        </p>
      </x-panel.card>

      <x-panel.card class="lg:col-span-2">
        <div class="flex items-baseline justify-between gap-4">
          <h2 class="text-base font-semibold text-slate-900">Najwyższy priorytet — od czego zacząć</h2>
          <a href="{{ $topicsUrl() }}" class="text-sm text-brand-600 hover:underline">Cały backlog</a>
        </div>
        @if ($top === [])
          <p class="mt-3 text-sm text-slate-500">Brak otwartych tematów wymagających działania (monitorowanie, zrealizowane i odrzucone są poza tą listą).</p>
        @else
          <ul class="mt-3 divide-y divide-slate-100">
            @foreach ($top as $topic)
              <li class="flex items-center gap-3 py-2">
                <x-panel.score :value="$topic->priority" size="sm" />
                <div class="min-w-0 flex-1">
                  <a href="{{ StrategyTopicsController::topicUrl($project->publicId, $topic->publicId) }}" class="block truncate font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $topic->label }}</a>
                  <p class="truncate text-xs text-slate-500">{{ StrategyLabels::reason($topic->actionReason) }}</p>
                </div>
                <x-panel.strategy-confidence :level="$topic->confidenceLevel" compact class="hidden sm:inline-flex" />
                <x-panel.strategy-action :action="$topic->action" />
              </li>
            @endforeach
          </ul>
        @endif
      </x-panel.card>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2 lg:items-start">
      <x-panel.card>
        <h2 class="text-base font-semibold text-slate-900">Zmiany po decyzji</h2>
        @if ($attention === [])
          <p class="mt-3 text-sm text-slate-500">Brak tematów, w których dowody zmieniły działanie albo stronę docelową po decyzji.</p>
        @else
          <ul class="mt-3 divide-y divide-slate-100 text-sm">
            @foreach ($attention as $topic)
              <li class="flex flex-wrap items-center gap-2 py-2">
                <a href="{{ StrategyTopicsController::topicUrl($project->publicId, $topic->publicId) }}" class="min-w-0 flex-1 truncate font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $topic->label }}</a>
                <x-panel.topic-status :status="$topic->status" />
                <span class="text-xs text-slate-500">teraz:</span>
                <x-panel.strategy-action :action="$topic->action" />
              </li>
            @endforeach
          </ul>
        @endif
      </x-panel.card>

      <x-panel.card>
        <h2 class="text-base font-semibold text-slate-900">Ostatnie istotne zdarzenia</h2>
        @if ($events === [])
          <p class="mt-3 text-sm text-slate-500">Brak zdarzeń — pojawią się po przeliczeniach (nowe tematy, zmiany działań, statusów i pasm).</p>
        @else
          <ul class="mt-3 divide-y divide-slate-100 text-sm">
            @foreach ($events as $event)
              <li class="py-2">
                <a href="{{ StrategyTopicsController::topicUrl($project->publicId, $event['topic']) }}" class="font-medium text-slate-900 hover:text-brand-700 hover:underline">{{ $event['label'] ?? '—' }}</a>
                <span class="text-slate-600">· {{ StrategyLabels::event($event['type']) . (($text = $eventText($event)) !== '' ? ': ' . $text : '') }}</span>
                <span class="block text-xs text-slate-500">{{ Format::datetime($event['at']) }}</span>
              </li>
            @endforeach
          </ul>
        @endif
      </x-panel.card>
    </div>
  @endif

  <x-panel.card class="mt-6">
    <h2 class="text-base font-semibold text-slate-900">Aktualność źródeł</h2>
    <dl class="mt-3 grid gap-4 text-sm sm:grid-cols-2 xl:grid-cols-4">
      <div>
        <dt class="text-slate-500">Google Search Console</dt>
        <dd class="mt-1 text-slate-900">
          @if (! $freshness['gsc']['connected'])
            <span class="text-slate-500">nie połączono</span>
          @else
            dane do {{ Format::date($freshness['gsc']['newest_date']) }}
            <span class="block text-xs text-slate-500">synchronizacja {{ Format::datetime($freshness['gsc']['last_success_at']) }}</span>
          @endif
        </dd>
      </div>
      <div>
        <dt class="text-slate-500">Pomiary SERP (Pozycje i analizy)</dt>
        <dd class="mt-1 text-slate-900">{{ Format::datetime($freshness['serp']['last_checked_at']) }}</dd>
      </div>
      <div>
        <dt class="text-slate-500">DataForSEO Labs (Luki SEO)</dt>
        <dd class="mt-1 text-slate-900">
          import {{ Format::datetime($freshness['labs']['last_import_at']) }}
          <span class="block text-xs text-slate-500">przeliczenie luk {{ Format::datetime($freshness['labs']['recalculated_at']) }}</span>
        </dd>
      </div>
      <div>
        <dt class="text-slate-500">Przeliczenie Strategii</dt>
        <dd class="mt-1 text-slate-900">
          {{ Format::datetime($state['refreshed_at']) }}
          <span class="block text-xs text-slate-500">
            @if ($state['refreshed_at'] === null)
              jeszcze nie przeliczono
            @elseif ($state['up_to_date'])
              aktualne względem danych modułów
            @else
              dane modułów zmieniły się od tego czasu
            @endif
            · kandydaci: {{ Format::number($state['candidates']['active']) }}
          </span>
        </dd>
      </div>
    </dl>
  </x-panel.card>

  @include('panel.strategy.partials.disclaimer')
@endsection
