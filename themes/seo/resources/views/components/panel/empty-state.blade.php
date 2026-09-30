@props(['title', 'description' => null])
<div {{ $attributes->class(['rounded-lg border-2 border-dashed border-slate-200 px-6 py-12 text-center']) }}>
  <p class="text-sm font-semibold text-slate-800">{{ $title }}</p>
  @if ($description)
    <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">{{ $description }}</p>
  @endif
  @if (! $slot->isEmpty())
    <div class="mt-6 flex justify-center gap-2">{{ $slot }}</div>
  @endif
</div>
