{{-- Dowody twierdzenia raportu AI: czytelne etykiety (bez kodów), odnośniki wyłącznie http(s). --}}
@props(['items' => []])
@if ($items !== [])
  <p {{ $attributes->class(['mt-1 text-xs text-slate-500']) }}>
    <span class="font-medium text-slate-600">Dowody:</span>
    @foreach ($items as $item)
      @if ($item['url'] !== null)
        <a href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer" class="break-words text-brand-600 hover:underline">{{ $item['label'] }}</a>@if (! $loop->last); @endif
      @else
        <span class="break-words">{{ $item['label'] }}</span>@if (! $loop->last); @endif
      @endif
    @endforeach
  </p>
@endif
