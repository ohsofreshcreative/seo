@props(['messages' => []])
@if (! empty($messages))
  <div class="mb-6 space-y-2" role="status">
    @foreach ($messages as $flash)
      <div @class([
        'rounded-md border px-4 py-3 text-sm',
        'border-emerald-200 bg-emerald-50 text-emerald-800' => $flash['type'] === 'success',
        'border-red-200 bg-red-50 text-red-800' => $flash['type'] === 'error',
        'border-sky-200 bg-sky-50 text-sky-800' => $flash['type'] === 'info',
      ])>{{ $flash['message'] }}</div>
    @endforeach
  </div>
@endif
