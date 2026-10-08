# AGENTS.md — Wibble (techniczna nazwa: OSF SEO)

Wspólne instrukcje dla Codex, GitHub Copilot i Claude Code. Edytuj wyłącznie ten plik —
`CLAUDE.md` tylko go importuje (`@AGENTS.md`), bez symlinku.

Komunikacja z użytkownikiem: **po polsku** (wyjaśnienia, pytania, podsumowania, raporty).

⸻

## 1. Czym jest projekt

**Wibble** (dawniej OSF SEO) to wewnętrzna aplikacja SEO agencji OhSoFresh (docelowo także panel klienta).
Działa jako osobna instalacja WordPress (staging: `https://seo.ohsofresh.top`) i składa się z:

- **pluginu `osf-seo`** (`plugins/osf-seo`) — cała logika biznesowa: projekty, Google OAuth,
  import Google Search Console, synchronizacja, analityka, uprawnienia, WP-CLI, REST,
- **motywu Sage 11 `seo`** (`themes/seo`) — wyłącznie UI panelu (routing Acorn, kontrolery,
  Blade, Tailwind, Alpine, Chart.js).

Od STEP 15 w nowych tekstach UI i dokumentacji produkt nazywa się **Whack-a-mole**; interfejs panelu (sidebar, tytuły kart, logowanie,
komunikaty) używa już tej nazwy, a administrator może ustawić logo aplikacji (Ustawienia → „Wygląd aplikacji”, `OsfSeo\Branding\BrandingService`,
D85 — tylko ID załącznika biblioteki mediów, PNG/JPG/WebP, bez SVG). Identyfikatory techniczne pozostają **celowo bez zmian**: plugin `osf-seo`
(także `Plugin Name: OSF SEO`), stałe `OSF_SEO_*`, tabele `osf_*`, namespace `OsfSeo\`, opcje/capabilities `osf_seo_*`, role i ich etykiety,
komendy `wp osf-seo …`, hooki WP-Cron, wyjście CLI i logi. Nie zmieniaj ich mimochodem — techniczny rebrand będzie osobnym, zaplanowanym etapem.

Źródła danych:
- **Google Search Console API** — źródło prawdy o skuteczności strony (kliknięcia, wyświetlenia, CTR,
  średnia pozycja GSC, strony docelowe, historia),
- **DataForSEO** — jedyny zatwierdzony płatny dostawca danych SEO (decyzja D3, od STEP 12): wolumen, historia
  wolumenu, CPC, konkurencja Ads, trudność SEO, intencja; od STEP 13 także wyszukiwanie nowych fraz (DataForSEO Labs
  Related Keywords i Keyword Suggestions, D29), od STEP 14 pomiary pozycji (Google Organic SERP, kolejka Standard, D36),
  od STEP 15 frazy domen konkurentów i projektu do Luk SEO (DataForSEO Labs Ranked Keywords, D43).
  Uzupełnia GSC, nigdy go nie zastępuje.

Płatne API wymagają jawnej decyzji architektonicznej (tabela decyzji w `docs/ARCHITECTURE.md`). Nie dodawaj
innych płatnych API (Semrush, Ahrefs, Senuto, SeoStation…) ani scrapowania wyników Google bez takiej decyzji.
Dostawców integruj wyłącznie za interfejsem domenowym (np. `OsfSeo\Market\KeywordMetricsProvider`,
`OsfSeo\Discovery\KeywordDiscoveryProvider`, `OsfSeo\Serp\SerpProvider`, `OsfSeo\Gap\CompetitorKeywordsProvider`).

Architektura, decyzje i roadmapa: **`docs/ARCHITECTURE.md`** — przeczytaj przed większą zmianą
i aktualizuj przy zmianie decyzji.

⸻

## 2. Repozytorium jest PUBLICZNE — sekrety

- Żaden sekret nie może trafić do kodu, testów, fixture'ów, dokumentacji, commitów ani logów:
  Google Client ID/Secret, klucz szyfrujący, tokeny OAuth (access/refresh), login i hasło API DataForSEO, klucze API dostawców AI (OpenAI),
  hasła, dane dostępowe Hostingera, klucze SSH, dane dostępowe do bazy.
- Sekrety konfigurujemy wyłącznie przez stałe w `wp-config.php` lub zmienne środowiskowe
  (np. `OSF_SEO_GOOGLE_CLIENT_ID`, `OSF_SEO_GOOGLE_CLIENT_SECRET`, `OSF_SEO_ENCRYPTION_KEY`,
  `OSF_SEO_DATAFORSEO_LOGIN`, `OSF_SEO_DATAFORSEO_PASSWORD`, `OSF_SEO_OPENAI_API_KEY`).
- Danych logowania DataForSEO nie proś w rozmowie, nie wypisuj, nie zapisuj w bazie ani repo, nie wysyłaj do JS/HTML;
  w testach i CI wyłącznie syntetyczne wartości (`tests/Support/DataForSeoFakes`) i atrapa HTTP — nigdy prawdziwe API.
- Przykłady tylko z placeholderami: `OSF_SEO_GOOGLE_CLIENT_ID=your-client-id`.
- Fixture'y odpowiedzi Google są syntetyczne — bez prawdziwych tokenów, e-maili i danych klientów.
- Ciągi przypominające sekrety w testach (prefiksy typu `ya29.`, `GOCSPX-`, nagłówki kluczy PEM)
  składaj w runtime z kawałków, żeby skanery sekretów nie zgłaszały fałszywych alarmów.
- Nie wypisuj (`var_dump`, `print_r`, `error_log`) surowych odpowiedzi OAuth ani danych z Google —
  logowanie wyłącznie przez logger pluginu, który redaguje sekrety.
- Nie odczytuj, nie wypisuj i nie używaj żadnych kluczy ani credentials znalezionych w repo
  lub w historii Git. Nie łącz się z serwerami (staging/produkcja).
- `.gitignore` w roocie jest whitelistą i blokuje typowe pliki z sekretami — nie osłabiaj go
  i nie dodawaj ignorowanych plików przez `git add -f`.
- Przed każdym commitem przejrzyj diff pod kątem sekretów.

⸻

## 3. Struktura repozytorium

Root repo = katalog `wp-content/` instalacji WordPress (LocalWP: `app/public/wp-content`).
Bez symlinków — każdy katalog leży dokładnie tam, gdzie oczekuje go WordPress.

| Ścieżka | Zawartość |
|---|---|
| `plugins/osf-seo/` | plugin (logika aplikacji): `osf-seo.php` (bootstrap), `src/` (namespace `OsfSeo\`), `tests/` (PHPUnit) |
| `themes/seo/` | motyw Sage 11 + Acorn 5 (namespace `App\` → `app/`), Vite, Tailwind 4 |
| `docs/ARCHITECTURE.md` | architektura, model danych, OAuth, synchronizacja, decyzje, roadmapa |
| `AGENTS.md`, `CLAUDE.md`, `README.md` | instrukcje i opis projektu |
| `.gitignore` | whitelist: wszystko poza powyższymi ścieżkami jest ignorowane |

Nowy katalog w roocie wymaga dopisania wyjątku `!/<ścieżka>` w `.gitignore`.

⸻

## 4. Stan motywu `themes/seo` (legacy do usunięcia)

Motyw powstał z marketingowego motywu `h2otwock` i wciąż zawiera jego kod: bloki ACF
(`app/Blocks`, `resources/views/blocks`), WooCommerce, CPT `offer`, marketingowy design system
(`resources/css/variables.scss`), GTM, Leaflet, GSAP, Swiper, jQuery, React.
**To kod przeznaczony do usunięcia** etapami C1–C5 (`docs/ARCHITECTURE.md`, sekcja 20).

- Nie rozwijaj go i nie kopiuj z niego wzorców (anatomia bloków ACF, `x-button`, `c-main`,
  `-smt`, `section-*`, atrybuty GSAP).
- Nie usuwaj go „przy okazji” — cleanup idzie osobnymi, testowanymi etapami, po akceptacji.
- Dawne zasady „bez mikrotypografii” i anatomia bloków ACF **nie obowiązują** panelu.
- Znane niespójności legacy (nie naprawiaj mimochodem):
  - `functions.php` i `app/Providers/ThemeServiceProvider.php` odwołują się do nieistniejącej
    klasy `App\Blocks\ExampleBlock`,
  - `app/setup.php` globuje nieistniejący katalog `app/Woo`,
  - `app/helpers.php` nie jest ładowany i duplikuje `get_pdf_thumbnail_url()` z `app/setup.php`,
  - `resources/views/blocks/posts.php` nie ma rozszerzenia `.blade.php`,
  - `package-lock.json`, `pnpm-lock.yaml`, `pnpm-workspace.yaml` są nieaktualne (aktualny: `yarn.lock`),
  - `.editorconfig` deklaruje spacje dla PHP, a kod PHP motywu jest pisany tabami.

⸻

## 5. Architektura — granice

- Logika, SQL, integracje, cron, uprawnienia → **plugin**. Motyw nie wykonuje zapytań SQL,
  nie woła Google i nie trzyma logiki biznesowej.
- Plugin to czysty PHP 8.2+: `$wpdb` (zawsze `prepare`), WP HTTP API (`wp_remote_*`), WP-CLI, REST.
  Bez Laravela/Acorn i bez Guzzle w pluginie. Zależności runtime z Composera tylko wtedy, gdy
  przewiduje je architektura (docelowo Action Scheduler — jeszcze nie dodany).
- Motyw korzysta z pluginu przez `osf_seo()` (kontener usług) — sprawdzaj `function_exists('osf_seo')`.
- Dane SEO wyłącznie we własnych tabelach `{$wpdb->prefix}osf_*` — nigdy w posts/postmeta/ACF.
- Dostęp do projektu wyłącznie przez `ProjectGuard` → `ProjectContext`; brak dostępu = 404
  (`ProjectNotFound`), brak uprawnienia do operacji na widocznym projekcie = 403 (`AccessDenied`).
  Usługi operujące na projekcie przyjmują `ProjectContext`, nigdy surowe ID. Nie dodawaj metod
  pobierających projekt po wewnętrznym ID bez filtra widoczności. Każda zmiana autoryzacji wymaga
  zielonego `ProjectAuthorizationTest` (test IDOR).
  `public_id` (ULID) tylko utrudnia enumerację — zabezpieczeniem zawsze jest autoryzacja.
- Uprawnienia sprawdzaj przez capabilities (`current_user_can`), nigdy przez nazwę roli.
- Google (STEP 5, `src/Google`): ruch do Google wyłącznie przez `OsfSeo\Http\HttpTransport`
  (WP HTTP API, w testach `pre_http_request`); refresh token tylko zaszyfrowany `TokenVault`,
  access token tylko w pamięci (`AccessTokenProvider`); żądania API przez `GoogleApi` (Bearer tylko do
  `https://*.googleapis.com`, po 401 jedno ponowienie). Kodów, weryfikatorów PKCE, `state` i tokenów
  nie loguj ani nie zapisuj; w testach używaj losowych fałszywych wartości (`tests/Support/GoogleFakes`).
