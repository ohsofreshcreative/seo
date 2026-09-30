@props(['href', 'active' => false])
<a href="{{ $href }}" @if ($active) aria-current="page" @endif
  {{ $attributes->class([
    'flex items-center rounded-md px-3 py-2 text-sm font-medium transition-colors',
    'bg-brand-700 text-white' => $active,
    'text-slate-300 hover:bg-brand-800 hover:text-white' => ! $active,
  ]) }}>{{ $slot }}</a>
