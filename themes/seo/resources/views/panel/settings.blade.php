@extends('panel.layouts.app', ['active' => 'settings'])

@section('title', 'Ustawienia')

@section('content')
  <x-panel.page-header title="Ustawienia" description="Stan aplikacji. Sekrety (np. klucze Google) konfiguruje się wyłącznie w wp-config.php." />

  <x-panel.card class="max-w-3xl">
    <dl class="divide-y divide-slate-100 text-sm">
      @foreach ([
        'Wersja pluginu' => $pluginVersion,
        'Schemat bazy' => $schemaVersion . ' (najnowszy: ' . $schemaLatest . ')',
        'Środowisko' => $environment,
        'PHP' => $phpVersion,
        'WordPress' => $wpVersion,
      ] as $label => $value)
        <div class="flex justify-between gap-4 py-3">
          <dt class="text-slate-500">{{ $label }}</dt>
          <dd class="font-medium text-slate-900">{{ $value }}</dd>
        </div>
      @endforeach
    </dl>
  </x-panel.card>
@endsection