- DataForSEO (STEP 12, `src/Market`, `src/DataForSeo`, `docs/ARCHITECTURE.md` sekcja 11): **płatne żądania wyłącznie
  z `MarketSyncService`** (plan → limity kosztów → zadanie) — nigdy z kontrolera, widoku, raportu, analizy szans ani
  importera GSC. Ruch tylko przez `HttpTransport` do `https://api.dataforseo.com/v3/`, Basic Auth budowany w chwili żądania.
  Każda zmiana musi zachować bezpieczniki: plan bez API (dry-run), TTL, limit zadań na przebieg, limity dzienny i miesięczny,
  brak automatycznego startu po wdrożeniu (automatyka tylko po pierwszej jawnej synchronizacji), brak ponawiania płatnego POST
  po timeoucie. Zadania DataForSEO nie trafiają do `sync_runs` (kolejka GSC). Dane rynkowe są wspólne dla rynku
  (`market_keywords`: dostawca × lokalizacja × język × klucz frazy) i nie należą do danych GSC projektu (reset property ich nie usuwa).
- Nowe frazy (STEP 13, `src/Discovery`, `docs/ARCHITECTURE.md` sekcja 12): **płatne żądania wyłącznie z `DiscoveryRunner`**
  (krok w tle po kolejce GSC albo `wp osf-seo discovery:run`) pod wspólną blokadą `MarketSyncService::LOCK` i wspólnymi limitami
  dziennym i miesięcznym (zadania w `market_tasks` z `trigger_type = discovery`) — bez drugiego budżetu. Kontroler i widok tylko
  planują (bez API) i kolejkują przebieg po potwierdzeniu planu (`expected_requests`, `expected_cost`). Każda zmiana musi zachować:
  plan bez API z maksymalnym kosztem, obowiązkowy limit kandydatów, jeden aktywny przebieg na projekt, cache seedów (TTL), `force`
  tylko z `osf_seo_manage_keyword_discovery`, brak ponawiania żądania, które mogło zostać opłacone, brak żądań po anulowaniu.
  Kandydat wskazuje `market_keywords` (metryk nie kopiujemy); metryki z odpowiedzi zapisujemy tylko przy braku lub po TTL.
  Kandydaci, źródła i decyzje nie należą do danych GSC (reset property ich nie usuwa — tylko widoczność wraca do „Nieznana”).
- Pozycje SERP (STEP 14, `src/Serp`, `docs/ARCHITECTURE.md` sekcja 13): **płatne zlecenia wyłącznie z `SerpSubmitter`** (krok w tle
  po kolejce GSC albo `wp osf-seo serp:run`) pod wspólną blokadą `MarketSyncService::LOCK` i wspólnymi limitami (zlecenia w `market_tasks`,
  `endpoint = google_organic_serp`). Kontroler i widok tylko planują (bez API) i kolejkują pomiar po potwierdzeniu planu (`expected_tasks`,
  `expected_cost`). Każda zmiana musi zachować: plan bez API z **szacowanym maksymalnym** kosztem (zgłoszony przez dostawcę rozstrzyga),
  rezerwację kosztu przy zakolejkowaniu (cały pomiar albo wcale), harmonogram domyślnie wyłączony i włączany tylko z potwierdzeniem,
  `UNIQUE (project_id, slot_key)`, okno ponownego sprawdzenia frazy, odstęp pomiaru ręcznego, oznaczenie `uncertain` przed wysłaniem
  i brak ponawiania zlecenia o nieznanym wyniku (odzyskanie po `tag`), brak automatycznego monitorowania wszystkich fraz, brak płatnych opcji
  (`priority`, `calculate_rectangles`, AI Overview, klikanie PAA) bez decyzji. Pełne TOP N zapisujemy dla wszystkich domen (bez surowego
  JSON-a, bez automatycznego usuwania historii); konkurenci tylko z zapisanych SERP-ów. Limit fraz `OSF_SEO_SERP_MAX_KEYWORDS` jest
  miękki (komunikat, bez obcinania) — nie zakładaj nigdzie 500 fraz. Tabele SERP nie należą do danych GSC (reset property ich nie usuwa).
