{{-- Karta szansy na liście: priorytet, pewność, typ, podstrona/fraza, powód, kluczowe metryki, następny krok, status. --}}
@php
  use App\Panel\Format;

  $current = $opportunity->current();
  $previous = $opportunity->previous();
  $isDecline = $opportunity->type === \OsfSeo\Opportunities\OpportunityType::Decline;
  $recommendation = $opportunity->recommendations()[0] ?? null;
  $href = \App\Http\Controllers\Panel\OpportunitiesController::detailUrl($project->publicId, $opportunity->publicId, $days);
@endphp
<article class="flex gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
  <div class="flex shrink-0 flex-col items-center gap-2">
    <x-panel.score :value="$opportunity->priority" />
    <span class="text-xs text-slate-500">priorytet</span>
  </div>

  <div class="min-w-0 flex-1">
    <div class="flex flex-wrap items-center gap-2">
      <span class="text-xs font-semibold uppercase tracking-wide text-brand-600">{{ $opportunity->type->label() }}</span>
      <x-panel.confidence :level="$opportunity->confidence" />
      <x-panel.opportunity-status :status="$opportunity->status" />
      @if ($opportunity->state !== \OsfSeo\Opportunities\OpportunityState::Active)
        <span class="text-xs text-slate-500">{{ $opportunity->state->label() }}@if ($opportunity->inactiveSince) · od {{ Format::datetime($opportunity->inactiveSince) }}@endif</span>
      @endif
    </div>

    <h3 class="mt-2 break-words text-base font-semibold text-slate-900">
      <a href="{{ $href }}" class="hover:text-brand-700 hover:underline">{{ $opportunity->title() }}</a>
    </h3>

    <p class="mt-1 text-sm text-slate-600">{{ $opportunity->summary() }}</p>

    <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-xs text-slate-500 sm:grid-cols-4">
      <div><dt class="inline">Wyświetlenia:</dt> <dd class="inline font-medium tabular-nums text-slate-800">{{ Format::number($current->impressions) }}</dd>
        @if ($previous->hasData())<span class="tabular-nums">(poprz. {{ Format::number($previous->impressions) }})</span>@endif</div>
      <div><dt class="inline">Kliknięcia:</dt> <dd class="inline font-medium tabular-nums text-slate-800">{{ Format::number($current->clicks) }}</dd>
        @if ($previous->hasData())<span class="tabular-nums">(poprz. {{ Format::number($previous->clicks) }})</span>@endif</div>
      <div><dt class="inline">CTR:</dt> <dd class="inline font-medium tabular-nums text-slate-800">{{ Format::percent($current->ctr()) }}</dd></div>
      <div><dt class="inline">Średnia pozycja (GSC):</dt> <dd class="inline font-medium tabular-nums text-slate-800">{{ Format::position($current->position()) }}</dd>
        @if ($isDecline && $previous->hasData())<span class="tabular-nums">(poprz. {{ Format::position($previous->position()) }})</span>@endif</div>
    </dl>

    @if ($recommendation)
      <p class="mt-3 text-sm text-slate-700"><span class="font-medium text-slate-900">Następny krok:</span> {{ $recommendation }}</p>
    @endif
  </div>

  <div class="hidden shrink-0 self-center sm:block">
    <x-panel.button variant="secondary" :href="$href">Szczegóły</x-panel.button>
  </div>
</article>
