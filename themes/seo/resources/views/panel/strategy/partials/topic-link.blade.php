{{-- Odnośnik do tematu Strategii z innego modułu (fraza należy do tematu): nazwa, działanie i status pracy. Bez tematu — nic. --}}
@if (! empty($topic))
  <a href="{{ \App\Http\Controllers\Panel\StrategyTopicsController::topicUrl($projectId, $topic['topic']) }}"
    class="inline-flex max-w-full items-center gap-1.5 rounded-md bg-brand-50 px-2 py-1 text-xs text-brand-800 ring-1 ring-inset ring-brand-200 hover:bg-brand-100"
    title="Temat Strategii: {{ $topic['label'] }} · {{ \App\Panel\StrategyLabels::action($topic['action']) }} · {{ \App\Panel\StrategyLabels::status($topic['status']) }}">
    <span class="font-semibold">Strategia</span>
    <span class="truncate">{{ $topic['label'] }}</span>
    <span class="whitespace-nowrap text-brand-700">· {{ \App\Panel\StrategyLabels::action($topic['action']) }} · {{ mb_strtolower(\App\Panel\StrategyLabels::status($topic['status'])) }}@if (! $topic['active']) · nieaktywny @endif</span>
  </a>
@endif