- Luki SEO (STEP 15, `src/Gap`, `docs/ARCHITECTURE.md` sekcja 14): **płatne żądania wyłącznie z `GapImporter`** (krok w tle po Nowych frazach
  albo `wp osf-seo gap:run`) pod wspólną blokadą `MarketSyncService::LOCK` i wspólnymi limitami (żądania w `market_tasks`, `trigger_type = gap`,
  endpoint `labs_ranked_keywords`). Kontroler i widok tylko planują (bez API) i kolejkują import po potwierdzeniu planu (`expected_requests`,
  `expected_cost`). Każda zmiana musi zachować: plan bez API z maksymalnym i oczekiwanym kosztem, limit kosztów sprawdzany **przed każdą
  stroną** (pauza i automatyczne wznowienie, bez obchodzenia limitów), brak ponawiania niepewnego żądania (sieć, 5xx, niepoprawna odpowiedź,
  przerwane w locie), jeden aktywny przebieg na projekt, harmonogram domyślnie wyłączony i włączany tylko z potwierdzeniem kosztu, wspólne
  zbiory domen (ponowne użycie świeżego zbioru bez opłaty; zbiór importuje naraz jeden przebieg — `claimImport`, utrzymanie i domykanie importu
  tylko pod `MarketSyncService::LOCK`). **Stronicowanie Ranked Keywords tylko `limit` + `offset` (maks. 10 000 fraz na
  domenę)** — bez niepotwierdzonych obejść; nieobecność frazy wiarygodna tylko od `covered_min_volume` × zapas 1,5 (przycięty import, niespójne
  strony — `gap_run_targets.unreliable` → mniej albo brak „lost” i „Brak widoczności”). Odświeżenie przerwane, wstrzymane albo niespójne nie
  zapisuje „lost” i zostawia zbiorowi stan poprzedniego udanego importu. Historia zbiorów (new / lost / back / url / up / down) nie jest drugim rank
  trackerem — monitoring pozycji pozostaje w STEP 14. Pełne przeliczenie luk (`GapRefresher`) nigdy w żądaniu WWW ani przy renderowaniu
  (panel tylko unieważnia klucz danych). Globalnych limitów kosztów DataForSEO nie zmieniaj bez decyzji właściciela.
- Strategia (STEP 16, `src/Strategy`, `docs/ARCHITECTURE.md` sekcja 15): **warstwa decyzyjna nad modułami — nie zastępuje Szans SEO, Nowych fraz,
  Luk SEO ani Pozycji** i nie kopiuje ich danych (metryki rynkowe, historia GSC i SERP tylko odwoływane). Kandydat = fraza rynkowa projektu
  (`market_keywords`, `UNIQUE (project_id, market_keyword_id)`); źródła wyłącznie przez adaptery `OsfSeo\Strategy\CandidateSource`, z respektowaniem
  decyzji modułów (odrzucone nie wracają tym źródłem), filtrami marki i wykluczeń oraz limitem `OSF_SEO_STRATEGY_MAX_KEYWORDS` (nadmiar liczony,
  bez cichego pomijania). Klucz danych przeliczenia musi obejmować także mutacje ręczne wszystkich modułów i `strategy_settings.revision` —
  nie tylko czas importu. Przeliczenie wyłącznie w CLI albo w tle, nigdy przy renderowaniu. Powiązanie szansa SEO ↔ fraza tylko przez
  `Opportunities\OpportunityKeywordIndex` (dane query × page) — nigdy przez samo `opportunities.keyword`; wspólna podstrona to kontekst, nie dowód
  (osobno od powiązań bezpośrednich), a przycięta lista fraz grupy jest jawnie oznaczona (`members_complete`). Płatna analiza SERP (faza B, `src/Strategy/Serp`,
  sekcja 15.12) wyłącznie przez `SerpSubmitter` STEP 14 (`SerpAnalysisService`), po podglądzie i potwierdzeniu, we wspólnych limitach i ze wspólnym
  odstępem pomiaru ręcznego; świeży (≤ 30 dni) zgodny pomiar jest używany ponownie bez kosztu; najwyżej `OSF_SEO_STRATEGY_SERP_MAX_PER_RUN` nowych
  pomiarów bez cichego obcinania; nieudana rezerwacja wycofuje przygotowane frazy. Fraza `analysis` jest poza listą i licznikami Pozycji, harmonogramem
  i miękkim limitem (zapytania modułu Pozycji filtrują `status = 'active'` — nie zmieniaj tego mimochodem), ale jest dowodem widoczności dla Luk SEO;
  dodanie do monitorowania zmienia ją na `active` bez utraty historii. Bez drugiego magazynu SERP (profile `serp_snapshot_profiles` to pochodna
  wersjonowanych reguł); Pozycja SERP projektu tylko ze świeżego pomiaru (31–90 dni — niższa pewność, > 90 — bez interpretacji); silny overlap tylko
  wg D64. Bez automatycznego harmonogramu, PAA/related searches, nowych płatnych endpointów, crawlera i AI w STEP 16 bez nowej decyzji.
  Rdzeń (faza C, `src/Strategy/Target`, `Topics`, `Decision`, sekcja 15.13): strona docelowa tylko przez `TargetPageResolver` — rodzina dowodów liczy
  się raz (szansa SEO, Nowe frazy i luka ze źródłem SERP/GSC to dowody pochodne GSC/SERP), SERP według pozycji (TOP20 silnie, 21–50 średnio, > 50 słabo),
  Labs najwyżej średnio, dopasowanie adresu zawsze słabo; „brak znanej strony” wymaga dodatkowego dowodu braku widoczności (nigdy sam brak GSC).
  Tematy: porównanie tylko z liderem, scalenie automatyczne wyłącznie przy tej samej znanej stronie (nie strona główna) albo silnym overlapie bez
  konfliktu stron; frazy przypięte poza grupowaniem automatycznym; stabilne ID (`TopicIdentity`, ≥ 50%). Działania w kolejności consolidate → recover →
  optimize → create → monitor → investigate; „create” to wyłącznie „Kandydat na nową stronę” z twardymi bramkami i pewnością najwyżej średnią bez
  pełnego `ProjectPageIndex` albo ręcznego potwierdzenia; konsolidacja tylko przy silnym sygnale konfliktu URL (pojedyncza zmiana adresu → investigate).
  Priorytet Strategii (`PriorityModel`) to ograniczone składniki × mnożnik pewności — monotoniczny, bez kopiowania wyników modułów; brak KD ≠ KD 0.
  **Przeliczenie nigdy nie zmienia statusu pracy tematu** (tylko flaga `decision_changed`); zdarzenia wyłącznie istotnych zmian; pakiet kontekstu
  (`TopicContextBuilder`) deterministyczny, dane zewnętrzne oznaczone jako niezaufane. Nie zmieniaj progów overlapu ani heurystyk kształtu z fazy B
  bez kalibracji na prawdziwych danych.
  Panel (faza D, `themes/seo` — kontrolery `Strategy*Controller`, widoki `panel/strategy`, sekcja 15.14): czyta wyłącznie zapisany stan (kolumny tematu
  z M0013, filtry i stronicowanie w SQL, bez zapisu profili SERP); „Przelicz” tylko zleca przeliczenie (`requestRefresh`) — pełne przeliczenie nigdy
  w żądaniu WWW (wykonuje CLI, od fazy E krok w tle); płatna analiza SERP wyłącznie podgląd → potwierdzenie → `SerpAnalysisService::start`
  (obie capabilities sprawdzane w trasie, kontrolerze i usłudze); odnośniki z modułów: szansa SEO → temat tylko przez dowód `opportunity.direct`;
  klient bez odrzuconych tematów, notatek, identyfikatorów użytkowników, ustawień, kosztów i analizy SERP (egzekwowane w usłudze).
  Tło (faza E, `StrategyRefreshQueue`, `StrategyRefreshRunner`, `StrategyScheduler`, sekcja 15.15): **krok w tle automatyzuje wyłącznie lokalne
  przeliczenie zapisanych danych** — nigdy pomiarów SERP, Labs, wyszukiwania fraz, metryk ani rozszerzania monitorowanych fraz; limity kosztów bez
  zmian. Zadanie = stan w wierszu `strategy_settings` (M0014, jedno na projekt, bez nowej tabeli kolejki i bez Action Scheduler); wykonawca to ostatni
  krok `SyncScheduler::onAfterRun` w czasie pozostałym z limitu ticka (`remainingBudget`, D63). Blokada `StrategyRefresher::lock()` trzymana przez
  przejęcie → `refreshLocked` → zakończenie (także CLI `strategy:refresh`); przejęcie tylko warunkowym UPDATE; `running` bez blokady = przerwany proces.
  Ponowienia: 3 próby (1 min → 5 min), potem `failed` do zmiany danych albo ręcznego zlecenia; w stanie tylko kod błędu (szczegóły w logu). Zlecenie
  w trakcie przeliczenia nie może zginąć (`refresh_requested_at` > początek przeliczenia → kolejne przeliczenie). Wykrywanie zmian z debounce
  (stabilny klucz 120 s, brak oczekujących zadań GSC, maks. 60 min) — nie przeliczaj po każdym zapisie importu i nie dopuść do cyklu przeliczenie →
  unieważnienie → przeliczenie (przeliczenie nie zmienia klucza danych). Projekt w tle wyłącznie przez `ProjectGuard::authorizeSystem` z identyfikatora
  zapisanego w bazie. Panel: statusy z `panelState()['job']`, endpoint `GET /strategy/status`, klient bez kodu błędu i stanu kroku w tle.
