{{--
  SERP Intelligence pomiaru frazy: kontekst, świeżość, kompozycja TOP10/TOP20, kształty wyników (heurystyka z pewnością), sygnał intencji,
  obecność projektu i konkurentów oraz TOP20 z tytułami, domenami i adresami. Wyniki z SERP to dane zewnętrzne — zawsze escapowane,
  linki tylko http(s) z rel="noopener noreferrer". Kształt „Dedykowana podstrona” to heurystyka — nigdy „strona usługowa”.
--}}
@php
  use App\Panel\Format;
  use App\Panel\StrategyLabels;
  use OsfSeo\Strategy\Serp\SerpConfidence;

  $profile = $detail['profile'] ?? null;
  $own = $detail['project'] ?? null;
  $rivals = $detail['competitors'] ?? null;
  $safeUrl = static fn (?string $url): bool => $url !== null && preg_match('#^https?://#i', $url) === 1;
  $shapes = static function (array $counts): string {
    arsort($counts);

    return implode(', ', array_map(static fn (string $shape, int $count): string => StrategyLabels::shape($shape) . ' ' . $count, array_keys($counts), array_values($counts)));
  };
@endphp
<div class="flex flex-wrap items-center gap-2 text-sm">
  <x-panel.serp-freshness :freshness="$detail['freshness']" :checked-at="$detail['checked_at']" />
  <span class="text-slate-600">pomiar {{ Format::datetime($detail['checked_at']) }}</span>
  <span class="text-slate-400">·</span>
  <span class="text-slate-600">{{ ($detail['context']['device'] ?? '') === 'mobile' ? 'mobile' : 'desktop' }}, TOP{{ $detail['context']['depth'] ?? '—' }}</span>
  @if (($detail['tracking'] ?? null) === 'analysis')
    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600" title="Pomiar analizy Strategii — fraza nie jest na liście monitorowanych Pozycji">analiza Strategii</span>
  @endif
  @if ($detail['spell'] ?? null)
    <span class="rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-800" title="Wyszukiwarka poprawiła albo podpowiedziała pisownię frazy — wyniki mogą dotyczyć innego zapytania">korekta pisowni</span>
  @endif
</div>

@if ($detail['freshness'] === 'stale')
  <p class="mt-2 text-xs text-amber-700">Pomiar nieaktualny (31–90 dni): kształt i kompozycja z obniżoną pewnością, bez Pozycji SERP projektu.</p>
@elseif ($detail['freshness'] === 'expired')
  <p class="mt-2 text-xs text-slate-500">Pomiar wygasły (> 90 dni) — nie służy do klasyfikacji ani overlapu. Fraza kwalifikuje się do nowego pomiaru.</p>
@endif

<dl class="mt-4 grid grid-cols-2 gap-4 text-sm md:grid-cols-4">
  <div>
    <dt class="text-slate-500">Pozycja SERP projektu</dt>
    <dd class="mt-1">
      @if ($own === null)
        <span class="text-slate-400" title="Pozycja SERP tylko ze świeżego pomiaru (≤ 30 dni)">—</span>
      @else
        <x-panel.serp-rank :rank="$own['rank']" :found="$own['found']" :depth="$detail['context']['depth'] ?? null" :featured="(bool) ($own['featured'] ?? false)" />
      @endif
    </dd>
  </div>
  <div>
    <dt class="text-slate-500">Dominujący kształt TOP10</dt>
    <dd class="mt-1 text-slate-900">
      @if ($profile === null)
        —
      @else
        {{ StrategyLabels::shape($profile['shape']) }}
        <span class="block text-xs text-slate-500">pewność {{ SerpConfidence::label($profile['shape_confidence']) }}@if ($profile['shape_share'] !== null) · udział {{ Format::percent((float) $profile['shape_share'], 0) }}@endif</span>
      @endif
    </dd>
  </div>
  <div>
    <dt class="text-slate-500">Sygnał intencji z SERP</dt>
    <dd class="mt-1 text-slate-900">
      @if ($profile === null)
        —
      @else
        {{ StrategyLabels::serpIntent($profile['intent_signal']) }}
        <span class="block text-xs text-slate-500">pewność {{ SerpConfidence::label($profile['intent_confidence']) }} · sygnał do sprawdzenia, nie klasyfikacja zapytania</span>
      @endif
    </dd>
  </div>
  <div>
    <dt class="text-slate-500">Konkurenci (TOP10 / TOP20)</dt>
    <dd class="mt-1 tabular-nums text-slate-900">
      @if ($rivals === null)
        —
      @else
        {{ $rivals['top10'] }} / {{ $rivals['top20'] }}
        @if ($rivals['best'] !== null)
          <span class="block text-xs text-slate-500">najlepszy: {{ $rivals['best']['name'] }} #{{ $rivals['best']['rank'] }}</span>
        @endif
      @endif
    </dd>
  </div>
