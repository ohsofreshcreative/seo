@extends('panel.layouts.guest')

@section('title', 'Logowanie')

@section('content')
  <x-panel.card>
    <h1 class="text-lg font-semibold text-slate-900">Zaloguj się</h1>

    @if (! empty($messages['error']))
      <div class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ $messages['error'] }}</div>
    @endif
    @if (! empty($messages['info']))
      <div class="mt-4 rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800" role="status">{{ $messages['info'] }}</div>
    @endif

    <form method="post" action="{{ \App\Panel\PanelUrl::to('login') }}" class="mt-6 space-y-5">
      <x-panel.nonce :action="$nonceAction" />
      @if ($redirectTo)
        <input type="hidden" name="redirect_to" value="{{ $redirectTo }}">
      @endif

      <x-panel.field name="log" label="Login lub e-mail" :value="$login" autocomplete="username" required autofocus />
      <x-panel.field name="pwd" label="Hasło" type="password" autocomplete="current-password" required />

      <label class="flex items-center gap-2 text-sm text-slate-600">
        <input type="checkbox" name="remember" value="1" class="rounded border-slate-300 text-brand-700 focus:ring-brand-500">
        Zapamiętaj mnie
      </label>

      <x-panel.button type="submit" class="w-full">Zaloguj</x-panel.button>
    </form>

    <p class="mt-6 text-center text-sm">
      <a href="{{ $lostPasswordUrl }}" class="font-medium text-brand-600 hover:text-brand-700">Nie pamiętasz hasła?</a>
    </p>
  </x-panel.card>
@endsection