- Analizy AI (STEP 17, `src/Ai`, `docs/ARCHITECTURE.md` sekcja 22): **wywołanie modelu wyłącznie z `AiAnalysisService`** — jawnie z CLI
  (`run`, `generate`) albo z kolejki zleceń zatwierdzonych w panelu (`queue` → krok w tle `runQueued`, faza D, sekcja 25.4) — nigdy przy
  renderowaniu panelu, gotowości, podglądzie, otwarciu tematu, przeliczeniu Strategii ani synchronizacji GSC; tło nigdy samo nie zleca analiz
  (bez automatycznego harmonogramu AI; `maintenance` tylko porządkuje historię). Dostawcy wyłącznie za `OsfSeo\Ai\Provider\AiProvider` (adapter = transport
  i format; `FakeProvider` koszt 0, `OpenAiProvider` — Responses API, `store: false`, `strict` JSON Schema, bez ponowień); nowe płatne API AI
  tylko z decyzją. Każda zmiana musi zachować: wyłącznik domyślnie wyłączony, **brak modelu i cen w kodzie** (tylko `OSF_SEO_AI_*`; bez cen →
  odmowa), budżet AI oddzielny od DataForSEO (limity DataForSEO bez zmian) z limitami domyślnie 0, rezerwację kosztu maksymalnego pod
  `GET_LOCK ai_budget` przed wywołaniem, rozliczenie z `usage`, wynik niepewny = cała rezerwacja, potwierdzenie płatnego uruchomienia,
  uprawnienie `osf_seo_manage_ai` (tylko administratorzy). Klucz `OSF_SEO_OPENAI_API_KEY` czytany w chwili żądania — nigdy w polu obiektu, bazie,
  logach, wyjątkach, HTML, JS ani odpowiedziach; bez formularza z jawnym sekretem; w testach tylko `AiFakes::apiKey()` i atrapa HTTP.
  Kontekst (`TopicContextAssembler`, wersja 2 od fazy B — rozszerzaj istniejący, nie twórz nowego) wyłącznie z pakietu kontekstu STEP 16, odczytów
  Strategii i zapisanych snapshotów stron (`PageEvidenceSource`, zero HTTP) w obrębie `ProjectContext` — bez nowej
  logiki Strategii, notatek, użytkowników i innych projektów; pozycje rozdzielone (`average_position_gsc`, `serp_rank_group`, `rank_labs`),
  proweniencja sekcji, jawne braki danych (treść strony niepobrana, indeks stron niepełny — „brak znanej strony” ≠ brak strony), budżet 32 KB
  z redukcją całych elementów (JSON nigdy nie ucinany), odcisk bez czasu budowania. Instrukcje wersjonowane (`PromptTemplate::VERSION` — zmiana
  treści = nowa wersja); dowody i treści zewnętrzne w osobnych blokach JSON (escapowane `<`, `>`); odpowiedź zapisywana jako wynik wyłącznie po
  `OutputValidator` (odwołania tylko z `refs` kontekstu, `evidence` z odwołaniem, bez prognoz liczbowych i obietnic) — inaczej `invalid`.
  Historia (`ai_runs` + `ai_run_payloads`, M0015) nigdy nie zmienia Strategii ani statusu pracy; decyzja użytkownika osobno od wyniku.
- Rekomendacje AI i briefy SEO (STEP 17 faza C, `src/Ai/Analysis`, `docs/ARCHITECTURE.md` sekcja 24): trzy typy (`page_optimization`,
  `new_page_brief` — zawsze „Kandydat na nową stronę”, `content_gap`) na **tej samej ścieżce wykonania i budżecie** co analiza tematu
  (`AiAnalysisService::generate` → wspólne `execute`; typ w `ai_runs.task`) — bez drugiego systemu budżetowego, drugiego silnika Strategii i nowego
  fetchera. Każda zmiana musi zachować: zgodność z działaniem Strategii wyłącznie przez `ActionCompatibility` (`allowed` / `explicit` / `blocked`,
  AI nigdy nie zmienia działania ani statusu pracy — niezgodność tylko jako ustalenie); gotowość (`ReadinessEvaluator`, czysta funkcja zapisanego
  źródła) — INSUFFICIENT / BLOCKED bez żadnego wywołania, PARTIAL z obowiązkiem ujawnienia ograniczeń, brakujące dane tylko jako wskazówki komend
  (nigdy automatyczne `pages:fetch`, SERP ani DataForSEO); kontekst v3 tylko dla tych typów (v2 analizy tematu bez zmian), bez diagnostyki parsera
  i stosunku tekstu do HTML, etykiety interfejsu i sekcje bez treści (`thin_section`) poza porównaniami, kolejność redukcji typu (luka treści nigdy
  bez stron konkurencji); instrukcje `AnalysisPrompts` wersjonowane per typ (zmiana treści = nowa wersja), język z projektu; wynik wyłącznie po
  `RecommendationValidator` (kontrakt v2: odwołania tylko z kontekstu, `fact` nie z samych heurystyk, brak treści nigdy faktem, bez prognoz,
  obietnic, „Google wymaga”, celów liczby słów, kopiowania treści konkurencji, keyword stuffingu, przekierowań / canonical / noindex / usuwania
  bez kontroli ręcznej, Content Score) — inaczej `invalid`; płatne generowanie tylko z potwierdzeniem **i** zatwierdzonym odciskiem planu
  (`AiPlan::fingerprint`, ponowna weryfikacja przy wykonaniu → `plan_changed`), blokada zlecenia projekt × temat × typ (`run_in_progress`),
  duplikat planu → `already_generated` (chyba że `--repeat`), bez automatycznych ponowień i „naprawiania” JSON-a. Historia: M0017
  (`plan_fingerprint`, `readiness`, `sources`), aktualność wyniku przez odcisk dowodów (wynik nieaktualny tylko oznaczany). Nie zmieniaj reguł
  walidatora ani progów etykiet interfejsu bez kalibracji na prawdziwych wynikach. Testy bez prawdziwego klucza (`FakeProvider`, atrapa HTTP);
  fixture'y w stylu realnego testu OhSoFresh tylko syntetyczne (`AiFakes::ohSoFreshHtml`) — nie pobieraj prawdziwej strony.
- Page Intelligence (STEP 17 faza B, `src/PageIntelligence`, `docs/ARCHITECTURE.md` sekcja 23): **żądania do stron wyłącznie z
  `PageIntelligenceService::fetch`** (jawnie, `osf_seo_manage_page_intelligence` — tylko administratorzy; klient tylko odczytuje) — nigdy przy
  renderowaniu, odczycie tematu, przeliczeniu Strategii ani budowaniu kontekstu AI; w kroku w tle wyłącznie retencja (D101) i pozycje jawnie
  zleconych `page_jobs` (faza D, D112 — ten sam `fetch`, bez crawlera i bez pobrań po przeliczeniu Strategii). Transport wyłącznie
  `CurlPageFetcher`: każdy hop ręcznie — `UrlSafetyPolicy` (składnia, zakres, DNS i kontrola każdego IP), połączenie tylko z przypiętym IP
  (`CURLOPT_RESOLVE` + `CURLINFO_PRIMARY_IP`), TLS względem oryginalnej nazwy hosta, bez proxy i ciasteczek, limity czasu, rozmiaru (po dekompresji),
  typu i przekierowań; **nie używaj WordPress HTTP API do pobierania stron, nie wyłączaj TLS i nie dodawaj „trybu bez przypięcia”** — bez
  bezpiecznego transportu odmowa. Zakres: domena projektu, domeny konkurentów projektu, dokładne adresy organicznych wyników zapisanego SERP-u
  projektu; bez automatycznego pobierania SERP-u i crawlera. Każda zmiana musi zachować: robots.txt (RFC 9309), limity hosta wspólne dla projektów
  (odstęp, limit dzienny, `Retry-After`), pobrania po kolei z blokadami strony i hosta, brak ponowień, dane wyłącznie w obrębie projektu (ten sam
  adres w dwóch projektach = osobne snapshoty), brak surowego HTML w bazie, nowy snapshot tylko przy zmianie odcisku treści, nieudane pobranie
  nigdy nie nadpisuje ostatniego poprawnego snapshotu. Indeksowalność tylko z dyrektyw (`indexable_by_directives` / `blocked_by_directives` /
  `unknown`) — nigdy „zaindeksowana w Google”; brak treści w pobranym HTML, timeout, 403 czy 404 nie dowodzą braku strony ani treści
  („W pobranym HTML nie wykryto…”). Reguły Strategii (`TargetPageResolver`, klasyfikator) nie korzystają ze snapshotów bez osobnej decyzji (D103).
  Treści stron w kontekście AI wyłącznie w bloku niezaufanym; budżet 32 KB bez zwiększania bez pomiaru. Testy transportu na lokalnych serwerach
  (`FixtureServers`, `FixtureNetwork` — 127.0.0.1 „publiczny”, 127.0.0.2 blokowany) — atrapa WP HTTP nie jest dowodem ochrony transportowej.
