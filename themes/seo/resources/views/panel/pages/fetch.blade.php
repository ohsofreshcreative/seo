@extends('panel.layouts.app', ['active' => 'pages'])

@section('title', 'Pobierz strony · ' . $project->name)

@php
  use App\Http\Controllers\Panel\PagesController;
  use App\Http\Controllers\Panel\StrategyTopicsController;
  use App\Panel\Format;
  use App\Panel\PageLabels;
  use App\Panel\PanelUrl;

  $projectId = $project->publicId;
  $action = PanelUrl::project($projectId, 'pages/fetch');
  $items = $plan['items'] ?? [];
  $refused = $plan['refused'] ?? null;
  $fetches = (int) ($plan['fetches'] ?? 0);
@endphp

@section('content')
  <p class="mb-4 flex flex-wrap gap-x-4 text-sm">
    @if ($form['topic'] !== '')
      <a href="{{ StrategyTopicsController::topicUrl($projectId, $form['topic']) }}#analiza-ai" class="text-brand-600 hover:underline">← Temat</a>
    @endif
    <a href="{{ PanelUrl::project($projectId, 'pages') }}" class="text-brand-600 hover:underline">Strony</a>
  </p>

  <x-panel.page-header title="Pobierz strony" description="Plan nie wysyła żadnych żądań. Strony pobierze przetwarzanie w tle dopiero po potwierdzeniu — z robots.txt, limitami witryny i odstępami między żądaniami." />

  @if ($plan === null)
    <x-panel.card class="max-w-3xl">
      <h2 class="text-base font-semibold text-slate-900">Adresy do pobrania</h2>
      <p class="mt-1 text-xs text-slate-500">
        Najwyżej {{ $maxUrls }} adresów. Dozwolone są wyłącznie strony domeny projektu, domen konkurentów projektu i wyniki zapisanych pomiarów SERP —
        inne adresy zostaną odrzucone w planie. Stronę docelową tematu i strony konkurencji z SERP wybierzesz też w szczegółach tematu Strategii.
      </p>
      <form method="get" action="{{ $action }}" class="mt-4 space-y-3">
        <input type="hidden" name="mode" value="url">
        <textarea name="urls" rows="5" maxlength="5000" class="w-full rounded-md border-slate-300 text-sm" placeholder="https://www.example.com/strona">{{ $form['urls'] }}</textarea>
        <x-panel.button type="submit">Sprawdź plan (bez pobierania)</x-panel.button>
      </form>
    </x-panel.card>
  @else
    <x-panel.card>
      <h2 class="text-base font-semibold text-slate-900">Plan pobrania</h2>
      @if ($form['mode'] === 'serp')
        <p class="mt-1 text-xs text-slate-500">Wyniki organiczne z zapisanego pomiaru SERP — pozycje: {{ implode(', ', array_map(static fn ($rank) => '#' . $rank, $form['ranks'])) }}.</p>
      @endif

      <ul class="mt-3 divide-y divide-slate-100">
        @foreach ($items as $item)
          <li class="py-3 text-sm">
            <p class="break-all font-medium text-slate-900">{{ $item['url'] ?? $item['input'] ?? '—' }}</p>
            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-600">
              @if ($item['kind'] !== null)<span class="rounded bg-slate-100 px-1.5 py-0.5">{{ PageLabels::kind($item['kind']) }}</span>@endif
              @if (is_array($item['serp'] ?? null))
                <span>SERP #{{ $item['serp']['rank_group'] }}{{ ($item['serp']['keyword'] ?? null) !== null ? ' „' . $item['serp']['keyword'] . '”' : '' }}, pomiar {{ Format::date($item['serp']['checked_at']) }}</span>
              @endif
              @if (! $item['allowed'])
                <span class="font-medium text-red-700">Niedozwolone: {{ PageLabels::error($item['reason']) }}</span>
              @elseif (! ($item['would_fetch'] ?? false))
                <span class="text-emerald-700">Aktualna kopia z {{ Format::datetime($item['last_seen_at']) }} — bez pobierania</span>
              @else
                <span class="text-sky-800">Zostanie pobrana{{ ($item['cache'] ?? null) === 'stale' ? ' (starsza kopia zostanie odświeżona)' : '' }}</span>
              @endif
            </div>
            @if ($item['allowed'] && is_array($item['host_limits'] ?? null))
              @php($limits = $item['host_limits'])
              <p class="mt-1 text-xs text-slate-500">
                Witryna {{ $item['host'] }}: pobrania w ciągu doby {{ $limits['fetches_24h'] }} z {{ $limits['daily_limit'] }}; odstęp między żądaniami {{ $limits['interval'] }} s.
                @if ($limits['blocked'] === 'host_retry_after')
                  <span class="text-amber-800">Witryna poprosiła o przerwę — pobranie zostanie odrzucone, bez ponowienia.</span>
                @elseif ($limits['blocked'] === 'domain_daily_limit')
                  <span class="text-amber-800">Dzienny limit witryny wyczerpany.</span>
                @elseif ($limits['blocked'] === 'domain_cooldown')
                  <span>Kolejne żądanie za {{ (int) $limits['retry_in'] }} s — przetwarzanie w tle poczeka.</span>
                @endif
              </p>
            @endif
            @if (($item['page'] ?? null) !== null)
              <a href="{{ PagesController::pageUrl($projectId, $item['page']) }}" class="mt-1 inline-block text-xs text-brand-600 hover:underline">Zapisane kopie strony</a>
            @endif
          </li>
        @endforeach
      </ul>
      <p class="mt-2 text-xs text-slate-500">Przed każdym pobraniem sprawdzamy robots.txt witryny — strony zablokowane nie zostaną pobrane. Pobieramy wyłącznie HTML (bez uruchamiania JavaScriptu); surowy HTML nie jest zapisywany.</p>

      @if ($refused !== null)
        <div class="mt-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
          <p class="font-medium">Zlecenie nie może zostać utworzone: {{ PageLabels::error($refused) }}</p>
          <p class="mt-1 text-xs">Popraw wybór — w jednym zleceniu wszystkie adresy muszą być dozwolone (najwyżej {{ $maxUrls }}).</p>
        </div>
        @if ($form['mode'] === 'url')
          <form method="get" action="{{ $action }}" class="mt-4 space-y-3">
            <input type="hidden" name="mode" value="url">
            <textarea name="urls" rows="4" maxlength="5000" class="w-full rounded-md border-slate-300 text-sm">{{ $form['urls'] }}</textarea>
            <x-panel.button type="submit" variant="secondary">Sprawdź ponownie</x-panel.button>
          </form>
        @endif
      @else
        <form method="post" action="{{ $action }}" class="mt-5 space-y-3 border-t border-slate-100 pt-4" x-data="{ sending: false }" @submit="sending = true">
          <x-panel.nonce />
          <input type="hidden" name="mode" value="{{ $form['mode'] }}">
          <input type="hidden" name="topic" value="{{ $form['topic'] }}">
          <input type="hidden" name="keyword" value="{{ $form['keyword'] }}">
          @foreach ($form['ranks'] as $rank)
            <input type="hidden" name="ranks[]" value="{{ $rank }}">
          @endforeach
          <input type="hidden" name="urls" value="{{ $form['urls'] }}">
          <input type="hidden" name="page" value="{{ $form['page'] }}">
          @if ($form['force'])<input type="hidden" name="force" value="1">@endif
          @if ($fetches === 0 && ! $form['force'])
            <p class="text-sm text-slate-700">Wszystkie wybrane strony mają aktualne kopie — zlecenie tylko je potwierdzi, bez żądań do witryn.
              <a href="{{ PagesController::planUrl($projectId, ['force' => true] + $form) }}" class="text-brand-600 hover:underline">Pobierz mimo to ponownie</a></p>
          @endif
          <label class="flex items-start gap-2 text-sm text-slate-800">
            <input type="checkbox" name="confirmed" value="1" required class="mt-0.5 rounded border-slate-300 text-brand-600">
            <span>Potwierdzam pobranie {{ $fetches }} {{ \OsfSeo\Opportunities\Text::plural($fetches, 'strony', 'stron', 'stron') }} (żądania HTTP do wskazanych witryn).</span>
          </label>
          <x-panel.button type="submit" x-bind:disabled="sending">Zleć pobranie</x-panel.button>
        </form>
      @endif
    </x-panel.card>
  @endif
@endsection
