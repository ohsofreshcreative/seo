{{-- Status pracy nad tematem Strategii (zmienia go wyłącznie użytkownik — przeliczenie nigdy). --}}
@props(['status'])
@php
  $value = $status instanceof \OsfSeo\Strategy\Topics\TopicStatus ? $status : \OsfSeo\Strategy\Topics\TopicStatus::tryFrom((string) $status);
  $colors = [
    'new' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
    'review' => 'bg-violet-50 text-violet-700 ring-violet-600/20',
    'planned' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
    'in_progress' => 'bg-brand-50 text-brand-700 ring-brand-500/30',
    'completed' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'dismissed' => 'bg-slate-100 text-slate-500 ring-slate-500/20',
  ][$value?->value ?? ''] ?? 'bg-slate-100 text-slate-600 ring-slate-500/20';
@endphp
<span {{ $attributes->class(['inline-flex items-center whitespace-nowrap rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset', $colors]) }}>{{ $value?->label() ?? '—' }}</span>
