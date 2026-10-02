{{-- Objaśnienia źródeł danych Luk SEO (wspólne dla widoków modułu). --}}
<div class="mt-8 space-y-1 text-xs text-slate-500">
  <p><strong class="font-medium text-slate-600">Pozycja konkurenta (Labs)</strong> pochodzi z bazy DataForSEO Labs (Ranked Keywords — migawka z daty podanej przy frazie), nie z naszego pomiaru SERP. Dokładne pozycje mierzy moduł „Pozycje”.</p>
  <p><strong class="font-medium text-slate-600">Widoczność projektu</strong> — z najlepszego dostępnego źródła: świeży pomiar SERP (do {{ $freshDays ?? 30 }} dni) → średnia pozycja (GSC) → punkt odniesienia z DataForSEO Labs. Sam brak frazy w GSC nie oznacza braku widoczności („Nieznana”).</p>
  <p><strong class="font-medium text-slate-600">Priorytet luki</strong> (0–100) to sygnał, czym warto zająć się najpierw — nie prognoza ruchu ani wartość biznesowa. <strong class="font-medium text-slate-600">Luka treści</strong> to heurystyka do sprawdzenia, nie diagnoza.</p>
</div>
