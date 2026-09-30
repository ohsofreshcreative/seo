@props(['title', 'description' => null])
<div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
  <div>
    <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $title }}</h1>
    @if ($description)
      <p class="mt-1 text-sm text-slate-500">{{ $description }}</p>
    @endif
  </div>
  @isset($actions)
    <div class="flex shrink-0 flex-wrap gap-2">{{ $actions }}</div>
  @endisset
</div>
