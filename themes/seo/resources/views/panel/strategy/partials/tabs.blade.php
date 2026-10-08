{{-- Nawigacja modułu Strategia. --}}
@php
  $base = \App\Panel\PanelUrl::project($project->publicId, 'strategy');
  $tabs = [
    'overview' => ['Przegląd', $base],
    'topics' => ['Backlog', $base . '/topics'],
    'serp' => ['SERP Intelligence', $base . '/serp'],
  ];

  if ($canAnalyze ?? false) {
    $tabs['analysis'] = ['Analiza SERP', $base . '/analysis'];
  }

  if ($canManage ?? false) {
    $tabs['settings'] = ['Ustawienia', $base . '/settings'];
  }
@endphp
<nav class="-mt-4 mb-6 flex flex-wrap gap-1 border-b border-slate-200 text-sm" aria-label="Strategia">
  @foreach ($tabs as $key => [$label, $href])
    <a href="{{ $href }}" @class([
      '-mb-px border-b-2 px-3 py-2 font-medium',
      'border-brand-600 text-brand-700' => $tab === $key,
      'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900' => $tab !== $key,
    ]) @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
  @endforeach
</nav>
