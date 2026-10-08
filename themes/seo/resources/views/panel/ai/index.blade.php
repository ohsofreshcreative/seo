@extends('panel.layouts.app', ['active' => 'ai'])

@section('title', 'Analizy AI · ' . $project->name)

@php
  use App\Http\Controllers\Panel\AiController;
  use App\Http\Controllers\Panel\StrategyTopicsController;
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Ai\Analysis\AnalysisType;
  use OsfSeo\Ai\Workspace\ReportLabels;

  $projectId = $project->publicId;
  $base = PanelUrl::project($projectId, 'ai');
  $manage = $history['manage'];
  $query = static fn (array $changes) => $base . '?' . http_build_query(array_filter(array_merge($filters, $changes), static fn ($value) => $value !== null && $value !== ''));
  $types = [...AnalysisType::RECOMMENDATIONS, AnalysisType::TOPIC_ANALYSIS];
@endphp

@section('content')
  <x-panel.page-header title="Analizy AI" :description="$manage
    ? 'Historia analiz projektu. Nową analizę przygotujesz w szczegółach tematu Strategii (najpierw plan i koszt).'
    : 'Gotowe analizy AI tematów Strategii przygotowane przez zespół agencji.'">
    <x-slot:actions>
      <x-panel.button variant="secondary" :href="PanelUrl::project($projectId, 'strategy/topics')">Tematy Strategii</x-panel.button>
    </x-slot:actions>
  </x-panel.page-header>

  <form method="get" action="{{ $base }}" class="mb-4 flex flex-wrap items-end gap-3">
    @if ($filters['topic'] ?? null)
      <input type="hidden" name="topic" value="{{ $filters['topic'] }}">
    @endif
    <label class="text-sm">
      <span class="block text-xs text-slate-500">Rodzaj</span>
      <select name="type" class="mt-1 rounded-md border-slate-300 text-sm">
        <option value="">Wszystkie</option>
        @foreach ($types as $type)
          <option value="{{ $type }}" @selected(($filters['type'] ?? null) === $type)>{{ ReportLabels::type($type) }}</option>
        @endforeach
      </select>
    </label>
    @if ($manage)
      <label class="text-sm">
        <span class="block text-xs text-slate-500">Status</span>
        <select name="status" class="mt-1 rounded-md border-slate-300 text-sm">
          <option value="">Wszystkie</option>
          <option value="ready" @selected(($filters['status'] ?? null) === 'ready')>Gotowe</option>
          <option value="active" @selected(($filters['status'] ?? null) === 'active')>W kolejce / w trakcie</option>
          <option value="problem" @selected(($filters['status'] ?? null) === 'problem')>Nieudane i niepewne</option>
        </select>
      </label>
    @endif
    <x-panel.button type="submit" variant="secondary">Filtruj</x-panel.button>
    @if ($history['topic'] !== null)
      <span class="inline-flex items-center gap-2 rounded-md bg-brand-50 px-2 py-1 text-xs text-brand-800">
        Temat: {{ $history['topic']['label'] ?? '—' }}
        <a href="{{ $query(['topic' => null, 'page' => null]) }}" class="font-semibold" aria-label="Usuń filtr tematu">×</a>
      </span>
    @endif
  </form>

  @if ($history['rows'] === [])
    <x-panel.empty-state :title="$manage ? 'Brak analiz' : 'Brak gotowych analiz'"
      :description="$manage ? 'Otwórz temat w Strategii i wybierz „Przygotuj analizę AI”. Koszt zobaczysz przed zleceniem.' : 'Gdy zespół przygotuje analizę tematu, pojawi się tutaj.'">
      @if ($manage)
        <x-panel.button :href="PanelUrl::project($projectId, 'strategy/topics')">Przejdź do tematów</x-panel.button>
      @endif
    </x-panel.empty-state>
  @else
    <x-panel.card class="p-0 sm:p-0">
      {{-- Telefon: lista; od md: tabela. --}}
      <ul class="divide-y divide-slate-100 md:hidden">
        @foreach ($history['rows'] as $row)
          <li class="px-4 py-3 text-sm">
            <a href="{{ AiController::runUrl($projectId, $row['id']) }}" class="font-medium text-brand-600 hover:underline">{{ $row['type_label'] }}</a>
            <p class="mt-0.5 break-words text-slate-700">{{ $row['topic']['label'] ?? 'Temat usunięty' }}</p>
            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
              <x-panel.ai-status :status="$row['status']" :decision="$row['decision']" />
              <span>{{ Format::datetime($row['created_at']) }}</span>
              @if ($row['strategy_changed'] === true)<span class="text-amber-800">Strategia zmieniła się</span>@endif
              @if ($manage)<span>{{ $row['paid'] ? Format::usd($row['cost'], 4) : 'test, 0 USD' }}</span>@endif
            </div>
          </li>
        @endforeach
      </ul>
      <div class="hidden md:block">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-4 py-3">Temat</th>
              <th class="px-4 py-3">Rodzaj</th>
              <th class="px-4 py-3">Data</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3" title="Szybki wskaźnik: czy dowody Strategii tematu zmieniły się od analizy. Dokładną aktualność (także treść stron) pokazuje raport.">Strategia od analizy</th>
              @if ($manage)
                <th class="px-4 py-3">Dostawca</th>
                <th class="px-4 py-3 text-right">Koszt</th>
              @endif
              <th class="px-4 py-3"><span class="sr-only">Raport</span></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach ($history['rows'] as $row)
              <tr>
                <td class="max-w-xs px-4 py-3">
                  @if ($row['topic'] !== null)
                    <a href="{{ StrategyTopicsController::topicUrl($projectId, $row['topic']['id']) }}" class="break-words text-slate-800 hover:underline">{{ $row['topic']['label'] ?? '—' }}</a>
                  @else
                    <span class="text-slate-400">Temat usunięty</span>
                  @endif
                </td>
                <td class="px-4 py-3 text-slate-700">{{ $row['type_label'] }}@if ($manage && $row['explicit'])<span class="block text-xs text-amber-800">świadomy wybór</span>@endif</td>
                <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ Format::datetime($row['created_at']) }}</td>
                <td class="px-4 py-3">
                  <x-panel.ai-status :status="$row['status']" :decision="$row['decision']" />
                  @if ($manage && $row['error'] !== null)<span class="mt-0.5 block max-w-xs text-xs text-slate-500">{{ $row['error'] }}</span>@endif
                </td>
                <td class="px-4 py-3 text-xs">
                  @if ($row['strategy_changed'] === true)
                    <span class="text-amber-800">Zmieniła się</span>
                  @elseif ($row['strategy_changed'] === false)
                    <span class="text-slate-600">Bez zmian</span>
                  @else
                    <span class="text-slate-400">—</span>
                  @endif
                </td>
                @if ($manage)
                  <td class="px-4 py-3 text-xs text-slate-600">{{ $row['provider'] }}<span class="block text-slate-400">{{ $row['model'] }}</span></td>
                  <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-slate-700">{{ $row['paid'] ? Format::usd($row['cost'], 4) : '0 USD' }}</td>
                @endif
                <td class="px-4 py-3 text-right"><a href="{{ AiController::runUrl($projectId, $row['id']) }}" class="font-medium text-brand-600 hover:underline">{{ $row['ready'] ? 'Raport' : 'Szczegóły' }}</a></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </x-panel.card>

    @if ($history['pages'] > 1)
      <nav class="mt-4 flex items-center justify-between text-sm" aria-label="Strony historii">
        <span class="text-slate-500">Strona {{ $history['page'] }} z {{ $history['pages'] }} ({{ Format::number($history['total']) }} analiz)</span>
        <span class="flex gap-2">
          @if ($history['page'] > 1)
            <x-panel.button variant="secondary" :href="$query(['page' => $history['page'] - 1])">Poprzednia</x-panel.button>
          @endif
          @if ($history['page'] < $history['pages'])
            <x-panel.button variant="secondary" :href="$query(['page' => $history['page'] + 1])">Następna</x-panel.button>
          @endif
        </span>
      </nav>
    @endif
  @endif
@endsection