- Panel AI i Page Intelligence (STEP 17 faza D, `src/Ai/Workspace`, `PageJobService`, kontrolery `AiController`, `PagesController`,
  widoki `panel/ai`, `panel/pages`, `docs/ARCHITECTURE.md` sekcja 25): **odczyt i przygotowanie bez żadnych żądań** (`AiWorkspaceService` — sekcja
  tematu z jednego źródła, plan fazy C, historia, raport); **wykonanie wyłącznie w kroku w tle po jawnym zleceniu**: analizy przez kolejkę
  `ai_runs.status = queued` (`AiAnalysisService::queue` → `runQueued`, D111 — zawsze zatwierdzony odcisk planu, rezerwacja kosztu przy
  zakolejkowaniu, plan przeliczany ponownie przed wywołaniem, inny odcisk → `plan_changed` bez wywołania, jedno wywołanie bez ponowień, niepewny
  wynik nigdy ponawiany), strony przez `page_jobs` (M0018, `PageJobService`, D112 — wyłącznie `PageIntelligenceService::fetch`, maks. 5 adresów,
  całe zlecenie odrzucane przy adresie spoza zakresu, odstęp hosta → termin kolejnej próby zamiast czekania). Nie dodawaj drugiej kolejki,
  frameworka kolejek, harmonogramu AI ani pobrań po przeliczeniu Strategii. Kontroler nie przyjmuje kosztu z formularza (tylko odcisk planu).
  Typ `explicit` — osobne potwierdzenie w formularzu i zapis w uruchomieniu; Strategia i status pracy bez zmian. Historia jednym zapytaniem
  (tani wskaźnik `sources.strategy_hash` vs. `strategy_topics.evidence_hash`, D114) — nie odbudowuj kontekstu dla wierszy listy. Raport tylko
  z wyniku zwalidowanego (`AiReport`, etykiety dowodów `EvidenceLabels`, eksport `AiReportText` — bez kosztów, modelu, kodów i JSON-a). Klient
  (bez `osf_seo_manage_ai`): wyłącznie gotowe, nieodrzucone analizy tematów widocznych w Strategii, bez kosztów, dostawcy, modelu i błędów;
  strony bez kodów błędów i historii pobrań (egzekwowane w usłudze). Etykiety UI bez kodów technicznych (`ReportLabels`, `App\Panel\PageLabels`).
- `$wpdb` traktuje tabelę z kolumnami ascii i utf8mb4 bez kolumny binarnej jako ASCII i odrzuca zapytania z polskimi znakami
  („contains invalid data”) — w nowych tabelach z tekstem użytkownika daj co najmniej jedną kolumnę `*_bin` / binarną albo zapisuj
  tekst przez `insert()`/`update()`. Frazy liczbowe („2024”) jako klucze tablic PHP stają się int — rzutuj na `(string)`.

⸻

## 6. Reguły domenowe Google Search Console (łatwo je pomylić)

- Szanse SEO (`src/Opportunities`, `docs/ARCHITECTURE.md` sekcja 10) to sygnały do sprawdzenia, nie gwarancja wzrostu:
  rekomendacje formułuj jako hipotezy („Sprawdź…”), nigdy jako diagnozę ani obietnicę; progi tylko w `OpportunityConfig`.
- Pozycja z GSC to **średnia pozycja**. W UI: „Średnia pozycja (GSC)” — nigdy „pozycja w Google”.
  Widoki TOP 3/10/20/50/100 oraz wzrosty/spadki muszą jasno komunikować, że to dane oparte
  na średniej pozycji GSC, a nie dokładny SERP rank tracking.
- Agregacja pozycji: `SUM(position_sum) / SUM(impressions)`, gdzie `position_sum = position × impressions`.
  Nigdy `AVG(position)`.
- CTR zawsze `clicks / impressions` — nigdy średnia z CTR.
- Niższa pozycja = lepiej. `zmiana = pozycja_poprzednia − pozycja_obecna`; wynik dodatni = wzrost
  (15 → 7 = +8, ↑), ujemny = spadek.
- Sumy kliknięć i wyświetleń projektu pochodzą z zapytania `[date]` (`site_daily`), nie z sumy
  fraz (GSC pomija frazy zanonimizowane).
- Daty GSC są w czasie pacyficznym (PT) — nie przeliczaj ich na Europe/Warsaw.
- Import jest idempotentny: zamiana zakresu dat w transakcji (DELETE zakresu + INSERT).
- Dane rynkowe (DataForSEO) nie zmieniają metryk GSC. Rozdzielaj pojęcia i etykiety: „Średnia pozycja (GSC)” ≠ dokładna
  pozycja SERP; „Trudność SEO” (Keyword Difficulty) ≠ „Konkurencja Ads” (płatne wyniki) — nigdy samo „Konkurencja”;
  „Wolumen” = średnia miesięczna liczba wyszukiwań; „CPC” w USD. Brak danych rynkowych = NULL, w UI „—”, nigdy 0.
- Tożsamość frazy GSC (`keywords.keyword_hash`, dokładne bajty) jest niezmienna; dane rynkowe mają osobny klucz
  (`MarketKeyword`: NFC, małe litery, spacje). Nie wiąż danych rynkowych z `keywords.id` (ID słownika są jednorazowe).
- **Pozycja SERP** = `rank_group` najlepszego wyniku **organicznego** rodziny domeny projektu (domena + subdomeny, dopasowanie na granicy
  etykiet — nigdy podciąg) w ostatnim pomiarze. `rank_absolute` tylko pomocniczo; wyróżniony fragment osobno, nigdy jako #1; „Poza TOP100”
  ≠ 0. Zmiana tylko względem poprzedniego pomiaru w tym samym kontekście (lokalizacja, język, urządzenie, głębokość), inaczej
  „Nieporównywalne”; wartość liczbowa nigdy z pustej pozycji. Pozycja SERP i „Średnia pozycja (GSC)” to osobne kolumny i metryki.
- Nowe frazy: deduplikacja wyłącznie po kluczu rynkowym — bez stemmingu i lematyzacji („strona internetowa” ≠ „strony internetowe”).
  Widoczność GSC kandydata (Nieznana / Brak / Słaba / Już widoczna) liczona po `keywords.market_key` z `SUM(position_sum) / SUM(impressions)`,
  progi tylko w `DiscoveryConfig`. Wynik 0–100 to „Priorytet” (priorytet odkrycia — sygnał do sprawdzenia), nigdy „wartość biznesowa”
  ani prognoza ruchu; formuła w `DiscoveryScorer` (sekcja 12.7), CPC z małą wagą. Intencja tylko z odpowiedzi dostawcy, bez lokalnej
  heurystyki; strona docelowa tylko z GSC, inaczej „Brak przypisanej strony”. Bez AI i bez wbudowanych seedów czy wykluczeń.
