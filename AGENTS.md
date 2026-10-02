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

Od STEP 15 w nowych tekstach UI i dokumentacji produkt nazywa się **Whack-a-mole** (istniejące teksty „Wibble” / „OSF SEO” zmieniamy
tylko przy okazji pracy nad danym widokiem, bez osobnego rebrandu). Identyfikatory techniczne pozostają **celowo bez zmian**: plugin `osf-seo`,
stałe `OSF_SEO_*`, tabele `osf_*`, namespace `OsfSeo\`, opcje/capabilities `osf_seo_*`, komendy `wp osf-seo …`.
Nie zmieniaj ich mimochodem — techniczny rebrand będzie osobnym, zaplanowanym etapem.

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
  Google Client ID/Secret, klucz szyfrujący, tokeny OAuth (access/refresh), login i hasło API DataForSEO,
  hasła, dane dostępowe Hostingera, klucze SSH, dane dostępowe do bazy.
- Sekrety konfigurujemy wyłącznie przez stałe w `wp-config.php` lub zmienne środowiskowe
  (np. `OSF_SEO_GOOGLE_CLIENT_ID`, `OSF_SEO_GOOGLE_CLIENT_SECRET`, `OSF_SEO_ENCRYPTION_KEY`,
  `OSF_SEO_DATAFORSEO_LOGIN`, `OSF_SEO_DATAFORSEO_PASSWORD`).
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
  `Opportunities\OpportunityKeywordIndex` (dane query × page) — nigdy przez samo `opportunities.keyword`. Płatna analiza SERP (od fazy B) wyłącznie
  przez `SerpSubmitter` STEP 14, po podglądzie i potwierdzeniu, we wspólnych limitach; bez automatycznego harmonogramu, PAA/related searches,
  nowych płatnych endpointów, crawlera i AI w STEP 16 bez nowej decyzji.
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
   `project-visibility`),
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
composer test:performance       # benchmark raportów + EXPLAIN na syntetycznych danych (OSOBNA baza testowa)
composer test:performance:serp  # benchmark pozycji SERP (100 projektów × 500 fraz, TOP100, historia, 2500 fraz) + EXPLAIN (OSOBNA baza)
composer test:performance:gap   # benchmark Luk SEO (40 zbiorów × 10 000 fraz, 20 projektów, import 10 000 fraz atrapą HTTP) + EXPLAIN (OSOBNA baza)
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
Agent nie wykonuje płatnego smoke testu DataForSEO (także pomiaru pozycji SERP i importu Luk SEO) ani wdrożenia bez wyraźnego polecenia użytkownika.
