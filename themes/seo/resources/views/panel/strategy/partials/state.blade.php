{{-- Stan przeliczenia Strategii (faza E — z zapisanego stanu zadania, bez przeliczania): faza, opis, wynik ostatniego przeliczenia,
     ponowne zlecenie, ostrzeżenie o niedziałającym kroku w tle (administrator). Gdy przeliczenie czeka albo trwa — odpytywanie statusu
     co 5 s i przeładowanie po zakończeniu (`runProgress`). --}}
@php
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use App\Panel\StrategyRefreshView;
  use OsfSeo\Opportunities\Text;

  $manage = $canManage ?? false;
  $job = $state['job'];
  $payload = StrategyRefreshView::payload($state, $manage);
  $summary = StrategyRefreshView::summary($state);
  $overflow = (int) ($state['last_refresh']['overflow'] ?? 0);
@endphp
<div class="space-y-2">
  <div x-data="runProgress(@js(PanelUrl::project($project->publicId, 'strategy/status')), @js($payload))"
    class="rounded-md border px-4 py-3 text-sm {{ StrategyRefreshView::tone($job['phase']) }}" data-strategy-refresh="{{ $job['phase'] }}">
    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
      <p class="min-w-0 flex-1">
        <span class="font-semibold" x-text="label">{{ $payload['label'] }}</span>
        <span class="mx-1" aria-hidden="true">·</span>
        <span x-text="detail">{{ $payload['detail'] }}</span>
      </p>
      @if ($manage && $state['supported'] && ! $payload['active'])
        <form method="post" action="{{ PanelUrl::project($project->publicId, 'strategy/refresh') }}" class="shrink-0">
          <x-panel.nonce />
          <input type="hidden" name="back" value="{{ $back ?? PanelUrl::project($project->publicId, 'strategy') }}">
          <button type="submit" class="font-semibold underline hover:no-underline">{{ in_array($job['phase'], ['pending'], true) ? 'Zleć przeliczenie teraz' : 'Zleć przeliczenie' }}</button>
        </form>
      @endif
    </div>
    @if ($summary !== null)
      <p class="mt-1 text-xs opacity-80">{{ $summary }}</p>
    @endif
    @if ($job['worker_stale'])
      <p class="mt-2 text-xs font-medium text-amber-800">
        Zadania w tle nie uruchamiały się {{ $job['worker_heartbeat'] === null ? 'jeszcze ani razu' : 'od ' . Format::datetime($job['worker_heartbeat']) }} — przeliczenie wykona się dopiero po uruchomieniu crona
        (<code class="rounded bg-white px-1">wp osf-seo sync:run</code> co minutę; instrukcja: docs/HOSTINGER-CRON.md) albo komendą <code class="rounded bg-white px-1">wp osf-seo strategy:refresh --project={{ $project->publicId }}</code>.
      </p>
    @endif
  </div>

  @if ($overflow > 0)
    <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      Niepełne dane: limit fraz Strategii ({{ Format::number($state['last_refresh']['limit'] ?? null) }}) pominął {{ Format::number($overflow) }} {{ Text::plural($overflow, 'kandydata', 'kandydatów', 'kandydatów') }} o najniższej ważności (bez cichego obcinania — limit zmienia stała <code class="text-xs">OSF_SEO_STRATEGY_MAX_KEYWORDS</code>).
    </div>
  @endif
</div>