- Strategia: **brak widoczności (GSC, Labs, SERP) nigdy nie dowodzi braku strony w witrynie**. Działanie „create” to wyłącznie
  „Kandydat na nową stronę” — wysoka pewność luki strukturalnej tylko z indeksem stron projektu (`ProjectPageIndex`) albo ręcznym potwierdzeniem;
  dane niejednoznaczne → „investigate”. Fakty GSC kandydata: NULL = brak danych GSC projektu, 0 = dane są, fraza bez wyświetleń (to nie dowód braku
  widoczności). „Widoczność GSC” z Nowych fraz (D35) nie jest dowodem nieobecności. Tematy scalamy automatycznie tylko przy zgodnej stronie docelowej,
  silnym overlapie SERP albo decyzji ręcznej — `tokenKey` i `core_key` to sygnały pomocnicze.
- Luki SEO: **Keyword Gap** (luki fraz) i **Content Gap** (luki treści) to osobne pojęcia. Pozycja konkurenta pochodzi z bazy DataForSEO Labs
  (migawka z datą) — w UI zawsze „(Labs)”, nigdy jako nasza Pozycja SERP. Widoczność projektu: świeży pomiar SERP → GSC → punkt odniesienia Labs;
  **sam brak frazy w GSC nigdy nie oznacza braku widoczności** (wtedy „Nieznana”); brak frazy w punkcie odniesienia oznacza „Brak widoczności”
  tylko w spójnym zbiorze pełnego TOP100 i z wolumenem z zapasem nad granicą (`GapDomain::provesNoVisibility()`). Wynik 0–100 to „Priorytet luki” (sygnał do sprawdzenia),
  formuła w `GapScorer` (sekcja 14.9). Luka treści to heurystyka z powodem i pewnością — etykiety wyłącznie „Potencjalna luka treści”,
  „Istniejąca strona — do wzmocnienia”, „Bez luki treści”, „Niejasne”; nigdy „projekt potrzebuje nowej strony”. Frazy markowe, wykluczone,
  poniżej progów — `listed = 0` z powodem (nie usuwamy); status pracy przetrwa przeliczenie. Inny język według dostawcy
  (`is_another_language`) to tylko informacja przy frazie („inny język”), nigdy filtr luk — na rynku PL frazy angielskie są normalnymi zapytaniami. Bez AI, stemmingu i crawla konkurencji.

⸻

## 7. Konwencje kodu

- Kod, identyfikatory, nazwy plików, tabel i pól: po angielsku. Nie tłumacz istniejących identyfikatorów.
- UI, etykiety, komunikaty w panelu i adminie, komentarze sekcyjne: po polsku.
- PHP: tabami, `declare(strict_types=1);` w nowych plikach pluginu, typy wszędzie, zgodność z PHP 8.2
  (bez składni i funkcji 8.3+: typowane stałe klas, `#[\Override]`, `json_validate()`, property hooks,
  `array_find()` itd.).
- Blade / JS / CSS: 2 spacje, LF, końcowy newline, single quotes.
- Komentarze tylko tam, gdzie wyjaśniają nieoczywiste decyzje — nie tłumacz kodu na prozę.

## 7a. Plugin `osf-seo` — konwencje

- `osf-seo.php` musi się parsować na starym PHP (kontrola wersji przed załadowaniem kodu 8.2) —
  nie dodawaj tam składni nowszej niż PHP 7.2.
