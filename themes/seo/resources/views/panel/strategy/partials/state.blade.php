{{-- Stan przeliczenia Strategii (z zapisanego stanu — bez przeliczania): rynek, przeliczenie w toku, zlecenie, nieaktualne dane, limit fraz. --}}
@php
  use App\Panel\Format;
  use App\Panel\PanelUrl;
  use OsfSeo\Opportunities\Text;

  $overflow = (int) ($state['last_refresh']['overflow'] ?? 0);
@endphp
<div class="space-y-2">
  @if (! $state['supported'])
    <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      Rynek projektu (kraj i język) nie jest obsługiwany przez dostawcę danych rynkowych — Strategia nie może zostać przeliczona. Zmień kraj albo język w ustawieniach projektu.
    </div>
  @elseif ($state['running'])
    <div class="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
      Przeliczenie Strategii trwa (CLI albo krok w tle). Widok pokazuje stan poprzedniego przeliczenia — odśwież stronę za chwilę.
    </div>
  @elseif ($state['refreshed_at'] === null)
    <div class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
      Strategia nie była jeszcze przeliczona dla tego projektu.
      @if ($state['requested_at'] !== null)
        Przeliczenie zlecono {{ Format::datetime($state['requested_at']) }} —
      @endif
      Przeliczenie jest lokalne (bez kosztów i bez żądań do API). Automatyczne przeliczanie w tle nie jest jeszcze włączone — do tego czasu uruchamia je administrator komendą <code class="rounded bg-white px-1 text-xs">wp osf-seo strategy:refresh --project={{ $project->publicId }}</code>.
    </div>
  @elseif ($state['requested_at'] !== null)
    <div class="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
      Przeliczenie zlecone {{ Format::datetime($state['requested_at']) }} — automatyczne przeliczanie w tle nie jest jeszcze włączone, więc zlecenie wykona administrator komendą <code class="rounded bg-white px-1 text-xs">wp osf-seo strategy:refresh</code>. Do tego czasu widać wynik z {{ Format::datetime($state['refreshed_at']) }}.
    </div>
  @elseif (! $state['up_to_date'])
    <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      Dane modułów zmieniły się od ostatniego przeliczenia ({{ Format::datetime($state['refreshed_at']) }}) — tematy mogą być nieaktualne.
      @if ($canManage ?? false)
        <form method="post" action="{{ PanelUrl::project($project->publicId, 'strategy/refresh') }}" class="mt-2 inline">
          <x-panel.nonce />
          <input type="hidden" name="back" value="{{ $back ?? PanelUrl::project($project->publicId, 'strategy') }}">
          <button type="submit" class="font-semibold text-amber-900 underline hover:no-underline">Zleć przeliczenie</button>
        </form>
      @endif
    </div>
  @endif

  @if ($overflow > 0)
    <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      Niepełne dane: limit fraz Strategii ({{ Format::number($state['last_refresh']['limit'] ?? null) }}) pominął {{ Format::number($overflow) }} {{ Text::plural($overflow, 'kandydata', 'kandydatów', 'kandydatów') }} o najniższej ważności (bez cichego obcinania — limit zmienia stała <code class="text-xs">OSF_SEO_STRATEGY_MAX_KEYWORDS</code>).
    </div>
  @endif
</div>
