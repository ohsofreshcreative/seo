{{-- Stan zapisanej kopii strony (Page Intelligence): aktualna, starsza, nieudane pobranie, nie pobrano. --}}
@props(['cache'])
<span {{ $attributes->class(['inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', \App\Panel\PageLabels::cacheTone($cache)]) }}>{{ \App\Panel\PageLabels::cache($cache) }}</span>
