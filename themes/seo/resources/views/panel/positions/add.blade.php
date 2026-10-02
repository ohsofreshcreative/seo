@extends('panel.layouts.app', ['active' => 'positions'])

@section('title', 'Dodaj frazy · Pozycje · ' . $project->name)

@php
  use App\Panel\Format;
  use App\Panel\PanelUrl;

  $base = PanelUrl::project($project->publicId, 'positions');
@endphp

@section('content')
  <p class="mb-2 text-sm"><a href="{{ $base }}" class="text-brand-600 hover:underline">← Pozycje</a></p>
  <x-panel.page-header title="Dodaj frazy do monitorowania"
    :description="$project->name . ' · dodanie frazy nic nie kosztuje — koszt pojawia się dopiero przy pomiarze (zawsze z podglądem)'" />

  @if ($market === null)
    <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Rynek projektu nie jest obsługiwany — zmień kraj i język projektu.</div>
  @endif

  <x-panel.card class="max-w-3xl">
    <form method="post" action="{{ $base }}/keywords">
      <x-panel.nonce />
      <input type="hidden" name="source" value="manual">
      <label for="keywords" class="block text-sm font-medium text-slate-700">Frazy (po jednej w wierszu albo po przecinku)</label>
      <textarea id="keywords" name="keywords" rows="10" required placeholder="strony internetowe&#10;sklep internetowy warszawa"
        @if (isset($errors['keywords'])) aria-invalid="true" aria-describedby="keywords-error" @endif
        class="mt-1 block w-full rounded-md text-sm shadow-sm {{ isset($errors['keywords']) ? 'border-red-400 focus:border-red-500 focus:ring-red-500' : 'border-slate-300 focus:border-brand-500 focus:ring-brand-500' }}">{{ $old }}</textarea>
      @if (isset($errors['keywords']))
        <p id="keywords-error" class="mt-1 text-sm text-red-700">{{ $errors['keywords'] }}</p>
      @endif
      <p class="mt-1 text-xs text-slate-500">
        Monitorowane: {{ Format::number($tracked) }}, zalecany limit projektu: {{ Format::number($maxKeywords) }} (OSF_SEO_SERP_MAX_KEYWORDS).
        Wielkość liter i spacje nie mają znaczenia; operatory wyszukiwania (np. site:) są odrzucane. Wolumen i trudność SEO nie są pobierane automatycznie — pokażemy „—”.
      </p>
      <div class="mt-4 flex flex-wrap gap-2">
        <x-panel.button type="submit">Dodaj do monitorowania</x-panel.button>
        <x-panel.button variant="secondary" :href="PanelUrl::project($project->publicId, 'keywords')">Wybierz z fraz GSC</x-panel.button>
        <x-panel.button variant="secondary" :href="PanelUrl::project($project->publicId, 'discovery')">Wybierz z Nowych fraz</x-panel.button>
      </div>
    </form>
  </x-panel.card>
@endsection
