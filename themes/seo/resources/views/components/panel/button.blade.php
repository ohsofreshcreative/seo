@props(['href' => null, 'variant' => 'primary', 'type' => 'button'])
@php
  $classes = [
    'primary' => 'bg-brand-700 text-white hover:bg-brand-800 focus-visible:outline-brand-700',
    'secondary' => 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus-visible:outline-slate-400',
    'danger' => 'bg-white text-red-700 ring-1 ring-inset ring-red-300 hover:bg-red-50 focus-visible:outline-red-600',
  ][$variant] ?? '';
  $base = 'inline-flex items-center justify-center gap-2 rounded-md px-3.5 py-2 text-sm font-semibold shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2';
@endphp
@if ($href)
  <a href="{{ $href }}" {{ $attributes->class([$base, $classes]) }}>{{ $slot }}</a>
@else
  <button type="{{ $type }}" {{ $attributes->class([$base, $classes]) }}>{{ $slot }}</button>
@endif
