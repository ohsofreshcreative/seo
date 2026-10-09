@extends('panel.layouts.app', ['active' => 'ai'])

@section('title', $run['type_label'] . ' · Analizy AI · ' . $project->name)

@php
  use App\Http\Controllers\Panel\AiController;
  use App\Http\Controllers\Panel\StrategyTopicsController;
  use App\Panel\Format;
  use App\Panel\PageLabels;
  use App\Panel\PanelUrl;

  $projectId = $project->publicId;
  $runUrl = AiController::runUrl($projectId, $run['id']);
  $topicUrl = $topic === null ? null : StrategyTopicsController::topicUrl($projectId, $topic['id']);
  $r = $report;
@endphp

@section('content')
  <p class="mb-4 flex flex-wrap gap-x-4 gap-y-1 text-sm print:hidden">
    @if ($topicUrl !== null)
      <a href="{{ $topicUrl }}#analiza-ai" class="text-brand-600 hover:underline">← Temat: {{ $topic['label'] ?? '—' }}</a>
    @endif
    <a href="{{ PanelUrl::project($projectId, 'ai') }}" class="text-brand-600 hover:underline">Historia analiz</a>
  </p>

  <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
    <div class="min-w-0">
      <h1 class="break-words text-2xl font-semibold tracking-tight text-slate-900">{{ $run['type_label'] }}</h1>
      <p class="mt-1 break-words text-sm text-slate-600">Temat: {{ $topic['label'] ?? ($run['topic']['label'] ?? '—') }} · {{ $project->name }}</p>
      <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
        <x-panel.ai-status :status="$run['status']" :decision="$run['decision']" />
        <span>Zlecona {{ Format::datetime($run['created_at']) }}</span>
        @if ($run['finished_at'] !== null)
          <span>· gotowa {{ Format::datetime($run['finished_at']) }}</span>
        @endif
      </div>
    </div>
    @if ($r !== null)
      <div x-data="copyText" class="flex shrink-0 flex-wrap gap-2 print:hidden">
        <textarea x-ref="summary" hidden readonly>{{ $exports['summary'] }}</textarea>
        <textarea x-ref="recommendations" hidden readonly>{{ $exports['recommendations'] }}</textarea>
        <textarea x-ref="brief" hidden readonly>{{ $exports['brief'] }}</textarea>
        <x-panel.button variant="secondary" x-on:click="copy('summary')"><span x-text="copied === 'summary' ? 'Skopiowano' : 'Kopiuj podsumowanie'">Kopiuj podsumowanie</span></x-panel.button>
        <x-panel.button variant="secondary" x-on:click="copy('recommendations')"><span x-text="copied === 'recommendations' ? 'Skopiowano' : 'Kopiuj rekomendacje'">Kopiuj rekomendacje</span></x-panel.button>
        <x-panel.button variant="secondary" x-on:click="copy('brief')"><span x-text="copied === 'brief' ? 'Skopiowano' : 'Kopiuj brief'">Kopiuj brief</span></x-panel.button>
        <x-panel.button variant="secondary" x-on:click="window.print()">Drukuj</x-panel.button>
        <p x-show="failed" x-cloak class="w-full text-xs text-red-700">Nie udało się skopiować — zaznacz tekst ręcznie albo użyj drukowania.</p>
      </div>
    @endif
  </div>

  {{-- Analiza w toku: status odświeżany co 5 s (wykonuje ją przetwarzanie w tle, nie przeglądarka). --}}
  @if ($run['active'])
    <div x-data="runProgress(@js($runUrl . '/status'), @js(['active' => true, 'label' => $run['status_label'], 'stuck' => false]))"
      class="mb-6 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" role="status">
      <p class="font-semibold">Analiza: <span x-text="label">{{ $run['status_label'] }}</span></p>
      <p x-show="stuck" x-cloak class="mt-2 rounded bg-amber-100 px-2 py-1 text-xs text-amber-900">Zlecenie czeka dłużej niż 15 minut, a przetwarzanie w tle nie działa — sprawdź cron serwera (wp osf-seo sync:run). Możesz anulować zlecenie (bez kosztu); po 6 h czekania wygasa bez kosztu.</p>
      <p class="mt-1 text-xs">Wykona ją przetwarzanie w tle (zwykle do kilku minut). Możesz opuścić tę stronę — wynik pojawi się tutaj i w historii analiz.</p>
      @if ($manage && $run['status'] === 'queued')
        <form method="post" action="{{ $runUrl }}/cancel" class="mt-2 print:hidden">
          <x-panel.nonce />
          <button type="submit" class="text-xs font-medium text-sky-800 underline">Anuluj zlecenie (bez kosztu)</button>
        </form>
      @endif
    </div>
  @elseif ($r === null)
    <div @class(['mb-6 rounded-lg border px-4 py-3 text-sm', 'border-amber-300 bg-amber-50 text-amber-900' => $run['status'] === 'uncertain', 'border-red-200 bg-red-50 text-red-800' => $run['status'] !== 'uncertain'])>
      @if ($run['status'] === 'uncertain')
        <p class="font-semibold">Wynik niepewny</p>
        <p class="mt-1">Żądanie mogło zostać wysłane do dostawcy, ale odpowiedź nie dotarła. Analiza nie jest ponawiana automatycznie; do budżetu liczona jest pełna rezerwacja kosztu.</p>
      @elseif ($run['status'] === 'invalid')
        <p class="font-semibold">Odpowiedź modelu odrzucona przez kontrolę jakości</p>
        <p class="mt-1">Wynik nie spełniał zasad (np. odwołania spoza danych, obietnice wzrostu) i nie został zapisany jako raport.</p>
      @else
        <p class="font-semibold">Analiza nie została wykonana</p>
      @endif
      @if (($run['error'] ?? null) !== null)
        <p class="mt-1 text-xs">{{ $run['error'] }}</p>
      @endif
      @if ($topicUrl !== null)
        <a href="{{ $topicUrl }}#analiza-ai" class="mt-2 inline-block text-xs font-medium underline print:hidden">Wróć do tematu i przygotuj analizę ponownie</a>
      @endif
    </div>
  @endif

  @if ($r !== null)
    {{-- Ostrzeżenia o naturze wyniku: dostawca testowy, aktualność, kandydat na nową stronę. --}}
    @if ($test_provider)
      <div class="mb-4 rounded-md border border-slate-300 bg-slate-100 px-4 py-2 text-sm text-slate-700">
        Wynik przykładowy wygenerowany przez dostawcę testowego — sprawdza działanie panelu, ale nie jest analizą modelu AI.
      </div>
    @endif
    @if ($freshness !== null && $freshness['state'] !== 'current')
      <div class="mb-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900">{{ $freshness['label'] }}</div>
    @elseif ($freshness !== null)
      <p class="mb-4 text-xs text-emerald-700">{{ $freshness['label'] }}</p>
    @endif
    @if ($r['candidate'])
      <div class="mb-4 rounded-md border border-brand-200 bg-brand-50 px-4 py-2 text-sm text-brand-900">
        Kandydat na nową stronę — brief nie potwierdza, że witryna nie ma takiej strony. Przed pisaniem sprawdź witrynę.
      </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <div class="min-w-0 space-y-6 lg:col-span-2">
        <x-panel.card>
          <h2 class="text-base font-semibold text-slate-900">Podsumowanie</h2>
          <p class="mt-2 whitespace-pre-line break-words text-sm text-slate-800">{{ $r['summary'] }}</p>
          @if ($r['intent'] !== null)
            <div class="mt-4 border-t border-slate-100 pt-3 text-sm">
              <p class="flex flex-wrap items-center gap-2"><span class="font-medium text-slate-900">Intencja wyszukiwania: {{ $r['intent']['label'] }}</span> <x-panel.basis :basis="$r['intent']['basis']" :confidence="$r['intent']['confidence_label']" /></p>
              @if ($r['intent']['explanation'] !== '')
                <p class="mt-1 break-words text-slate-700">{{ $r['intent']['explanation'] }}</p>
              @endif
              <x-panel.evidence-list :items="$r['intent']['evidence']" />
            </div>
          @endif
        </x-panel.card>

        @if ($r['top'] !== null)
          @php($top = $r['top'])
          <div class="rounded-lg border-2 border-brand-500 bg-white p-6 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Najważniejsza rekomendacja</p>
            <h2 class="mt-1 break-words text-lg font-semibold text-slate-900">{{ $top['title'] }}</h2>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-600">
              <span>{{ $top['priority_label'] }}</span>
              @if ($top['urgency_label'] !== null)<span>· pilność: {{ mb_strtolower($top['urgency_label']) }}</span>@endif
              <span>· wpływ: {{ mb_strtolower($top['impact_label']) }}</span>
              <x-panel.basis :basis="$top['basis']" :confidence="$top['confidence_label']" />
            </div>
            @include('panel.ai.partials.recommendation-body', ['item' => $top])
          </div>
        @endif

        @if (count($r['now']) > 1)
          <x-panel.card>
            <h2 class="text-base font-semibold text-slate-900">Co zrobić teraz</h2>
            <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-800">
              @foreach ($r['now'] as $item)
                <li class="break-words">{{ $item['title'] }}</li>
              @endforeach
            </ol>
          </x-panel.card>
        @endif

        @if (count($r['recommendations']) > 1)
          <x-panel.card>
            <h2 class="text-base font-semibold text-slate-900">Pozostałe rekomendacje ({{ count($r['recommendations']) - 1 }})</h2>
            <ol class="mt-3 space-y-5">
              @foreach (array_slice($r['recommendations'], 1) as $item)
                <li class="border-t border-slate-100 pt-4 first:border-0 first:pt-0">
                  <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <span class="text-sm font-semibold tabular-nums text-slate-500">{{ $loop->iteration + 1 }}.</span>
                    <h3 class="min-w-0 break-words text-sm font-semibold text-slate-900">{{ $item['title'] }}</h3>
                  </div>
                  <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                    @if ($item['type_label'] !== null)<span class="rounded bg-slate-100 px-1.5 py-0.5">{{ $item['type_label'] }}</span>@endif
                    <span>{{ $item['priority_label'] }}</span>
                    @if ($item['urgency_label'] !== null)<span>· {{ mb_strtolower($item['urgency_label']) }}</span>@endif
                    <span>· wpływ: {{ mb_strtolower($item['impact_label']) }}</span>
                    <x-panel.basis :basis="$item['basis']" :confidence="$item['confidence_label']" />
                  </div>
                  @include('panel.ai.partials.recommendation-body', ['item' => $item])
                </li>
              @endforeach
            </ol>
          </x-panel.card>
        @endif

        @if ($r['titles'] !== [] || $r['metas'] !== [])
          <x-panel.card>
            <h2 class="text-base font-semibold text-slate-900">Tytuł strony i opis meta — propozycje</h2>
            @foreach (['titles' => 'Tytuł strony (title)', 'metas' => 'Opis meta (meta description)'] as $key => $label)
              @if ($r[$key] !== [])
                <h3 class="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</h3>
                <ul class="mt-1 space-y-2">
                  @foreach ($r[$key] as $suggestion)
                    <li class="text-sm">
                      <p class="break-words font-medium text-slate-900">{{ $suggestion['text'] }} <span class="text-xs font-normal tabular-nums text-slate-500">({{ $suggestion['length'] }} znaków)</span></p>
                      @if ($suggestion['rationale'] !== '')<p class="break-words text-xs text-slate-600">{{ $suggestion['rationale'] }}</p>@endif
                      <div class="mt-0.5"><x-panel.basis :basis="$suggestion['basis']" /></div>
                      <x-panel.evidence-list :items="$suggestion['evidence']" />
                    </li>
                  @endforeach
                </ul>
              @endif
            @endforeach
          </x-panel.card>
        @endif

        @if ($r['outline'] !== null)
          <x-panel.card>
            <h2 class="text-base font-semibold text-slate-900">Konspekt (H1–H3)</h2>
            @if ($r['outline']['h1'] !== '')
              <p class="mt-2 break-words text-sm"><span class="mr-2 rounded bg-brand-100 px-1.5 py-0.5 text-xs font-semibold text-brand-800">H1</span><span class="font-semibold text-slate-900">{{ $r['outline']['h1'] }}</span></p>
            @endif
            <ul class="mt-2 space-y-2">
              @foreach ($r['outline']['sections'] as $section)
                <li @class(['text-sm', 'pl-6' => $section['level'] === 3])>
                  <p class="break-words"><span class="mr-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-700">H{{ $section['level'] }}</span><span class="font-medium text-slate-900">{{ $section['heading'] }}</span></p>
                  @if ($section['scope'] !== '')<p class="mt-0.5 break-words text-xs text-slate-600">{{ $section['scope'] }}</p>@endif
                  <x-panel.evidence-list :items="$section['evidence']" />
                </li>
              @endforeach
            </ul>
          </x-panel.card>
        @endif

        @if ($r['topics'] !== [])
          <x-panel.card>
            <h2 class="text-base font-semibold text-slate-900">Tematy treści względem konkurencji</h2>
            <p class="mt-1 text-xs text-slate-500">Porównanie z pobranym HTML stron — brak tematu w pobranym HTML nie dowodzi, że strona go nie omawia.</p>
            <ul class="mt-3 divide-y divide-slate-100">
              @foreach ($r['topics'] as $item)
                <li class="py-2 text-sm">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="break-words font-medium text-slate-900">{{ $item['label'] }}</span>
                    <span @class(['rounded px-1.5 py-0.5 text-xs', 'bg-red-50 text-red-700' => $item['status'] === 'potentially_missing', 'bg-amber-50 text-amber-800' => $item['status'] === 'shallow_on_project_page', 'bg-emerald-50 text-emerald-700' => $item['status'] === 'present_on_project_page', 'bg-slate-100 text-slate-600' => ! in_array($item['status'], ['potentially_missing', 'shallow_on_project_page', 'present_on_project_page'], true)])>{{ $item['status_label'] }}</span>
                    <x-panel.basis :basis="$item['basis']" :confidence="$item['confidence_label']" />
                  </div>
                  @if ($item['note'] !== '')<p class="mt-0.5 break-words text-xs text-slate-600">{{ $item['note'] }}</p>@endif
                  <x-panel.evidence-list :items="$item['evidence']" />
                </li>
              @endforeach
            </ul>
          </x-panel.card>
        @endif

        @if ($r['links'] !== [])
          <x-panel.card>
            <h2 class="text-base font-semibold text-slate-900">Linkowanie wewnętrzne</h2>
            <ul class="mt-2 space-y-3">
              @foreach ($r['links'] as $link)
                <li class="text-sm">
                  <p class="break-words text-slate-800">
                    <span class="font-medium">{{ $link['from_url'] === '' ? 'Ta strona' : $link['from_url'] }}</span> → <span class="font-medium">{{ $link['to_url'] === '' ? 'ta strona' : $link['to_url'] }}</span>
                    @if ($link['anchor'] !== '')<span class="text-slate-500">(anchor: „{{ $link['anchor'] }}”)</span>@endif
                  </p>
                  @if ($link['rationale'] !== '')<p class="break-words text-xs text-slate-600">{{ $link['rationale'] }}</p>@endif
                  <div class="mt-0.5"><x-panel.basis :basis="$link['basis']" /></div>
                  <x-panel.evidence-list :items="$link['evidence']" />
                </li>
              @endforeach
            </ul>
          </x-panel.card>
        @endif

        @if ($r['questions'] !== [])
          <x-panel.card>
            <h2 class="text-base font-semibold text-slate-900">Pytania użytkowników</h2>
            <p class="mt-1 text-xs text-slate-500">Rozważ sekcję FAQ tylko wtedy, gdy pasuje do strony i intencji.</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-800">
              @foreach ($r['questions'] as $question)
                <li class="break-words">{{ $question }}</li>
              @endforeach
            </ul>
          </x-panel.card>
        @endif

        @if ($r['findings'] !== [])
          <x-panel.card>
            <h2 class="text-base font-semibold text-slate-900">Uzasadnienie — ustalenia z danych</h2>
            <ul class="mt-2 space-y-3">
              @foreach ($r['findings'] as $finding)
                <li class="text-sm">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700">{{ $finding['kind_label'] }}</span>
                    <span class="break-words font-medium text-slate-900">{{ $finding['title'] }}</span>
                    <x-panel.basis :basis="$finding['basis']" :confidence="$finding['confidence_label']" />
                  </div>
                  @if ($finding['explanation'] !== '')<p class="mt-0.5 break-words text-slate-700">{{ $finding['explanation'] }}</p>@endif
                  <x-panel.evidence-list :items="$finding['evidence']" />
                </li>
              @endforeach
            </ul>
          </x-panel.card>
        @endif
      </div>

      <div class="min-w-0 space-y-6">
        @if ($r['limitations'] !== [] || $r['warnings'] !== [])
          <x-panel.card class="border-amber-200">
            <h2 class="text-base font-semibold text-slate-900">Ograniczenia i zastrzeżenia</h2>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-700">
              @foreach ([...$r['limitations'], ...$r['warnings']] as $note)
                <li class="break-words">{{ $note }}</li>
              @endforeach
            </ul>
          </x-panel.card>
        @endif

        @if ($r['checks'] !== [])
          <x-panel.card>
            <h2 class="text-base font-semibold text-slate-900">Do ręcznego sprawdzenia</h2>
            <ul class="mt-2 space-y-2 text-sm">
              @foreach ($r['checks'] as $check)
                <li>
                  <p class="break-words font-medium text-slate-800">{{ $check['check'] }}</p>
                  @if ($check['reason'] !== '')<p class="break-words text-xs text-slate-600">{{ $check['reason'] }}</p>@endif
                  <x-panel.evidence-list :items="$check['evidence']" />
                </li>
              @endforeach
            </ul>
          </x-panel.card>
        @endif

        @foreach (['ctas' => 'Wezwania do działania (CTA)', 'client_data' => 'Dane potrzebne od klienta'] as $key => $label)
          @if ($r[$key] !== [])
            <x-panel.card>
              <h2 class="text-base font-semibold text-slate-900">{{ $label }}</h2>
              <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-800">
                @foreach ($r[$key] as $value)
                  <li class="break-words">{{ $value }}</li>
                @endforeach
              </ul>
            </x-panel.card>
          @endif
        @endforeach

        @if ($r['risks'] !== [])
          <x-panel.card>
            <h2 class="text-base font-semibold text-slate-900">Ryzyko kanibalizacji</h2>
            <ul class="mt-2 space-y-2 text-sm">
              @foreach ($r['risks'] as $risk)
                <li>
                  <p class="break-words text-slate-800">{{ $risk['description'] }}</p>
                  <x-panel.basis :basis="$risk['basis']" />
                  <x-panel.evidence-list :items="$risk['evidence']" />
                </li>
              @endforeach
            </ul>
          </x-panel.card>
        @endif

        @if ($r['missing'] !== [])
          <x-panel.card>
            <h2 class="text-base font-semibold text-slate-900">Brakujące informacje</h2>
            <ul class="mt-2 space-y-2 text-sm">
              @foreach ($r['missing'] as $item)
                <li><p class="break-words font-medium text-slate-800">{{ $item['item'] }}</p>@if ($item['why'] !== '')<p class="break-words text-xs text-slate-600">{{ $item['why'] }}</p>@endif</li>
              @endforeach
            </ul>
          </x-panel.card>
        @endif

        <p class="text-xs text-slate-500">Rekomendacje AI to hipotezy do sprawdzenia, a nie gwarancja wzrostu. Panel nie zmienia treści strony automatycznie.</p>
      </div>
    </div>
  @endif

  {{-- Szczegóły techniczne i decyzja — wyłącznie administrator AI. --}}
  @if ($admin !== null)
    <x-panel.card class="mt-6 print:hidden">
      <h2 class="text-base font-semibold text-slate-900">Szczegóły (tylko administrator)</h2>
      <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
        <div><dt class="text-xs text-slate-500">Dostawca i model</dt><dd class="mt-1 break-words">{{ $admin['provider'] }} · {{ $admin['model'] }}</dd></div>
        <div><dt class="text-xs text-slate-500">Zlecenie</dt><dd class="mt-1">{{ $admin['trigger'] }}{{ $admin['explicit'] ? ' · świadomy wybór typu' : '' }}</dd></div>
        <div><dt class="text-xs text-slate-500">Gotowość danych przy zleceniu</dt><dd class="mt-1">{{ $admin['readiness'] }}</dd></div>
        <div><dt class="text-xs text-slate-500">Zgodność z działaniem</dt><dd class="mt-1">{{ $admin['compatibility'] }}</dd></div>
        <div>
          <dt class="text-xs text-slate-500">Koszt</dt>
          <dd class="mt-1 tabular-nums">
            @if ($admin['paid'])
              {{ Format::usd($admin['cost']['charged'], 4) }} <span class="block text-xs text-slate-500">maks. {{ Format::usd($admin['cost']['estimated'], 4) }}; {{ $admin['cost']['basis'] }}</span>
            @else
              0 USD <span class="block text-xs text-slate-500">{{ $admin['cost']['basis'] }}</span>
            @endif
          </dd>
        </div>
        <div><dt class="text-xs text-slate-500">Tokeny (wejście / wyjście)</dt><dd class="mt-1 tabular-nums">{{ $admin['tokens']['input'] === null ? '—' : Format::number($admin['tokens']['input']) }} / {{ $admin['tokens']['output'] === null ? '—' : Format::number($admin['tokens']['output']) }}</dd></div>
        <div><dt class="text-xs text-slate-500">Kontrola jakości</dt><dd class="mt-1">{{ $admin['validation_errors'] > 0 ? 'Błędy: ' . $admin['validation_errors'] : 'Bez błędów' }}</dd></div>
        @if ($admin['error'] !== null)
          <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Powód</dt><dd class="mt-1">{{ $admin['error'] }}</dd></div>
        @endif
      </dl>

      @if ($run['status'] === 'succeeded')
        <form method="post" action="{{ $runUrl }}/decision" class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
          <x-panel.nonce />
          <span class="text-sm text-slate-600">Decyzja o wyniku (nie zmienia Strategii):</span>
          @if ($run['decision'] !== 'accepted')
            <x-panel.button type="submit" variant="secondary" name="decision" value="accepted">Przyjmij</x-panel.button>
          @endif
          @if ($run['decision'] !== 'rejected')
            <x-panel.button type="submit" variant="danger" name="decision" value="rejected">Odrzuć (ukryj przed klientem)</x-panel.button>
          @endif
          @if ($run['decision'] !== null)
            <x-panel.button type="submit" variant="secondary" name="decision" value="">Cofnij decyzję</x-panel.button>
          @endif
        </form>
      @endif
    </x-panel.card>
  @endif
@endsection
