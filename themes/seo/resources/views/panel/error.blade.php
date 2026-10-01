@extends($layout === 'app' ? 'panel.layouts.app' : 'panel.layouts.guest')

@section('title', $title)

@section('content')
  <x-panel.card class="mx-auto max-w-xl text-center">
    <p class="text-sm font-semibold text-brand-600">{{ $status }}</p>
    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ $title }}</h1>
    <p class="mt-2 text-sm text-slate-600">{{ $message }}</p>
    <div class="mt-6">
      <x-panel.button :href="\App\Panel\PanelUrl::to()" variant="secondary">Wróć do panelu</x-panel.button>
    </div>
  </x-panel.card>
@endsection