- Autoloader własny (`src/Autoloader.php`, PSR-4 `OsfSeo\` → `src/`): plugin działa bez
  `composer install` na serwerze. Composer służy tylko narzędziom dev (PHPUnit); `config.platform.php` = 8.2.
- Usługi rejestruj wyłącznie w `Plugin::createContainer()`; dostęp: `osf_seo()->get(Klasa::class)`.
- Uprawnienia: stałe w `Auth\Capabilities`, role w `Auth\Roles`, synchronizacja `Auth\RoleManager::sync()`
  (aktywacja + zmiana `Plugin::VERSION`). Nowe capability dopisz w `Capabilities::all()` i podbij wersję.
- Aktywacja/aktualizacja: `Setup\Installer` (idempotentny). Dezaktywacja niczego nie usuwa.
  Destrukcyjne operacje na danych tylko jawnie (opt-in) i po akceptacji.
- `Plugin::VERSION` = nagłówek `Version` w `osf-seo.php` (pilnuje tego test).
- Klasy z logiką testowalną bez WordPressa; dostęp do API WP za cienkim adapterem (wzór: `Auth\RoleStore` / `WpRoleStore`).
- Logowanie: `osf_seo()->logger()` (poziom: `OSF_SEO_LOG_LEVEL`); `Support\Redactor` maskuje sekrety —
  wrażliwe dane w kontekście pod kluczami `*_token`, `*_secret`, `*_password`, `code`, `authorization` itd.
- WP-CLI: komendy w przestrzeni `wp osf-seo …`; wyjście i logi po angielsku (jak rdzeń WP-CLI).
  Formaty maszynowe (`--format=json`) bez dodatkowych komunikatów.
- Baza: wyłącznie przez `Database\Connection` (prepare zawsze, błędy SQL jako wyjątki). Tabele
  `Connection::table('nazwa')` → `{prefix}osf_nazwa`. Wartości `NULL` przez `insert()`/`update()`, nie przez prepare.
- Schemat: nowa zmiana = nowa migracja `src/Database/Migrations/MNNNN*.php` (dopisana w
  `Migrator::defaultMigrations()`) + aktualizacja `Database\Schema`. Migracje po wdrożeniu są niezmienne,
  idempotentne i nie usuwają danych. Test `MigratorTest` pilnuje zgodności migracji ze specyfikacją.

## 8. Panel (UI w motywie) — konwencje

Fundament panelu powstał w STEP 4 (`docs/ARCHITECTURE.md`, sekcje 4.1–4.4). Obowiązują proste zasady:

1. UI po polsku; kod, trasy, klasy, pola i tabele po angielsku.
2. Standardowe utilities Tailwind bez ograniczeń legacy (`text-sm`, `font-medium`, `rounded-*` itd.),
   gdy są uzasadnione UI. Nie komplikuj design systemu.
3. Powtarzalne elementy → komponenty Blade `resources/views/components/panel/*` (`<x-panel.* />`:
   `button`, `card`, `page-header`, `field`, `badge`, `flash`, `empty-state`, `nav-link`, `nonce`, `delta`, `stat`,
   `score`, `confidence`, `opportunity-status`, `visibility`, `candidate-status`, `serp-rank`, `rank-change`, `gap-type`, `content-gap`,
   `project-visibility`, `strategy-action`, `topic-status`, `target-state`, `serp-freshness`, `strategy-confidence`, `brand`,
   `ai-status`, `readiness`, `basis`, `evidence-list`, `page-cache`),
   nie `@apply` ani własne klasy. Własny CSS tylko, gdy utilities nie wystarczają.
4. Tokeny kolorów (`brand-*`) w bloku `@theme` w `resources/css/panel.css`; bez hexów w Blade;
   bez dark mode w MVP. `panel.css` skanuje tylko pliki panelu (`source(none)` + `@source`),
   a `app.css` motywu wyklucza je (`@source not`) — CSS strony i panelu się nie mieszają.
5. Kontrolery (`app/Http/Controllers/Panel`) cienkie, bez SQL — dane z usług pluginu.
6. Frazy i URL-e z GSC to dane zewnętrzne: zawsze `{{ }}`; `{!! !!}` tylko dla zaufanego HTML z kodu;
   linki tylko `http(s)` z `rel="noopener noreferrer"`.
7. Każda trasa projektu przechodzi przez middleware `ResolveProject` (opcjonalnie z capability,
   np. `ResolveProject::class . ':osf_seo_manage_projects'`); kontekst z
   `$request->attributes->get(ResolveProject::ATTRIBUTE)`. Surowe ID z URL nigdy nie trafia do zapytań.
8. Trasy poza `/login` są w grupie `[Authenticate, VerifyNonce]`. Każdy formularz POST zawiera
   `<x-panel.nonce />`. Middleware globalne (`PanelMiddleware::GLOBAL`): nagłówki bezpieczeństwa,
   `UnslashInput` (cofa `wp_magic_quotes`), `RequirePlugin` (503 bez pluginu).
9. Bez sesji Laravela: logowanie = ciasteczko WordPressa (`wp_signon`), CSRF = nonce WP + kontrola
   Origin/Referer, komunikaty flash = `App\Panel\Flash` (transient per użytkownik).
10. Adresy przez `App\Panel\PanelUrl` (względem `home_url()`), błędy przez `App\Panel\PanelResponse`.
11. Layout panelu (`panel/layouts/base`) nie woła `wp_head()`/`wp_footer()` — bez CDN, GTM, fontów
    zewnętrznych i skryptów motywu. Strony panelu: `noindex`, `Cache-Control: private, no-store`.
12. JS: Alpine (`resources/js/panel.js`); Chart.js dopiero z wykresami. Bez Reacta i jQuery.
13. Panel wymaga „ładnych” odnośników WordPressa (Ustawienia → Bezpośrednie odnośniki ≠ „Prosty”).

⸻

## 9. Komendy

Motyw (`themes/seo`) — menedżer pakietów **yarn** (nie npm ani pnpm):

```bash
cd themes/seo
composer install
yarn install
yarn dev      # Vite dev server (seo.local:6011)
yarn build    # produkcyjny build → public/build
```

Plugin (`plugins/osf-seo`):

```bash
cd plugins/osf-seo
composer install      # tylko narzędzia dev (PHPUnit)
composer test         # PHPUnit — testy jednostkowe bez WordPressa
composer lint         # php -l dla osf-seo.php, src/ i tests/
composer test:integration   # prawdziwy WordPress + OSOBNA testowa baza MySQL/MariaDB (zmienne OSF_SEO_TEST_DB_*)
wp osf-seo status     # stan pluginu (kod wyjścia 1, gdy kontrola nie przejdzie); --format=json
wp osf-seo db:migrate # oczekujące migracje (idempotentne)
wp osf-seo db:status  # wersja schematu i stan tabel; --format=json
wp osf-seo project:list [--status=…]            # projekty widoczne dla --user (bez --user: wszystkie)
wp osf-seo project:create --name=… --domain=…   # --porcelain zwraca public_id
wp osf-seo project:assign <public_id> <user> [--role=viewer|manager]
wp osf-seo project:unassign <public_id> <user>
wp osf-seo google:status        # konfiguracja OAuth (tylko nazwy i stan, bez wartości), redirect URI, połączenia
wp osf-seo google:generate-key  # nowy OSF_SEO_ENCRYPTION_KEY do wp-config.php (nigdzie nie zapisywany)
wp osf-seo gsc:properties --project=<public_id>                 # properties konta Google projektu
wp osf-seo gsc:select-property --project=<id> --property=<url>  # --reset-data przy danych innej property
wp osf-seo gsc:probe --project=<id>                             # mała próbka prawdziwych danych, bez zapisu
wp osf-seo gsc:sync|gsc:backfill --project=<id> [--run]         # zlecenie (i opcjonalnie wykonanie) synchronizacji
wp osf-seo gsc:status --project=<id>                            # stan synchronizacji i pokrycie danych
wp osf-seo sync:run             # kolejka synchronizacji (cron systemowy), potem szanse SEO, dane rynkowe i wyszukiwanie fraz w tle
wp osf-seo opportunities:analyze --project=<id> [--days=7|28|90] [--force]   # szanse SEO z zapisanych danych GSC
wp osf-seo opportunities:list --project=<id> [--days=28] [--type=…] [--status=open|all|…] [--format=json]
wp osf-seo dataforseo:status [--project=<id>] [--format=json]   # DataForSEO bez sekretów: rynek, metryki, zadania, koszty, limity
wp osf-seo dataforseo:sync --project=<id> --dry-run [--limit=10]   # plan bez żadnego żądania (zawsze najpierw)
wp osf-seo dataforseo:sync --project=<id> [--limit=<n>] [--force] [--wait=<s>]   # PŁATNE — tylko na polecenie użytkownika
wp osf-seo dataforseo:keyword --project=<id> --keyword="<fraza>"   # zapisane metryki (bez API)
wp osf-seo dataforseo:run [--collect-only]   # krok w tle raz (odbiór wyników zadań)
wp osf-seo dataforseo:locations [--country=PL]   # bezpłatna lista lokalizacji (weryfikacja kodów)
wp osf-seo discovery:suggest --project=<id>      # podpowiedzi seedów z GSC i szans (bez API)
wp osf-seo discovery:plan --project=<id> --seeds="a, b" [--method=related|suggestions] [--depth=1-3] [--limit=<n>] [--min-volume=<n>] [--max-kd=<n>]   # plan: zero żądań
wp osf-seo discovery:run --project=<id> --seeds="a, b" [opcje planu] [--force] [--yes] [--queue-only]   # PŁATNE — tylko na polecenie użytkownika
wp osf-seo discovery:status|list|refresh --project=<id> [--format=json]   # stan, kandydaci, przeliczenie widoczności (bez API)
wp osf-seo discovery:cancel --project=<id> --run=<id>
wp osf-seo serp:plan --project=<id> [--keywords=…] [--format=json]   # plan pomiaru pozycji: zero żądań, szacowany maks. koszt
wp osf-seo serp:run --project=<id> [--keywords=…] [--yes] [--queue-only] [--wait=<s>]   # PŁATNE — tylko na polecenie użytkownika
wp osf-seo serp:collect|status|list|snapshot …   # odbiór wyników (bezpłatny), stan, lista, pełne TOP N
wp osf-seo serp:track|untrack --project=<id> --keywords="a, b" [--from=manual|gsc|discovery]   # monitorowane frazy (bez API)
wp osf-seo serp:settings --project=<id> [--enable|--disable] [--frequency=…] [--device=…] [--depth=…]   # włączenie = płatny harmonogram
wp osf-seo competitors:list|add|update|organic --project=<id> …   # konkurenci (bez API)
wp osf-seo gap:plan --project=<id> [--preset=quick|standard|full] [--max-rank=…] [--min-volume=…] [--max-rows=…] [--competitors=…] [--no-baseline] [--force]   # plan luk: zero żądań
wp osf-seo gap:run --project=<id> [opcje planu] [--yes] [--queue-only]   # PŁATNE (Labs Ranked Keywords) — tylko na polecenie użytkownika
wp osf-seo gap:status|cancel|recalculate|list|keyword|content|pages|set-status|settings|brand --project=<id> …   # luki SEO (bez API)
wp osf-seo strategy:status|preview --project=<id> [--format=json]      # Strategia: stan, aktualność klucza danych, podgląd kandydatów (bez zapisu i bez API)
wp osf-seo strategy:refresh --project=<id> [--force]                   # materializacja kandydatów, faktów i dowodów (bez API)
wp osf-seo strategy:candidates|keyword --project=<id> …                # lista kandydatów, fakty i dowody frazy (bez API)
wp osf-seo strategy:add|remove --project=<id> --keywords="a, b"        # wpisy ręczne Strategii (osf_seo_manage_strategy)
wp osf-seo strategy:serp-plan --project=<id> [--keywords=…] [--format=json]   # podgląd analizy SERP: zero żądań, ponowne użycie pomiarów, maks. koszt
wp osf-seo strategy:serp-run --project=<id> [--keywords=…] [--yes] [--queue-only] [--wait=<s>]   # PŁATNE (przez SerpSubmitter) — tylko na polecenie użytkownika
wp osf-seo strategy:serp-status|serp|serp-overlap --project=<id> …     # stan analiz, SERP Intelligence kandydata, overlap dwóch kandydatów (bez API)
wp osf-seo strategy:list|topic|context --project=<id> [--topic=…] [--format=json]   # tematy (backlog), szczegóły tematu, pakiet kontekstu (bez API)
wp osf-seo strategy:set-status --project=<id> --topic=… --status=… [--note=…]           # status pracy (przeliczenie go nie zmienia)
wp osf-seo strategy:set-target --project=<id> --topic=… (--target-url=… | --confirm-missing | --clear)   # ręczna strona docelowa (nie `--url` — globalny parametr WP-CLI)
wp osf-seo strategy:pin|unpin --project=<id> --keywords="a, b" [--topic=… | --new]   # przypięcia fraz do tematów (osf_seo_manage_strategy)
wp osf-seo strategy:queue [--run] [--time-limit=<s>] [--format=json]   # kolejka przeliczeń Strategii w tle: diagnostyka; --run = krok w tle raz (bez API)
wp osf-seo ai:status [--format=json]                                  # konfiguracja AI bez sekretów: wyłącznik, dostawca, model, obecność klucza, ceny, limity, wersje
wp osf-seo ai:context|validate-context --project=<id> --topic=…        # kontekst AI tematu (JSON) / walidacja: determinizm, rozmiar, braki danych (bez API)
wp osf-seo ai:plan --project=<id> --topic=… [--provider=fake|openai] [--focus="…"]   # plan: tokeny, koszt maks., budżet, blokady — zero żądań
wp osf-seo ai:run --project=<id> --topic=… [--provider=fake]           # domyślnie dostawca testowy (koszt 0); --provider=openai PŁATNE — tylko na polecenie użytkownika
wp osf-seo ai:runs|show|decide|delete --project=<id> [--run=<id>] …    # historia, wynik i walidacja (--payload), decyzja użytkownika, usunięcie; ai:runs --check-stale — aktualność wyników
wp osf-seo ai:analysis-types [--format=json]                          # typy analiz rekomendacji, wersje instrukcji, zgodność z działaniami Strategii
wp osf-seo ai:readiness --project=<id> --topic=… --type=page-optimization|new-page-brief|content-gap [--explicit]   # gotowość z zapisanych danych — zero żądań
wp osf-seo ai:plan --project=<id> --topic=… --type=… [--provider=…] [--explicit]   # plan analizy rekomendacji z odciskiem planu (zero żądań); ai:context --type=… — kontekst v3
wp osf-seo ai:generate --project=<id> --topic=… --type=… [--provider=fake] [--explicit] [--plan=<odcisk>] [--yes] [--repeat]   # domyślnie dostawca testowy; płatny — tylko na polecenie użytkownika, z zatwierdzonym planem
wp osf-seo ai:budget [--project=<id>]                                  # budżet AI (oddzielny od DataForSEO)
wp osf-seo ai:purge                                                    # porządki bez wywołań AI: porzucone uruchomienia, retencja historii
wp osf-seo ai:queue [--run] [--time-limit=<s>] [--format=json]        # kolejka analiz zleconych w panelu (stan); --run = krok w tle raz (dostawca z zatwierdzonego planu)
wp osf-seo pages:status|list --project=<id> [--format=json]           # Page Intelligence: konfiguracja, transport, strony i stan pamięci (bez HTTP)
wp osf-seo pages:plan --project=<id> (--page-url=… | --urls="a b" | --topic=… | --keyword=… --ranks=1,2) [--force]   # plan: zakres, pamięć, limity hosta — zero HTTP i DNS
wp osf-seo pages:fetch --project=<id> (wybór jak wyżej) [--force] [--yes]   # JAWNE pobranie (zewnętrzne żądania HTTP) — tylko na polecenie użytkownika
wp osf-seo pages:check-url --project=<id> --page-url=…                # diagnostyka SSRF: zakres, składnia, DNS i IP (bez HTTP); nie `--url` — globalny parametr WP-CLI
wp osf-seo pages:show|snapshot|delete --project=<id> --page=…|--snapshot=…   # snapshot (JSON), usunięcie strony; pages:purge — retencja (bez HTTP)
wp osf-seo pages:jobs [--run] [--time-limit=<s>] [--format=json]      # zlecenia pobrania z panelu (stan); --run = krok w tle raz (żądania HTTP tylko dla jawnie zleconych stron)
composer test:performance:pages # benchmark ekstrakcji HTML (mały / średni / duży / JS / wiele linków) i kontekstu AI ze stronami (bez sieci i bazy)
composer test:performance       # benchmark raportów + EXPLAIN na syntetycznych danych (OSOBNA baza testowa)
composer test:performance:serp  # benchmark pozycji SERP (100 projektów × 500 fraz, TOP100, historia, 2500 fraz) + EXPLAIN (OSOBNA baza)
composer test:performance:gap   # benchmark Luk SEO (40 zbiorów × 10 000 fraz, 20 projektów, import 10 000 fraz atrapą HTTP) + EXPLAIN (OSOBNA baza)
composer test:performance:strategy  # benchmark Strategii (100 / 1 000 / 5 000 fraz + 10 projektów, przebiegi bez zmian, krok w tle) (OSOBNA baza)
composer test:performance:ai    # benchmark analiz rekomendacji: kontekst, gotowość, walidacja (A–C bez bazy) + historia 10 000 analiz (D, OSOBNA baza); --no-db
```

Testy integracyjne czyszczą i usuwają tabele — **nigdy nie wskazuj bazy strony**. Zmienne:
`OSF_SEO_TEST_DB_NAME` (domyślnie `osf_seo_test`), `OSF_SEO_TEST_DB_USER`, `OSF_SEO_TEST_DB_PASSWORD`,
`OSF_SEO_TEST_DB_HOST` (np. `localhost:/ścieżka/mysqld.sock` dla socketu Local).

WP-CLI w LocalWP (uzupełnij dane swojej strony; strona w Local musi być uruchomiona):

```bash
php -d "mysqli.default_socket=$HOME/Library/Application Support/Local/run/<LOCAL_SITE_ID>/mysql/mysqld.sock" \
  "$(command -v wp)" --path="<ścieżka strony>/app/public" <komenda>
```

`wp db query` w Local nie działa (brak binarki `mysql`) — używaj `wp eval` / `wp eval-file`.

⸻

## 10. Build i assety

- `themes/seo/public/build/**` jest śledzony w gicie (obecny deploy nie buduje assetów).
  Po każdej zmianie w `themes/seo/resources/css` lub `resources/js` uruchom `yarn build`
  i commituj `public/build` razem ze zmianą.
- Build jest powtarzalny: te same źródła dają te same hashe plików.
- `theme.json` w motywie jest źródłem preprocesowanym; realny plik powstaje w `public/build/assets/theme.json`.

⸻

## 11. Workflow i raportowanie

- IMPLEMENT → TEST → FIX → TEST AGAIN. Nie uznawaj niczego za działające wyłącznie na podstawie
  analizy kodu. Czego nie da się uruchomić, oznacz jako NOT TESTED.
- Agent może uruchamiać buildy i testy w swoim środowisku (także harness WordPress/MariaDB).
  Nie uruchamia niczego na stagingu ani produkcji.
- Raport po etapie: IMPLEMENTED / TESTED (faktycznie uruchomione komendy i wynik) / NOT TESTED /
  ISSUES / GIT STATUS / NEXT STEP.
- Pracuj etapami zaakceptowanymi przez użytkownika; nie zaczynaj kolejnego STEP bez zgody.
- Destrukcyjne operacje (usuwanie plików lub danych, przepisywanie historii) tylko po przedstawieniu planu.

⸻

## 12. Git

- Pracuj na przydzielonym branchu roboczym. Nie merguj do `main` bez zgody.
- Bez force push, bez przepisywania historii, bez usuwania branchy.
- Commity małe, opisowe, po polsku (jak dotychczasowa historia).
- Nie commituj niepowiązanych plików ani lokalnych zmian użytkownika.

⸻

## 13. Deployment

Staging: `https://seo.ohsofresh.top` (Hostinger). Deployment nowej struktury repo jest
**jeszcze nieustalony** (`docs/ARCHITECTURE.md`, sekcja 18). Nie zmieniaj jego konfiguracji,
nie łącz się z serwerem i nie używaj żadnych credentials znalezionych w repo.
Nigdy nie wdrażaj całego repozytorium do `wp-content` (wcześniejszy incydent nadpisał pliki WordPressa) — wdrożenie
wyłącznie zawężone: `plugins/osf-seo/` → `wp-content/plugins/osf-seo/`, `themes/seo/` → `wp-content/themes/seo/`.
Agent nie wykonuje płatnego smoke testu DataForSEO (także pomiaru pozycji SERP i importu Luk SEO), płatnego wywołania AI (OpenAI), pobierania prawdziwych stron z internetu (`pages:fetch`) ani wdrożenia bez wyraźnego polecenia użytkownika.
