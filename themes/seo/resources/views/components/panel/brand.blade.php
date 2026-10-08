{{-- Marka aplikacji: logo z biblioteki mediów (proporcje zachowane, ograniczona wysokość i szerokość) albo napis „Whack-a-mole”. --}}
@props(['logo' => null, 'variant' => 'sidebar'])
@php
  $guest = $variant === 'guest';
@endphp
@if ($logo)
  <img src="{{ $logo->url }}" @if ($logo->srcset) srcset="{{ $logo->srcset }}" sizes="{{ $guest ? '240px' : '176px' }}" @endif
    width="{{ $logo->width }}" height="{{ $logo->height }}" alt="{{ $logo->alt }}" decoding="async"
    {{ $attributes->class(['block h-auto w-auto object-contain', 'max-h-16 max-w-60' => $guest, 'max-h-10 max-w-44' => ! $guest]) }}>
@else
  <span {{ $attributes->class(['font-semibold tracking-tight', 'text-2xl text-brand-700' => $guest, 'text-lg text-white' => ! $guest]) }}>Whack-a-mole</span>
@endif
