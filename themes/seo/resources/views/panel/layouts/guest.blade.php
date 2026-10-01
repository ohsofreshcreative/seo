@extends('panel.layouts.base')

@section('body')
  <main class="flex min-h-full items-center justify-center px-4 py-12">
    <div class="w-full max-w-md">
      <div class="mb-8 text-center">
        <span class="text-2xl font-semibold tracking-tight text-brand-700">OSF SEO</span>
        <p class="mt-1 text-sm text-slate-500">Panel SEO OhSoFresh</p>
      </div>

      @yield('content')
    </div>
  </main>
@endsection
