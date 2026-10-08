@extends('panel.layouts.base')

@section('body')
  <main class="flex min-h-full items-center justify-center px-4 py-12">
    <div class="w-full max-w-md">
      <div class="mb-8 text-center">
        <x-panel.brand :logo="$brandLogo" variant="guest" class="mx-auto" />
        <p class="mt-1 text-sm text-slate-500">Panel SEO OhSoFresh</p>
      </div>

      @yield('content')
    </div>
  </main>
@endsection