</dl>

@if ($profile !== null)
  <div class="mt-4 grid gap-4 text-xs text-slate-600 md:grid-cols-2">
    <p><span class="font-medium text-slate-700">TOP10:</span> {{ $profile['top10']['organic'] }} wyników organicznych, {{ $profile['top10']['domains'] }} domen, stron głównych {{ $profile['top10']['home'] }}@if ($profile['top10']['shapes'] !== []) · {{ $shapes($profile['top10']['shapes']) }}@endif</p>
    <p><span class="font-medium text-slate-700">TOP20:</span> {{ $profile['top20']['organic'] }} wyników organicznych, {{ $profile['top20']['domains'] }} domen @if ($profile['top20']['shapes'] !== []) · {{ $shapes($profile['top20']['shapes']) }}@endif</p>
  </div>
@endif

@if (($detail['results'] ?? []) === [])
  <p class="mt-4 text-sm text-slate-500">Pomiar nie zawiera wyników organicznych w TOP20.</p>
@else
  @foreach ([array_values(array_filter($detail['results'], static fn (array $result): bool => $result['rank'] <= 10)), array_values(array_filter($detail['results'], static fn (array $result): bool => $result['rank'] > 10))] as $chunkIndex => $chunk)
  @continue($chunk === [])
  @if ($chunkIndex === 1)
    <details class="mt-2">
      <summary class="cursor-pointer text-sm text-brand-600">Pokaż wyniki 11–20 ({{ count($chunk) }})</summary>
  @endif
  <div class="mt-4 overflow-x-auto">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
      <thead class="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
        <tr>
          <th class="py-2 pr-3 text-right">#</th>
          <th class="py-2 pr-3">Wynik</th>
          <th class="py-2 pr-3" title="Heurystyka z adresu i prezentacji wyniku — opis tego, co rankuje, nie fakt o treści strony">Kształt (heurystyka)</th>
          <th class="py-2">Oznaczenie</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        @foreach ($chunk as $result)
          <tr @class(['bg-brand-50/60' => $result['project']])>
            <td class="py-2 pr-3 text-right align-top tabular-nums text-slate-700">{{ $result['rank'] }}</td>
            <td class="max-w-xl py-2 pr-3 align-top">
              <p class="break-words font-medium text-slate-900">{{ $result['title'] ?? '—' }}</p>
              <p class="text-xs text-slate-500">{{ $result['host'] }}</p>
              @if ($safeUrl($result['url']))
                <a href="{{ $result['url'] }}" target="_blank" rel="noopener noreferrer" class="block break-all text-xs text-brand-600 hover:underline">{{ $result['url'] }}</a>
              @else
                <span class="block break-all text-xs text-slate-500">{{ $result['url'] }}</span>
              @endif
            </td>
            <td class="py-2 pr-3 align-top text-xs text-slate-700">
              {{ StrategyLabels::shape($result['shape']) }}
              @if ($result['confidence'] !== null)
                <span class="block text-slate-500">pewność {{ SerpConfidence::label($result['confidence']) }}</span>
              @endif
            </td>
            <td class="py-2 align-top text-xs">
              @if ($result['project'])
                <span class="rounded bg-brand-700 px-1.5 py-0.5 font-medium text-white">projekt</span>
              @endif
              @if ($result['competitor'] !== null)
                <span class="rounded bg-amber-50 px-1.5 py-0.5 font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20">konkurent: {{ $result['competitor'] }}</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  @if ($chunkIndex === 1)
    </details>
  @endif
  @endforeach
@endif
