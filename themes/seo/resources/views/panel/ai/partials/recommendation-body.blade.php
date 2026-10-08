{{-- Treść rekomendacji raportu AI: co zrobić, gdzie, dlaczego, jak sprawdzić, dowody (dane zewnętrzne zawsze escapowane). --}}
<dl class="mt-3 space-y-2 text-sm">
  @if ($item['description'] !== '')
    <div><dt class="text-xs font-medium text-slate-500">Co zrobić</dt><dd class="break-words text-slate-800">{{ $item['description'] }}</dd></div>
  @endif
  @if ($item['target'] !== '')
    <div><dt class="text-xs font-medium text-slate-500">Gdzie</dt><dd class="break-words text-slate-800">{{ $item['target'] }}</dd></div>
  @endif
  @if ($item['rationale'] !== '')
    <div><dt class="text-xs font-medium text-slate-500">Dlaczego</dt><dd class="break-words text-slate-800">{{ $item['rationale'] }}</dd></div>
  @endif
  @if ($item['impact_rationale'] !== '')
    <div><dt class="text-xs font-medium text-slate-500">Wpływ</dt><dd class="break-words text-slate-700">{{ $item['impact_rationale'] }}</dd></div>
  @endif
  @if ($item['verification'] !== '')
    <div><dt class="text-xs font-medium text-slate-500">Jak sprawdzić</dt><dd class="break-words text-slate-700">{{ $item['verification'] }}</dd></div>
  @endif
</dl>
@if ($item['manual_check'])
  <p class="mt-2 text-xs font-medium text-amber-800">Wymaga ręcznej weryfikacji przed wdrożeniem.</p>
@endif
@if ($item['findings'] !== [])
  <p class="mt-1 text-xs text-slate-500">Na podstawie ustaleń: {{ implode('; ', $item['findings']) }}</p>
@endif
<x-panel.evidence-list :items="$item['evidence']" />
