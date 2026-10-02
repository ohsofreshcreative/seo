# Wibble — architektura

**Wibble** to nazwa produktu (dawniej OSF SEO). Identyfikatory techniczne — plugin `osf-seo`, stałe `OSF_SEO_*`,
tabele `{prefix}osf_*`, namespace `OsfSeo\`, komendy `wp osf-seo …`, opcje i capabilities `osf_seo_*` — pozostają
**celowo bez zmian**; bezpieczny techniczny rebrand będzie osobnym etapem.

Dokument opisuje zaakceptowaną architekturę, decyzje i roadmapę. Aktualizuj go przy każdej zmianie
decyzji. Repozytorium jest publiczne — dokument zawiera wyłącznie nazwy stałych i placeholdery,
nigdy wartości sekretów.

## Spis treści

1. [Cel i zakres](#1-cel-i-zakres)
2. [Decyzje](#2-decyzje)
3. [Repozytorium i środowiska](#3-repozytorium-i-środowiska)
4. [Komponenty i podział odpowiedzialności](#4-komponenty-i-podział-odpowiedzialności)
5. [Uprawnienia](#5-uprawnienia)
6. [Model danych](#6-model-danych)
7. [Google OAuth](#7-google-oauth)
8. [Przepływ danych GSC i reguły obliczeń](#8-przepływ-danych-gsc-i-reguły-obliczeń)
9. [Synchronizacja](#9-synchronizacja)
10. [Szanse SEO (STEP 11)](#10-szanse-seo-step-11)
11. [Dane rynkowe (DataForSEO, STEP 12)](#11-dane-rynkowe-dataforseo-step-12)
12. [Nowe frazy (STEP 13)](#12-nowe-frazy-step-13)
13. [Pozycje SERP i konkurenci (STEP 14)](#13-pozycje-serp-i-konkurenci-step-14)
14. [Luki SEO (STEP 15)](#14-luki-seo-step-15)
15. [Strategia i SERP Intelligence (STEP 16)](#15-strategia-i-serp-intelligence-step-16)
16. [Bezpieczeństwo](#16-bezpieczeństwo)
17. [Konfiguracja i sekrety](#17-konfiguracja-i-sekrety)
18. [Deployment (do ustalenia)](#18-deployment-do-ustalenia)
19. [Roadmapa i stan prac](#19-roadmapa-i-stan-prac)
20. [Porządki w motywie (C1–C5)](#20-porządki-w-motywie-c1c5)
21. [Ryzyka i otwarte kwestie](#21-ryzyka-i-otwarte-kwestie)

---

## 1. Cel i zakres

Panel SEO dla stron agencji i jej klientów. Po dodaniu projektu i połączeniu go z Google Search
Console system sam pobiera frazy, na które strona pojawia się w Google, zapisuje historię we własnej
bazie i pokazuje: ranking fraz (średnia pozycja GSC), porównania okresów, wzrosty i spadki,
szanse SEO, landing pages i wykresy. Od STEP 13 wyszukuje też **nowe frazy**, na które strona jeszcze nie ma widoczności
(seedy → DataForSEO Labs → deduplikacja → widoczność w GSC → priorytet → decyzja; sekcja 12), a od STEP 14 mierzy **Pozycję SERP**
monitorowanych fraz (pełne TOP100 Google z DataForSEO, historia, konkurenci; sekcja 13) — osobno od średniej pozycji GSC, a od STEP 15
pokazuje **Luki SEO**: frazy, na które rankują konkurenci, a projekt nie albo słabiej, oraz potencjalne luki treści (sekcja 14). STEP 16 dodaje
**Strategię** — warstwę łączącą sygnały modułów w backlog SEO z dowodami (sekcja 15; w toku, faza A).
W nowych tekstach produkt nazywa się **Whack-a-mole** (identyfikatory techniczne `osf-seo` / `osf_seo_*` bez zmian).

Źródła danych: Google Search Console (źródło prawdy o skuteczności strony: kliknięcia, wyświetlenia, CTR, średnia pozycja
GSC, strony docelowe, historia) oraz — od STEP 12 — **DataForSEO** jako zatwierdzony płatny dostawca danych rynkowych
(wolumen, historia wolumenu, CPC, konkurencja Ads, trudność SEO; D3). Dane rynkowe uzupełniają GSC, nigdy go nie zastępują.

Poza zakresem: inne płatne API (Semrush, Ahrefs, Senuto, SeoStation…), scrapowanie wyników Google, AI. Każde nowe płatne
API wymaga osobnej decyzji architektonicznej; integracje dostawców wyłącznie za interfejsem domenowym (np. `KeywordMetricsProvider`).

## 2. Decyzje

| # | Decyzja | Uzasadnienie / uwagi |
|---|---|---|
| D1 | Jedno repozytorium: motyw + plugin. Root repo = `wp-content/` (`themes/seo`, `plugins/osf-seo`) | Jeden produkt, wspólne wdrażanie; LocalWP bez symlinków; ścieżki 1:1 z serwerem |
| D2 | Plugin `osf-seo` w czystym PHP (bez Laravela/Acorn, bez Guzzle); motyw = UI (routing Acorn, Blade) | Logika niezależna od motywu, brak konfliktów zależności z `vendor/` motywu |
| D3 | Źródła danych: Google Search Console API (źródło prawdy o skuteczności strony) oraz **DataForSEO — jedyny zatwierdzony płatny dostawca danych SEO** (od STEP 12). Płatne API wymagają jawnej decyzji architektonicznej w tej tabeli; zgoda na DataForSEO nie obejmuje innych płatnych API | Wibble potrzebuje danych rynkowych o frazach i SERP (wolumen, trudność, CPC, konkurencja, od STEP 14 obserwowany ranking SERP), których Google Search Console nie udostępnia. Koszt kontrolowany lokalnymi limitami (sekcja 11.6). Pierwotnie (MVP 1): tylko GSC, koszt zewnętrznych usług 0 zł |
| D4 | OAuth scope: `https://www.googleapis.com/auth/webmasters.readonly` (+ `openid email` do identyfikacji konta) | Tylko odczyt, bez modyfikacji Search Console |
| D5 | Hosting: Hostinger. Staging: `https://seo.ohsofresh.top`. Bez Redis/persistent object cache w MVP | Cache przez transients/bazę; nic nie zależy od Redis |
| D6 | Lokalnie system działa bez systemowego crona (WP-Cron); na produkcji podpinamy cron systemowy | Sekcja 9.1 |
| D7 | Aplikacja OAuth w trybie External/Testing akceptowana do czasu publikacji | Refresh tokeny w trybie Testing wygasają po 7 dniach — publikacja przed produkcją |
| D8 | Motyw czyszczony etapami do czystego Sage 11 tylko dla OSF SEO (C1–C5), build/test po każdym etapie | Sekcja 20 |
| D9 | Panel: standardowe utilities Tailwind, proste konwencje (AGENTS.md, sekcja 8) | Dawne ograniczenia projektu marketingowego nie obowiązują |
| D10 | Wykresy: Chart.js. React niepotrzebny (do usunięcia w cleanupie) | — |
| D11 | MVP 1 bez rozbicia fraz na device/country | Nie mnożymy danych bez potrzeby; priorytet: query, page, date, clicks, impressions, CTR, średnia pozycja |
| D12 | TOP 3/10/20/50/100: karty liczone dla wybranego okresu, wykres na oknie kroczącym 7 dni; UI jasno informuje, że to średnia pozycja GSC | Dzienne pozycje fraz z 1 wyświetleniem są szumem |
| D13 | Backfill ok. 16 miesięcy, od najnowszych danych; UI nie jest blokowane; postęp widoczny | Połącz GSC → najnowsze dane → dashboard działa → starsze dane w tle |
| D14 | `query_page_daily` eksperymentalnie; pomiar na kilku projektach przed decyzją o retencji/rollupie | Sekcja 6.4 |
| D15 | Opportunity Score dopiero w MVP 2 | Sekcja 10 (tylko specyfikacja) |
| D16 | `public_id` (ULID) w URL + `ProjectGuard` | ULID tylko utrudnia enumerację; zabezpieczeniem jest autoryzacja |
| D17 | Repozytorium publiczne: sekrety wyłącznie w `wp-config.php` / zmiennych środowiskowych | Sekcja 17 |
| D18 | Zmiana property GSC przy istniejących danych = jawny reset (usunięcie danych projektu i ponowny import); bez izolacji danych per property | Prostszy model (klucze faktów bez property), zero ryzyka mieszania danych. Sekcja 7.1 |
| D19 | Kolejka synchronizacji: własna, na `osf_sync_runs` + WP-Cron / cron systemowy (zamiast Action Scheduler) | Bez zewnętrznej biblioteki w publicznym repo i dodatkowych tabel; jeden runner (GET_LOCK), budżet czasu. Sekcja 9.1 |
| D20 | Szanse SEO w modelu hybrydowym: dowody wyliczane z danych GSC (wykrycia okresu zastępowane przy analizie), stan pracy trwały; szansa = projekt × stabilny odcisk (property, typ, podstrona/fraza/para adresów) | Lista zadań przetrwa przeliczenia i ponowny import; bez kopiowania faktów GSC. Sekcja 10 |
| D21 | Analiza szans jako osobny krok po kolejce synchronizacji (WP-Cron i `sync:run`), nie część importera; klucz danych pomija analizę bez zmian | Import nie zależy od analizy; idempotentnie, bez generalizowania `SyncRunner`. Sekcja 10.8 |
| D22 | Reset property archiwizuje szanse (stan `archived`, historia i stan pracy zostają) i usuwa ich dane pochodne; szanse nie należą do `GscDataStore::DATA_TABLES` | Stare rekomendacje nie udają aktualnych, a historia pracy nie znika bez decyzji. Sekcja 10.8 |
| D23 | Dane rynkowe za interfejsem domenowym `KeywordMetricsProvider`; DataForSEO (`DataForSeoProvider`) jest jego implementacją | Synchronizacja, raport fraz i szanse nie zależą od konkretnego dostawcy; bez ogólnego „frameworka API”. Sekcja 11.1 |
| D24 | Klucz danych rynkowych = dostawca × lokalizacja × język × MD5 znormalizowanej frazy (`market_keywords`), niezależny od słownika GSC; `keywords.market_key` wyliczany w tle | Identyfikatory fraz GSC są jednorazowe (reset property usuwa słownik) — dane rynkowe przetrwają reset i są wspólne dla projektów na tym samym rynku (jeden płatny odczyt). Sekcja 11.4 |
| D25 | Wolumen: Google Ads Search Volume w kolejce **Standard** (asynchronicznie); trudność SEO: DataForSEO Labs Bulk Keyword Difficulty (**Live**); TTL obu metryk 30 dni | Standard tańszy o ⅓ niż Live, a wolumen nie jest potrzebny w czasie rzeczywistym; Labs nie ma trybu Standard. Sekcja 11.2 |
| D26 | Osobny mały runner danych rynkowych (`market_tasks`, krok po kolejce GSC) zamiast `sync_runs` | `SyncRunner` jest specyficzny dla GSC (`Dataset::from` na każdym wierszu) — zadanie innego dostawcy w `sync_runs` zatrzymałoby kolejkę GSC; awaria DataForSEO nie może wpływać na import GSC. Sekcja 11.8 |
| D27 | Twarde bezpieczniki kosztów: limit płatnych zadań na przebieg, lokalne limity dzienny i miesięczny, plan bez API (dry-run), automatyka tylko po pierwszej jawnej synchronizacji projektu, wstrzymanie po błędzie konta | Błąd w kodzie nie może wygenerować tysięcy płatnych zadań; wdrożenie niczego nie uruchamia. Sekcja 11.6 |
| D28 | Szanse SEO: dane rynkowe wyłącznie jako kontekst (wyświetlanie); priorytet i pewność bez zmian | Wagę wolumenu i trudności ustalimy po zebraniu prawdziwych danych; szanse działają bez DataForSEO. Sekcja 11.9 |
| D29 | Wyszukiwanie nowych fraz za interfejsem `KeywordDiscoveryProvider`; minimalny zestaw endpointów: DataForSEO Labs **Related Keywords** i **Keyword Suggestions** (Live) | Jeden seed na żądanie (relacja „z którego seeda”), w odpowiedzi wolumen, trudność SEO, CPC, konkurencja Ads i intencja (bez płatnego wzbogacania), cena za element ograniczana limitem; Keyword Ideas nie przypisuje wyników do seedów, Google Ads Keywords For Keywords nie ma trudności SEO, frazy domen i SERP poza zakresem. Sekcja 12.2 |
| D30 | Wyszukiwanie korzysta z bezpieczników danych rynkowych: te same limity dzienny i miesięczny (rejestr `market_tasks`, `trigger_type = discovery`), ta sama blokada płatnych żądań i wstrzymanie po błędzie konta; bez drugiego budżetu. Panel tylko kolejkuje przebieg po potwierdzeniu planu, wykonuje go tło (albo CLI) | Jeden budżet = jeden bezpiecznik dla całego DataForSEO; żądanie przeglądarki nigdy nie wysyła płatnego żądania ani nie czeka na dostawcę. Sekcje 12.4, 12.10 |
| D31 | Kandydat = projekt × fraza rynkowa (`market_keywords`); metryki nie są kopiowane; źródła fraza × seed × metoda | Jedna fraza z wielu seedów = jeden kandydat z zachowanymi relacjami; metryki wspólne ze STEP 12 i innymi projektami rynku. Sekcja 12.5 |
| D32 | Metryki z odpowiedzi discovery zapisywane do wspólnych `market_keywords` tylko przy braku danych lub po TTL; intencja z odpowiedzi; bez osobnego wzbogacania kandydatów | Zero dodatkowych płatnych żądań na kandydata; świeży wolumen Google Ads ze STEP 12 nie jest nadpisywany. Sekcja 12.9 |
| D33 | Priorytet odkrycia 0–100 jako przejrzysta suma ograniczonych składników (popyt i CPC w skali log z limitem, CPC maks. 5 pkt); nazwa „Priorytet”, nigdy „wartość biznesowa” | Wynik wyjaśnialny w UI, żadna metryka nie dominuje; to sygnał do sprawdzenia, nie prognoza. Sekcja 12.7 |
| D34 | Cache seeda: projekt × dostawca × rynek × metoda z co najmniej tak szerokimi parametrami, TTL 30 dni; pobranie mimo cache tylko z `osf_seo_manage_keyword_discovery` | Brak podwójnej opłaty za ten sam seed (także przy podwójnym kliknięciu — jeden aktywny przebieg i odstęp 60 s). Sekcja 12.9 |
| D35 | Widoczność GSC kandydata: deterministyczne klasy (nieznana, brak, słaba, już widoczna) po kluczu rynkowym, średnia pozycja ważona wyświetleniami; reset property zachowuje kandydatów i decyzje (widoczność → nieznana) | Pozycja 45 z popytem pozostaje szansą, pozycja 2 ze stałą widocznością nie; decyzje nie znikają przy zmianie property. Sekcja 12.6 |
| D36 | Pomiary pozycji za interfejsem `SerpProvider`; DataForSEO **Google Organic SERP w kolejce Standard** (zadania po 100 w zleceniu, odbiór `task_get/advanced`, lista gotowych zadań), bez Live, priorytetu i płatnych opcji; domyślnie TOP100; cena konfigurowalna, w UI „szacowany maksymalny koszt”, koszt zgłoszony przez dostawcę rozstrzygający | Standard jest najtańszy, a pozycje nie są potrzebne w czasie rzeczywistym; TOP100 daje konkurentów i wejścia/wyjścia z TOP; cena z dokumentacji zweryfikowana pośrednio. Sekcja 13.2 |
| D37 | Zapis **pełnego TOP N każdego pomiaru, wszystkich domen**, znormalizowany (`serp_snapshots`, `serp_results` jako liczby + słowniki domen, adresów i opisów), bez surowego JSON-a i bez automatycznego usuwania historii | Konkurent dodany później dostaje historię bez nowych kosztów; konkurenci organiczni z faktów; ~26 B na wynik; przyszła archiwizacja/partycjonowanie zakresami `snapshot_id`. Sekcja 13.3 |
| D38 | **Pozycja SERP** = `rank_group` najlepszego wyniku organicznego rodziny domeny projektu (domena + subdomeny, granica etykiet); `rank_absolute` zapisany osobno; wyróżniony fragment osobno (nigdy #1); średnia pozycja GSC osobna; pomiary per projekt (bez współdzielenia między projektami) | Jedna jasna definicja pozycji, porównywalna w czasie; GSC bez zmian; prostsza izolacja i koszty projektu. Sekcje 13.1, 13.4 |
| D39 | Monitorowane są tylko jawnie dodane frazy (ręcznie, z Fraz GSC, z Nowych fraz), bez wzbogacania danymi rynkowymi; limit **miękki** `OSF_SEO_SERP_MAX_KEYWORDS` (domyślnie 500) z komunikatem zamiast obcinania — architektura na tysiące fraz | Koszt rośnie liniowo z liczbą fraz — decyduje człowiek; limit to rekomendacja, nie ograniczenie modelu. Sekcja 13.6 |
| D40 | Płatne pomiary pod bezpiecznikami DataForSEO: rezerwacja kosztu szacowanego w `market_tasks` już przy zakolejkowaniu (wspólne limity), cały pomiar albo wcale, wysyłka tylko w tle/CLI pod wspólną blokadą, pomiar `uncertain` przed wysłaniem — zlecenie o nieznanym wyniku nie jest ponawiane, odzyskanie po `tag` | Jeden budżet dla wszystkich modułów; żądanie przeglądarki nigdy nie wysyła płatnego żądania; brak podwójnej opłaty po timeoucie. Sekcja 13.7 |
| D41 | Harmonogram domyślnie wyłączony (tydzień, gdy włączony po potwierdzeniu kosztu); `UNIQUE (project_id, slot_key)` na termin, okno ponownego sprawdzenia 6 h, odstęp pomiaru ręcznego 15 min; przekroczenie limitu = przebieg pominięty z powodem i ponowienie po odnowieniu limitu | Brak nakładania się crona i pomiarów ręcznych; przewidywalny koszt; widoczny powód braku pomiaru. Sekcja 13.8 |
| D42 | Konkurenci (monitorowani i organiczni) wyłącznie z zapisanych SERP-ów — bez dodatkowych żądań, bez ocen „siły” | Zero kosztu za konkurenta; fakty zamiast punktacji. Sekcja 13.9 |
| D43 | Luki SEO za interfejsem `CompetitorKeywordsProvider`; DataForSEO **Labs Ranked Keywords (Live)**, tylko wyniki organiczne, filtry pozycji i wolumenu po stronie dostawcy, cena konfigurowalna (0,012 USD za żądanie + 0,00012 USD za frazę) | Jedno żądanie zwraca do 1 000 fraz domeny z metrykami (bez dodatkowych zapytań o wolumen/KD); Live — dane potrzebne w jednym przebiegu. Sekcja 14.2 |
| D44 | Stronicowanie wyłącznie potwierdzonym mechanizmem `limit` + `offset` (maks. 10 000 fraz na domenę), bez niepotwierdzonych obejść; przycięcie do najmocniejszych fraz, wiarygodność nieobecności (`covered_min_volume`, duplikaty między stronami) | Ryzyko pominięcia lub zdublowania fraz niedopuszczalne (decyzja STEP 15); ograniczenie opisane w UI i dokumentacji. Sekcja 14.2 |
| D45 | Zbiory fraz domen **wspólne dla projektów** na rynku (domena × lokalizacja × język), świeże przez 30 dni, ponowne użycie bez opłaty, gdy obejmują zakres; domyślnie TOP30, wolumen ≥ 10, 10 000 fraz; punkt odniesienia projektu z Ranked Keywords (TOP100) | Ten sam konkurent wielu klientów kosztuje raz; punkt odniesienia odróżnia brak widoczności od nieznanej. Sekcja 14.4 |
| D46 | Widoczność projektu: świeży pomiar SERP (STEP 14) → GSC → punkt odniesienia Labs; sam brak frazy w GSC nigdy nie oznacza braku widoczności | GSC pokazuje tylko frazy z wyświetleniami; brak = brak dowodu. Sekcja 14.6 |
| D47 | Płatne żądania wyłącznie z `GapImporter` (tło / CLI) pod wspólną blokadą i wspólnymi limitami; limit sprawdzany przed każdą stroną, pauza i wznowienie, bez ponawiania niepewnych żądań, jeden aktywny przebieg na projekt; **globalne limity bez zmian** do smoke testu | Jeden budżet dla wszystkich modułów; brak podwójnej opłaty; przewidywalny koszt. Sekcja 14.5 |
| D48 | Priorytet luki 0–100 (popyt, osiągalność, siła sygnału, luka, intencja, CPC — skale logarytmiczne z limitem) i mnożnik dla fraz bez luki; sygnał do sprawdzenia, nie prognoza | Przejrzysty i stabilny ranking bez dominacji ogromnego wolumenu. Sekcja 14.9 |
| D49 | Keyword Gap i Content Gap osobno; luka treści jako heurystyka z powodem i pewnością („Potencjalna luka treści”, „Istniejąca strona — do wzmocnienia”, „Bez luki treści”, „Niejasne”), grupy deterministyczne wokół lidera ze stabilnymi identyfikatorami | Bez AI i bez twierdzeń o „potrzebnej nowej stronie”; status pracy przetrwa przeliczenie. Sekcje 14.10–14.11 |
| D50 | Historia zbiorów: new / lost / back / url / istotne zmiany pozycji między importami Labs — bez rozbudowy w drugi rank tracker | Monitoring pozycji pozostaje w STEP 14. Sekcja 14.12 |
| D51 | Strategia (STEP 16) to warstwa decyzyjna nad modułami — nie zastępuje Szans SEO, Nowych fraz, Luk SEO ani Pozycji; czyta ich wyniki i decyzje. Rekord backlogu = temat (frazy + strona docelowa + działanie); tożsamość frazy = fraza rynkowa projektu (`market_keywords`); `core_key` i zbiór wyrazów — tylko sygnały pomocnicze | Jedno miejsce decyzji bez kopiowania danych modułów; ta sama tożsamość co w STEP 12–15. Sekcja 15 |
| D52 | Kandydaci z adapterów źródeł (ręczne, Pozycje, Szanse SEO, Nowe frazy, Luki fraz, Luki treści, GSC) z kolejnością poziomów, filtrami marki i wykluczeń i limitem `OSF_SEO_STRATEGY_MAX_KEYWORDS` (5000); decyzje modułów (odrzucenia) respektowane | Przewidywalna skala i brak „wskrzeszania” odrzuconych fraz. Sekcja 15.2 |
| D53 | Fakty i dowody per fraza materializowane w `strategy_keywords` (przyrostowo, `facts_hash`); metryki rynkowe, historia GSC i SERP tylko odwoływane; klucz danych obejmuje mutacje ręczne wszystkich modułów i rewizję Strategii, nie tylko czas importu | Lista i klasyfikacja bez agregacji przy renderowaniu; brak nieaktualnych wyników po decyzjach użytkownika. Sekcje 15.3, 15.6 |
| D54 | Powiązanie szansa SEO ↔ fraza z danych query × page (członek grupy szansy, ta sama podstrona, fraza) — nie z samego `opportunities.keyword`; używane przez Strategię i szczegóły luki | Szanse są per podstrona — kolumna frazy jest zwykle pusta. Sekcja 15.5 |
| D55 | SERP Intelligence na danych STEP 14 (bez nowych tabel wyników; profil pomiaru jako tabela pochodna); jednorazowa analiza = monitorowana fraza `analysis` (poza harmonogramem i listą Pozycje, dowód widoczności także dla Luk SEO), w kontekście pomiaru projektu; świeżość 30 / 90 dni | Bez drugiego systemu SERP; analiza nie zamienia się w koszt cykliczny. Sekcja 15.10 (faza B) |
| D56 | Płatna analiza SERP tylko ręcznie: lider tematu plus jawnie wybrane frazy do potwierdzenia overlapu, podgląd kosztu i potwierdzenie, maks. `OSF_SEO_STRATEGY_SERP_MAX_PER_RUN` (100) fraz, wspólne limity DataForSEO; bez automatycznego harmonogramu w MVP | Koszt pod kontrolą człowieka; jeden budżet. Sekcja 15.10 |
| D57 | „Create” = „Kandydat na nową stronę”: brak widoczności (GSC, Labs, SERP) nie dowodzi braku strony; wysoka pewność luki strukturalnej tylko z indeksem stron projektu (`ProjectPageIndex`, przyszły crawler) albo ręcznym potwierdzeniem; dane niejednoznaczne → „investigate” | Bez fałszywych rekomendacji tworzenia stron, które już istnieją. Sekcja 15.1 |
| D58 | Działania deterministycznymi regułami w kolejności consolidate → recover → optimize → create → monitor → investigate, z kodem powodu | Wyjaśnialność bez AI. Sekcja 15.10 (faza C) |
| D59 | Priorytet strategii 0–100 = ograniczone składniki w skali logarytmicznej z limitem × mnożnik pewności; pewność punktowa (niezależne źródła, strona docelowa, świeżość, konflikty) | Sortowanie pracy bez dominacji wolumenu i bez fałszywej precyzji. Sekcja 15.10 (faza C) |
| D60 | Tematy: automatyczne scalanie wyłącznie przy zgodnej stronie docelowej, silnym overlapie SERP albo decyzji ręcznej; `tokenKey`, `core_key`, grupy luk i wspólne domeny — tylko „możliwa grupa”; porównanie z liderem (bez łańcuchów), konflikt stron projektu blokuje scalenie | Brak agresywnego łączenia fraz z własnym popytem. Sekcja 15.10 (faza C) |
| D61 | Workflow tematów jak w Szansach SEO; przeliczenie nie zmienia statusu (odrzucone i zrealizowane dostają tylko flagi), zdarzenia tylko istotnych zmian | Decyzje użytkownika są trwałe; historia bez duplikowania rekordów. Sekcja 15.10 (faza C) |
| D62 | Capability `osf_seo_manage_strategy` (0.16.0); płatna analiza SERP dodatkowo `osf_seo_manage_serp_tracking`; klient — aktywny backlog tylko do odczytu, bez notatek wewnętrznych i odrzuconych tematów | Least privilege; koszt wydaje tylko uprawniony do pomiarów. Sekcja 15.9 |
| D63 | Krok Strategii w tle (faza E) we wspólnym limicie czasu ticka wszystkich kroków po kolejce — bez osobnego budżetu czasu; przeliczenie nigdy przy renderowaniu | Kolejny krok nie wydłuża ticka ponad jeden limit. Sekcja 15.10 |

## 3. Repozytorium i środowiska

```
<root repo> = wp-content/
├── plugins/osf-seo/     # plugin (logika)
├── themes/seo/          # motyw Sage 11 (UI)
├── docs/                # ten dokument
├── AGENTS.md, CLAUDE.md, README.md
└── .gitignore           # whitelist + blokada plików z sekretami
```

| Środowisko | Opis |
|---|---|
| Lokalne | LocalWP; katalog `app/public/wp-content` strony jest kopią roboczą repo |
| Staging | `https://seo.ohsofresh.top` (Hostinger), osobna instalacja WordPress tylko dla aplikacji |
| Produkcja | do ustalenia |

## 4. Komponenty i podział odpowiedzialności

```
┌────────────────────────── seo.ohsofresh.top (jedna instalacja WP) ──────────────────────────┐
│  MOTYW SAGE (prezentacja)                      PLUGIN osf-seo (logika i dane)                 │
│  routes/web.php (Acorn)                        Auth: role, capabilities, ProjectGuard         │
│  kontrolery (cienkie)   ───── wywołuje ─────►  Projects / Google OAuth / Gsc / Sync           │
│  Blade + Tailwind + Alpine + Chart.js          Analytics (porównania, wzrosty, score)         │
│  walidacja formularzy, formatowanie PL   ◄──── DTO / tablice ────   REST /wp-json/osf-seo/v1  │
│                                                WP-CLI, harmonogram zadań, własne tabele       │
└──────────────────────────────────────────────────────────────────────────────────────────────┘
```

- **Kontrakt plugin → motyw**: kontrolery wołają usługi pluginu w tym samym procesie (`osf_seo()`),
  bez HTTP. Usługi analityczne przyjmują `ProjectContext` (już autoryzowany projekt), nie surowe ID.
- **REST pluginu** (`/wp-json/osf-seo/v1/...`) służy danym asynchronicznym (wykresy, zmiana zakresu);
  autoryzacja: ciasteczko WP + nonce (`X-WP-Nonce`), `permission_callback` używa `ProjectGuard`.
- **Strony panelu renderowane serwerowo** (Blade); sortowanie, filtry i paginacja w query stringu.
- **Routing** (STEP 4): trasy Acorn w motywie (`themes/seo/routes/web.php`, włączane przez
  `->withRouting(using: ...)` w `functions.php` z middleware `PanelMiddleware::GLOBAL`). Acorn 5
  dopasowuje trasę przy starcie motywu i obsługuje ją w `parse_request` (przed zapytaniem WordPressa);
  `/wp-admin`, `wp-login.php`, REST i pliki `*.php` są wyłączone spod routera. Ścieżki niepasujące do
  żadnej trasy obsługuje dalej WordPress (strony legacy motywu) — do czasu porządków C2.
  Wymagane „ładne” odnośniki WordPressa.
- **Uwierzytelnianie** ciasteczkiem WordPressa (`wp_signon` w `/login` albo `wp-login.php`).
  **Bez sesji Laravela** (`using:` pomija grupę `web`, więc nie są potrzebne `APP_KEY` ani zapisywalne
  `storage/framework/sessions`): CSRF = nonce WordPressa (`VerifyNonce`) + zgodność Origin/Referer,
  komunikaty flash = transient per użytkownik (`App\Panel\Flash`).

### 4.1 Trasy panelu (MVP 1)

`{project}` = `public_id` projektu. Brak dostępu lub nieistniejący projekt → zawsze **404**
(identyczna odpowiedź); widoczny projekt bez uprawnienia do operacji → **403**.

Zaimplementowane w STEP 4:

```
GET  /login  POST /login                     (gość; nonce + Origin, limit prób LoginThrottle)
POST /logout                                 GET  /                    (dashboard projektów)
GET  /settings                               (osf_seo_manage_settings)
GET  /projects[?status=archived]             GET  /projects/create     POST /projects
GET  /projects/{project}                     (przegląd; pusty stan → Search Console)
GET  /projects/{project}/edit                POST /projects/{project}  (osf_seo_manage_projects)
POST /projects/{project}/archive | /restore | /pause                   (osf_seo_manage_projects)
GET  /projects/{project}/{section}           pages | audit (placeholdery; keywords, search-console i opportunities — niżej)
ANY  /projects/{cokolwiek innego}            → 404 panelu (nie strona motywu)
```

Zaimplementowane w STEP 5:

```
GET  /projects/{project}/search-console      (stan połączenia; akcje dla osf_seo_manage_connections)
POST /projects/{project}/search-console/connect | /disconnect           (osf_seo_manage_connections)
GET  /oauth/google/callback                  (stały redirect URI; zalogowany użytkownik + state)
```

Zaimplementowane w STEP 6:

```
POST /projects/{project}/search-console/property  (osf_seo_manage_connections; wybór z listy sites.list, opcjonalnie reset_data=1)
GET  /projects/{project}/search-console?change=1  (lista properties dla zarządzających)
```

Zaimplementowane w STEP 9:

```
POST /projects/{project}/search-console/sync      (osf_seo_manage_connections; limit 1 / 5 min, bez duplikatów)
GET  /projects/{project}/search-console/status    (JSON stanu synchronizacji; dostęp do projektu)
```

Zaimplementowane w STEP 10:

```
GET  /projects/{project}                         (dashboard: KPI, TOP N, wzrosty/spadki, wykres; ?days=7|28|90)
GET  /projects/{project}/keywords                (lista fraz: ?days, q, pos_min, pos_max, min_impr, movement, sort, dir, page, per_page)
```

Zaimplementowane w STEP 12:

```
GET  /projects/{project}/market-data                   (dane rynkowe: stan, koszty i limity, podgląd planu — koszty i plan tylko z osf_seo_manage_market_data)
POST /projects/{project}/market-data/sync              (osf_seo_manage_market_data; expected_keywords z podglądu, nonce + Origin, raz na 5 min)
GET  /projects/{project}/keywords                      (dodatkowo: ?min_volume, max_kd, sort=volume|difficulty)
```

Zaimplementowane w STEP 11:

```
GET  /projects/{project}/opportunities                 (lista: ?days=7|28|90, type, status, priority, confidence, q, state, view=list|pages, page)
GET  /projects/{project}/opportunities/{opportunity}   (szczegóły; {opportunity} = public_id szansy, ULID; ?days)
POST /projects/{project}/opportunities/{opportunity}   (osf_seo_manage_opportunities; status, note, completed_on)
POST /projects/{project}/opportunities/analyze         (osf_seo_manage_opportunities; „Przelicz szanse”, limit 1 / 60 s)
```

Zaimplementowane w STEP 13 (`{run}`, `{candidate}` = ULID; mutacje i koszty — `osf_seo_manage_keyword_discovery`, nonce + Origin):

```
GET  /projects/{project}/discovery                         (lista: ?q, status, visibility, min_volume, max_kd, min_priority, intent, excluded, sort, dir, page)
GET  /projects/{project}/discovery/keywords/{candidate}    (szczegóły frazy)        POST …/keywords/{candidate} (status, note)
GET  /projects/{project}/discovery/runs/{run}              (przebieg)               GET  …/runs/{run}/status (JSON postępu; koszt tylko z capability)
GET  /projects/{project}/discovery/new                     (formularz)              POST /projects/{project}/discovery/preview (plan i koszt, bez API)
POST /projects/{project}/discovery/runs                    (uruchomienie: expected_requests, expected_cost z podglądu; raz na 60 s)
POST /projects/{project}/discovery/runs/{run}/cancel       POST /projects/{project}/discovery/bulk (status zaznaczonych)
POST /projects/{project}/discovery/exclusions              (wykluczone słowa projektu)
```

Zaimplementowane w STEP 14 (`{keyword}`, `{run}`, `{competitor}`, `?snapshot=` = ULID; mutacje, plan i koszty — `osf_seo_manage_serp_tracking`, nonce + Origin):

```
GET  /projects/{project}/positions                         (lista: ?q, band, change, sort, dir, page)
GET  /projects/{project}/positions/keywords/{keyword}      (szczegóły frazy: historia, wykres, pełne TOP N; ?snapshot=)
GET  /projects/{project}/positions/runs/{run}              (pomiar)                 GET  …/runs/{run}/status (JSON postępu; koszt tylko z capability)
GET  /projects/{project}/positions/check[?ids[]=…]         (podgląd pomiaru, bez API)  POST …/positions/check (expected_tasks, expected_cost)
POST /projects/{project}/positions/runs/{run}/cancel       GET/POST /projects/{project}/positions/settings (podgląd kosztu / zapis)
GET  /projects/{project}/positions/add                     POST …/positions/keywords (source=manual|gsc|discovery)   POST …/positions/remove
GET  /projects/{project}/competitors[?archived=1]          POST /projects/{project}/competitors (dodanie)
GET  /projects/{project}/competitors/organic               GET/POST /projects/{project}/competitors/{competitor} (szczegóły / edycja, status)
```

Zaimplementowane w STEP 15 (`{gap}`, `{cluster}`, `{run}`, `{competitor}` = ULID, `{url}` = klucz adresu MD5; mutacje, plan i koszty —
`osf_seo_manage_keyword_gap`, nonce + Origin):

```
GET  /projects/{project}/gaps                              (przegląd)               POST …/gaps/recalculate (przeliczenie w tle)
GET  /projects/{project}/gaps/keywords                     (luki fraz: ?type, competitor, visibility, content, status, intent, min_volume, max_kd, min_priority, filtered, q, sort, dir, page)
GET  /projects/{project}/gaps/keywords/{gap}               (szczegóły luki)         POST …/gaps/keywords/{gap} (status, note)
GET  /projects/{project}/gaps/content[/{cluster}]          (luki treści / grupa)    POST …/gaps/content/{cluster} (status, note)
GET  /projects/{project}/gaps/pages[/{competitor}/{url}]   (strony konkurencji / strona)
GET  /projects/{project}/gaps/import                       (formularz)              POST …/gaps/preview (plan i koszt, bez API)
POST /projects/{project}/gaps/runs                         (uruchomienie: expected_requests, expected_cost z podglądu; raz na 60 s)
GET  /projects/{project}/gaps/runs/{run}                   (import)                 GET  …/runs/{run}/status (JSON postępu; koszt tylko z capability)
POST /projects/{project}/gaps/runs/{run}/cancel            POST …/gaps/bulk (status zaznaczonych luk lub grup)
GET/POST /projects/{project}/gaps/settings                 POST …/gaps/schedule (enabled, confirm)   POST …/gaps/competitors/{competitor}/brand
```

Kolejne etapy:

```
GET  /projects/{project}/keywords/{keyword}
GET  /projects/{project}/pages[/{page}]      (MVP 2)
```

### 4.2 Struktura pluginu

```
plugins/osf-seo/
├── osf-seo.php          # nagłówek pluginu, kontrola wersji PHP, bootstrap
├── composer.json        # PSR-4 OsfSeo\ → src/ (Composer tylko dla narzędzi dev)
├── src/
│   ├── Plugin.php, Container.php, Autoloader.php, functions.php (osf_seo())
│   ├── Auth/            # Capabilities, Roles, RoleManager, ProjectGuard, ProjectContext, ProjectNotFound, AccessDenied
│   ├── Setup/           # Lifecycle (aktywacja/dezaktywacja), Installer (instalacja i aktualizacje, bez usuwania danych)
│   ├── Support/         # Config (stałe/env), Logger, Redactor (maskowanie sekretów)
│   ├── Cli/             # wp osf-seo status, db:*, project:*, google:*, gsc:*, sync:run, opportunities:*, dataforseo:*, discovery:*, serp:*, competitors:*
│   ├── Database/        # Connection ($wpdb + wyjątki, transakcje, GET_LOCK), BulkInsert, Migrator, Migrations/, Schema (spec), SchemaInspector
│   ├── Projects/        # Project, ProjectRepository, ProjectService, DomainNormalizer, statusy i role
│   ├── Http/            # HttpTransport (WP HTTP API), HttpResponse — cały ruch do Google
│   ├── Google/          # OAuthFlow, OAuthClient, OAuthStateStore, Pkce, TokenVault, ConnectionRepository, AccessTokenProvider, GoogleApi (ApiRequester)
│   ├── Gsc/             # GscClient (sites.list, searchAnalytics.query), PropertyService, GscProbe, GscImporter, Dictionary, GscDataStore
│   ├── Sync/            # WindowPlanner, SyncPlanner, SyncRunner (kolejka sync_runs), SyncScheduler (WP-Cron), SyncService
│   ├── Analytics/       # Metrics, Period, KeywordReport, OverviewReport, Visibility, ReportCache
│   ├── Opportunities/   # Szanse SEO (STEP 11): OpportunityDetector, CtrModel, OpportunityScorer, ConfidenceModel, Fingerprint, OpportunityExplainer, OpportunityConfig, OpportunityDataSource, OpportunityAnalyzer, OpportunityRepository, OpportunityService, OpportunityScheduler
│   ├── Market/          # Dane rynkowe (STEP 12): KeywordMetricsProvider (interfejs), MarketKeyword (normalizacja), MarketSyncService, repozytoria, plan, limity kosztów
│   ├── DataForSeo/      # DataForSeoClient (HTTP, Basic Auth, błędy), DataForSeoResponse, DataForSeoProvider, DataForSeoDiscoveryProvider (Labs), DataForSeoSerpProvider (Google Organic), DataForSeoRankedKeywordsProvider (Labs Ranked Keywords), DataForSeoMarkets, KeywordRules
│   ├── Discovery/       # Nowe frazy (STEP 13): KeywordDiscoveryProvider (interfejs), DiscoveryPlanner, DiscoveryRunner (jedyne płatne żądania), DiscoveryService, DiscoveryRefresher, VisibilityClassifier, DiscoveryScorer, SeedSuggester, SeedList, ExclusionList, repozytoria
│   ├── Rest/            # (później) endpointy dla panelu — w MVP 1 dane renderowane serwerowo + JSON stanu synchronizacji
│   ├── Serp/            # Pozycje SERP i konkurenci (STEP 14): SerpProvider (interfejs), SerpPlanner, SerpSubmitter (jedyne płatne zlecenia), SerpCollector, SerpStore, SerpDictionary, DomainFamily, RankChange, SerpTrackingService, CompetitorService, SerpReports, repozytoria
│   ├── Gap/             # Luki SEO (STEP 15): CompetitorKeywordsProvider (interfejs), GapPlanner, GapImporter (jedyne płatne żądania), GapRefresher, VisibilityResolver, GapClassifier, GapScorer, BrandMatcher, KeywordClusterer, ContentGapClassifier, GapService, GapReports, repozytoria
│   ├── Strategy/        # Strategia (STEP 16, faza A): StrategyConfig, CandidateSource (interfejs) + Sources/ (adaptery modułów), CandidateCollector, EvidenceBuilder, StrategyRefresher, StrategyService, repozytoria
│   └── Crawler/         # (MVP 3)
└── tests/               # PHPUnit: Unit (bez WordPressa), Integration (prawdziwy WP + MySQL/MariaDB)
```

### 4.3 Struktura panelu w motywie (STEP 4)

```
themes/seo/
├── functions.php                   # ->withRouting(using: …) + PanelMiddleware::GLOBAL
├── routes/web.php                  # trasy panelu (capabilities jako literały)
├── app/Http/Controllers/Panel/     # Auth, Dashboard, Project (przegląd = dashboard GSC), Keywords, Opportunities (szanse SEO), MarketData (dane rynkowe), Discovery (nowe frazy), ProjectSection, SearchConsole (OAuth, property, synchronizacja), Settings
├── app/Http/Middleware/Panel/      # PanelHeaders, UnslashInput, RequirePlugin, Authenticate, VerifyNonce, ResolveProject
├── app/Panel/                      # PanelUrl (adresy, bezpieczny redirect), PanelResponse (404/403/503), Flash, Format (liczby i daty PL)
├── app/View/Composers/Panel/       # Layout: użytkownik, projekty do przełącznika, bieżący projekt, flash
├── resources/css/panel.css         # osobne wejście Vite: Tailwind 4 (source(none)) + forms + tokeny brand-*
├── resources/js/panel.js           # Alpine (bez jQuery, GSAP, Reacta, CDN): postęp synchronizacji i wyszukiwania fraz; panel/chart.js — Chart.js ładowany dynamicznie tylko na dashboardzie
├── resources/views/panel/          # layouts/{base,guest,app}, auth/login, dashboard, projects/*, opportunities/*, discovery/*, settings, error
└── resources/views/components/panel/  # button, card, page-header, field, badge, flash, empty-state, nav-link, nonce, delta, stat, score, confidence, opportunity-status, visibility, candidate-status
```

### 4.4 Panel a wp-admin i motyw legacy

- Użytkownicy z `osf_seo_access` bez `manage_options` (klienci, `osf_seo_admin`) nie wchodzą do
  wp-admin (przekierowanie do panelu, `admin-post.php` i AJAX bez zmian), nie widzą paska admina,
  a po `wp-login.php` trafiają do panelu (`OsfSeo\Auth\WpAdminAccess`). Administrator WordPressa — bez zmian.
- Layout panelu nie woła `wp_head()`/`wp_footer()`: żadne skrypty ani style motywu marketingowego
  (GTM, Leaflet, Google Fonts, GSAP, jQuery) nie trafiają do panelu.
- `App\View\Composers\App` (composer dla `*`) nie wymaga już ACF (`get_field` tylko, gdy istnieje) —
  jedyna zmiana w kodzie legacy potrzebna, żeby panel działał bez ACF Pro.
- `panel.css` i `app.css` mają rozdzielone źródła klas; `app.css`/`editor.css` po dodaniu panelu są
  bajtowo identyczne. `app.js` współdzieli z `panel.js` chunk Alpine (`module.esm-*.js`) — zmiana
  tylko w podziale plików, nie w działaniu.

## 5. Uprawnienia

Zaimplementowane w STEP 1 (`plugins/osf-seo/src/Auth`). Kod sprawdza **capabilities**, nigdy nazwy ról.

| Capability | Znaczenie |
|---|---|
| `osf_seo_access` | wejście do panelu |
| `osf_seo_view_all_projects` | widzi wszystkie projekty (bez przypisania) |
| `osf_seo_manage_projects` | tworzy, edytuje i archiwizuje projekty |
| `osf_seo_manage_connections` | łączy konta Google / properties GSC, uruchamia synchronizację |
| `osf_seo_manage_users` | zarządza klientami i ich przypisaniem do projektów |
| `osf_seo_manage_settings` | ustawienia aplikacji |
| `osf_seo_manage_opportunities` | szanse SEO: status, notatka, data wdrożenia, ręczne przeliczenie (od STEP 11, wersja 0.11.0) |
| `osf_seo_manage_market_data` | płatna synchronizacja danych rynkowych (DataForSEO), podgląd planu, koszty i limity (od STEP 12, wersja 0.12.0) |
| `osf_seo_manage_keyword_discovery` | wyszukiwanie nowych fraz: plan i koszt, płatne uruchomienie, pobranie mimo cache, anulowanie, decyzje i notatki, wykluczenia, koszty (od STEP 13, wersja 0.13.0) |
| `osf_seo_manage_serp_tracking` | pozycje SERP: ustawienia i włączenie płatnych pomiarów, plan i koszt, pomiar ręczny, anulowanie, monitorowane frazy, konkurenci, koszty (od STEP 14, wersja 0.14.0) |
| `osf_seo_manage_keyword_gap` | luki SEO: plan i koszt, płatny import fraz konkurentów, anulowanie, przeliczenie, ustawienia analizy, harmonogram, warianty marki, status i notatki luk i grup, koszty (od STEP 15, wersja 0.15.0) |
| `osf_seo_manage_strategy` | strategia: przeliczenie, wpisy ręczne, a w kolejnych fazach status, notatki, strona docelowa, przypięcia i ustawienia; płatna analiza SERP dodatkowo z `osf_seo_manage_serp_tracking` (od STEP 16, wersja 0.16.0) |

| Rola | Capabilities |
|---|---|
| `administrator` (WordPress) | wszystkie powyższe (dopisywane do istniejących uprawnień) |
| `osf_seo_admin` — „OSF SEO — Administrator” | `read` + wszystkie powyższe; bez uprawnień administracyjnych WordPressa (least privilege dla pracowników agencji) |
| `osf_seo_client` — „OSF SEO — Klient” | `read` + `osf_seo_access`; widzi wyłącznie przypisane projekty (tylko odczyt) |

- Role są synchronizowane z definicją w kodzie przy aktywacji i przy zmianie wersji pluginu:
  brakujące uprawnienia są dodawane, a nadmiarowe usuwane z ról `osf_seo_*` (definicja w kodzie
  jest źródłem prawdy).
- Dezaktywacja pluginu niczego nie usuwa (role, opcje, w przyszłości dane i tokeny zostają).
### 5.1 Dostęp do projektów (STEP 3)

`OsfSeo\Auth\ProjectGuard` jest jedyną bramą: `authorize(public_id, user_id, capability)` → `ProjectContext`.

| Kto | Widzi |
|---|---|
| `osf_seo_view_all_projects` (administrator, `osf_seo_admin`) | wszystkie projekty, także zarchiwizowane |
| pozostali z `osf_seo_access` (`osf_seo_client`) | wyłącznie projekty przypisane w `osf_project_users`, bez zarchiwizowanych |
| bez `osf_seo_access` | nic (nawet przy wpisie w `osf_project_users`) |

- Filtr widoczności jest w SQL (`ProjectRepository`), nie w PHP po pobraniu danych.
- Brak dostępu jest nieodróżnialny od nieistnienia: `ProjectNotFound` (ta sama klasa, komunikat
  i kod) → **404**. Najpierw widoczność, potem uprawnienie — obcy projekt nigdy nie daje 403.
- Projekt widoczny, ale brak capability do operacji (np. klient próbuje edytować) → `AccessDenied` → **403**.
- `ProjectContext` ma prywatny konstruktor — tworzy go wyłącznie `ProjectGuard`, więc usługi
  przyjmujące kontekst nie mogą zostać wywołane dla projektu bez autoryzacji. Nie ma publicznej
  metody pobierającej projekt po wewnętrznym ID; w URL-ach wyłącznie `public_id` (ULID).
- Mutacje w `ProjectService` dodatkowo sprawdzają capability (obrona w głąb).
- Rola w projekcie (`manager`/`viewer`) jest zapisywana i dostępna w kontekście; w MVP zmiany danych
  wymagają globalnych capabilities (rozróżnienie ról — panel klienta, MVP 4).
- Dostęp systemowy (bez użytkownika) tylko z WP-CLI (`authorizeSystem`).
- Usunięcie konta WordPress usuwa jego przypisania do projektów (hook `deleted_user`).
- Testy IDOR (`tests/Integration/Projects/ProjectAuthorizationTest.php`) sprawdzają m.in. surowe ID,
  manipulacje identyfikatorem, zarchiwizowane projekty, brak `osf_seo_access` i nieodróżnialność błędów.

## 6. Model danych

Zaimplementowane w STEP 2: migracja `plugins/osf-seo/src/Database/Migrations/M0001CreateCoreTables.php`,
specyfikacja stanu docelowego `src/Database/Schema.php` (test integracyjny pilnuje, że migracje dają
dokładnie ten stan). Prefiks `{$wpdb->prefix}osf_`, wszystkie tabele InnoDB, `utf8mb4_unicode_ci`
(kolumny identyfikatorów technicznych: `ascii_bin`). **Bez kluczy obcych** (kaskady na milionach
wierszy blokują bazę) — spójność pilnuje aplikacja, projekt usuwamy zadaniem wsadowym.
Czasy (`*_at`) w UTC; kolumny `date` faktów GSC to daty GSC (czas pacyficzny).

### 6.1 Dlaczego nie jedna tabela `gsc_stats`

1. query × page × date × device × country daje wielokrotnie więcej wierszy niż potrzebne do listy
   fraz, TOP N, porównań i wykresów (wystarczy query × date).
2. Suma kliknięć po wierszach z wymiarem `query` jest mniejsza od sumy z GSC (anonimizacja fraz) —
   dlatego osobna tabela `site_daily` z prawdziwymi sumami.
3. Fraza i URL jako ID zamiast tekstu w każdym wierszu; tabela `pages` będzie wspólnym kluczem GSC + crawler.

### 6.2 Tabele MVP 1 (schemat w wersji 1)

**`osf_projects`** — `id` INT UNSIGNED AI; `public_id` CHAR(26) ascii_bin (ULID); `name` VARCHAR(190);
`domain` VARCHAR(190); `country` CHAR(2) (domyślnie `pl`); `language` VARCHAR(10); `status`
ENUM(active, paused, archived); `connection_id` INT UNSIGNED NULL; `gsc_property` VARCHAR(255) NULL;
`gsc_permission` VARCHAR(32) NULL; `gsc_data_property` VARCHAR(255) NULL (od schematu 2 — property, z której
pochodzą zapisane dane; sekcja 7.1); `settings` LONGTEXT NULL (JSON); `last_synced_at` DATETIME NULL;
`created_by` BIGINT UNSIGNED; `created_at`; `updated_at`.
PK(`id`), UNIQUE(`public_id`), indeksy (`status`), (`domain`), (`connection_id`).
Wymiar `country` w API GSC ma kody 3-literowe (`pol`) — mapowanie w kliencie GSC.

**`osf_project_users`** — `project_id` INT UNSIGNED; `user_id` BIGINT UNSIGNED; `role` ENUM(manager, viewer);
`created_at`. PK(`project_id`, `user_id`), indeks (`user_id`, `project_id`).

**`osf_connections`** (konto Google; jedno konto może obsługiwać wiele projektów) — `id` INT UNSIGNED AI;
`owner_user_id` BIGINT UNSIGNED; `provider` VARCHAR(20) ascii (`google`); `google_sub` VARCHAR(255) ascii;
`email` VARCHAR(190); `refresh_token_enc` TEXT ascii (wyłącznie szyfrogram); `scopes` TEXT;
`status` ENUM(active, needs_reauth, revoked); `last_error` VARCHAR(255); `last_refreshed_at`; `created_at`;
`updated_at`. PK(`id`), UNIQUE(`provider`, `google_sub`, `owner_user_id`), indeks (`owner_user_id`).

**`osf_keywords`** — `id` INT UNSIGNED AI; `project_id`; `keyword` VARCHAR(500); `keyword_hash` BINARY(16);
`first_seen` DATE; `last_seen` DATE; `created_at`. UNIQUE(`project_id`, `keyword_hash`), indeks (`project_id`, `last_seen`).

**`osf_pages`** — `id` INT UNSIGNED AI; `project_id`; `url` VARCHAR(2048); `url_hash` BINARY(16);
`path` VARCHAR(2048); `first_seen`; `last_seen`; `created_at`. UNIQUE(`project_id`, `url_hash`).

**Tabele faktów** — `project_id` INT UNSIGNED, `date` DATE, wspólne metryki: `clicks` INT UNSIGNED,
`impressions` INT UNSIGNED, `position_sum` DOUBLE (= Σ position × impressions).

| Tabela | PK (klastrowy) | Indeksy dodatkowe | Dataset GSC |
|---|---|---|---|
| `osf_gsc_site_daily` (+ `device` TINYINT UNSIGNED) | (`project_id`, `date`, `device`) | — | `[date, device]` — prawdziwe sumy |
| `osf_gsc_query_daily` | (`project_id`, `date`, `keyword_id`) | (`project_id`, `keyword_id`, `date`) | `[date, query]` |
| `osf_gsc_query_page_daily` (eksperymentalnie) | (`project_id`, `date`, `keyword_id`, `page_id`) | (`project_id`, `keyword_id`, `date`, `page_id`), (`project_id`, `page_id`, `date`, `keyword_id`) | `[date, query, page]` |
| `osf_visibility_daily` | (`project_id`, `date`) | — | wyliczane: `top3`, `top10`, `top20`, `top50`, `top100`, `keywords_total` (okno kroczące 7 dni) |

`osf_gsc_page_daily` (`[date, page]`, landing pages) powstanie w MVP 2 nową migracją.

**`osf_gsc_import_staging`** (od schematu 3) — tabela pośrednia importu: `run_id` BIGINT (= `sync_runs.id`), `date`,
`keyword_id`, `page_id` (0 = brak wymiaru), metryki; PK (`run_id`, `date`, `keyword_id`, `page_id`). Pusta poza
trwającymi importami (sekcja 8.2).

**`osf_sync_state`** — PK(`project_id`, `dataset` VARCHAR(32) ascii); `status` ENUM(idle, queued, running, failed, retrying);
`newest_date`; `oldest_date` (kursor backfillu); `refresh_cursor` (cykl odświeżania); `consecutive_failures`; `last_success_at`;
`last_attempt_at`; `last_refresh_at`; `retry_after` (przerwa planowania po błędzie); `last_error`; `updated_at`
(kolumny `refresh_cursor`, `last_refresh_at`, `retry_after` i wartość `retrying` — schemat 4).

**`osf_sync_runs`** (kolejka i historia widoczna w UI) — `id` BIGINT UNSIGNED AI; `project_id`; `dataset`;
`trigger_type` ENUM(schedule, manual, backfill, connect); `window_start`; `window_end`; `status`
ENUM(queued, running, success, failed, skipped, retrying, cancelled); `priority`; `attempt`; `rows_fetched`; `rows_written`;
`api_requests`; `error_code`; `error_message`; `queued_at`; `available_at`; `started_at`; `finished_at`; `locked_until`;
`property`. Indeksy: (`project_id`, `id`), (`status`, `queued_at`), `queue` (`status`, `priority`, `available_at`),
`project_dataset_status` (`project_id`, `dataset`, `status`). Retencja 90 dni (`priority`, `available_at`, `locked_until`,
`property`, nowe statusy i indeksy — schemat 4).

**Szanse SEO** (od schematu 5, migracja `M0005CreateOpportunities` — tylko nowe tabele, sekcja 10):

- **`osf_opportunities`** — `id`; `public_id` CHAR(26) ascii_bin (ULID, UNIQUE); `project_id`; `fingerprint` BINARY(16);
  `type` VARCHAR(32) ascii; `property` (property GSC danych); `page_url` VARCHAR(2048) NULL + `page_hash` BINARY(16) NULL;
  `keyword` VARCHAR(500) NULL; `state` ENUM(active, inactive, archived); `status` ENUM(new, review, planned, in_progress,
  completed, dismissed); `note` TEXT; `completed_on` DATE; `baseline` LONGTEXT (JSON); `last_priority`, `last_confidence`,
  `last_period_days`, `last_latest_date`, `last_evidence` (snapshot ostatniego wykrycia); `first_detected_at`, `last_detected_at`,
  `inactive_since`, `status_changed_at`, `status_changed_by`, `created_at`, `updated_at`.
  UNIQUE(`project_id`, `fingerprint`), indeksy (`project_id`, `state`, `status`), (`project_id`, `page_hash`).
- **`osf_opportunity_detections`** — PK(`opportunity_id`, `period_days`); `project_id`; `priority`; `confidence`; `impressions`;
  `clicks`; `latest_date`; `search_text` TEXT; `evidence` LONGTEXT (JSON); `analyzed_at`. Indeks (`project_id`, `period_days`, `priority`).
- **`osf_opportunity_analyses`** — PK(`project_id`, `period_days`); `status` ENUM(success, skipped, failed); `trigger_type`;
  `property`; `data_key` CHAR(32); `latest_date`; `opportunities`; `duration_ms`; `message`; `analyzed_at`.

**Dane rynkowe** (od schematu 6, migracja `M0006CreateMarketData` — nowe tabele i kolumna dopuszczająca NULL, sekcja 11.4):

- **`osf_market_keywords`** — `id`; `provider` ascii; `location_code`; `language_code` ascii; `keyword_key` BINARY(16) (MD5 postaci
  znormalizowanej); `keyword` (postać znormalizowana); `search_volume`, `cpc` DECIMAL(12,4), `competition_level`, `competition_index`,
  `low_top_of_page_bid`, `high_top_of_page_bid`, `keyword_difficulty` — wszystkie NULL = brak danych; `volume_fetched_at`,
  `volume_stale_after`, `volume_pending_until`, `volume_task_id`, `difficulty_fetched_at`, `difficulty_stale_after`, `difficulty_task_id`;
  `created_at`, `updated_at`. UNIQUE(`provider`, `location_code`, `language_code`, `keyword_key`).
- **`osf_market_keyword_monthly`** — PK(`market_keyword_id`, `month`); `search_volume`; `updated_at` (odświeżenie nadpisuje nakładające się miesiące).
- **`osf_market_tasks`** — zadania płatnego API: `provider`, `endpoint`, `mode` ENUM(standard, live), `trigger_type`, `project_id`, rynek,
  `provider_task_id`, `status` ENUM(pending, completed, failed, expired), `keywords_count`, `results_count`, `keywords` (JSON, usuwany po 30 dniach),
  `estimated_cost`, `cost` (zgłoszony przez API), `attempts`, `error_code`, `error_message`, czasy. Indeksy (`status`, `next_check_at`), (`created_at`), (`project_id`, `id`).
- **`osf_market_sync_state`** — PK(`project_id`, `provider`); `enabled_at`/`enabled_by` (pierwsza jawna synchronizacja), ostatni przebieg, sukces, błąd, `next_auto_at`.
- **`osf_keywords.market_key`** BINARY(16) NULL + indeks (`project_id`, `market_key`) — klucz rynkowy frazy GSC (wyliczany w tle).

Tabele danych rynkowych nie należą do `GscDataStore::DATA_TABLES` — reset property ich nie usuwa.

**Nowe frazy** (od schematu 7, migracja `M0007CreateKeywordDiscovery` — nowe tabele i kolumny dopuszczające NULL, sekcja 12):

- **`osf_market_keywords`** + `search_intent` VARCHAR(16) ascii NULL, `intent_fetched_at` DATETIME NULL (intencja z odpowiedzi discovery).
- **`osf_discovery_runs`** — `id`; `public_id` CHAR(26) ascii_bin; `project_id`; `provider`, `location_code`, `language_code`; `method`; `depth`;
  `status` ENUM(queued, running, completed, partial, failed, cancelled); `trigger_type`; `seeds_count`, `max_candidates`, `seed_limit`,
  `min_volume`, `max_difficulty`, `forced`, `options` (JSON); `tasks_planned`, `tasks_done`, `estimated_cost`, `cost` DECIMAL(12,6);
  `items_received`, `candidates_new`, `candidates_seen`, `rejected` (JSON: powód → liczba); `blocked_by`, `error_code`, `error_message`;
  `created_by`, `created_at`, `started_at`, `finished_at`, `updated_at`. UNIQUE(`public_id`), indeksy `project_run` (`project_id`, `id`),
  `status_run` (`status`, `id`).
- **`osf_discovery_run_seeds`** — PK(`run_id`, `seed_key` BINARY(16)); `seed`, `source` (manual/gsc/opportunity), `position`, `status`
  ENUM(pending, running, done, cached, failed, cancelled), `pages_done`, `next_offset`, `items`, `candidates_new`, `cost`, `task_id`
  (`market_tasks.id`), `attempts`, `error_code`, `next_attempt_at`, `started_at`, `finished_at`.
- **`osf_discovery_candidates`** — `id`; `public_id` CHAR(26) **utf8mb4_bin** (sekcja 12.14); `project_id`; `market_keyword_id`; `status`
  ENUM(new, review, accepted, dismissed); `note`; `status_changed_at`/`_by`; `seeds_count`, `best_relation`; `visibility` ENUM(unknown, none,
  low, visible), `gsc_impressions`, `gsc_clicks`, `gsc_position` DECIMAL(6,2), `target_url`; `priority` TINYINT NULL, `score` (JSON składników);
  `excluded`; `provider_meta` (JSON); `first_run_id`, `last_run_id`; `discovered_at`, `last_seen_at`, `scored_at`, `created_at`, `updated_at`.
  UNIQUE(`public_id`), UNIQUE `project_market_keyword` (`project_id`, `market_keyword_id`), indeks `project_priority` (`project_id`, `priority`).
- **`osf_discovery_candidate_sources`** — PK(`candidate_id`, `seed_key`, `method`); `seed`, `run_id`, `relation`, `depth`, `result_position`,
  `first_seen_at`, `last_seen_at`; indeks `run` (`run_id`).
- **`osf_discovery_settings`** — PK(`project_id`); `excluded_terms`, `refresh_key` (klucz danych przeliczenia), `refreshed_at`, `updated_by`, `updated_at`.

Tabele wyszukiwania nie należą do `GscDataStore::DATA_TABLES` — reset property ich nie usuwa (sekcja 12.6).

**Pozycje SERP i konkurenci** (od schematu 8, migracja `M0008CreateSerpTracking` — tylko nowe tabele, sekcja 13.3):

- **`osf_serp_contexts`** — `id` SMALLINT; `context_key` BINARY(16) UNIQUE; `engine`, `serp_type`, `location_code`, `language_code`, `device`,
  `os`, `depth`; `created_at`.
- **`osf_serp_settings`** — PK(`project_id`); `enabled` (domyślnie 0), `frequency` ENUM(daily, every_3_days, weekly), `device`, `depth`,
  `enabled_at`/`_by`, `next_run_at`, `retry_after`, `last_run_at`, `last_skip_reason`, `last_skip_at`, `updated_by`, `updated_at`; indeks `due` (`enabled`, `next_run_at`).
- **`osf_serp_competitors`** — `id`; `public_id` ascii_bin; `project_id`; `name`; `domain`, `domain_key` BINARY(16); `status` ENUM(active,
  inactive, archived); czasy i autorzy. UNIQUE `project_domain` (`project_id`, `domain_key`), indeks `project_status`.
- **`osf_serp_tracked_keywords`** — `id`; `public_id` CHAR(26) **utf8mb4_bin**; `project_id`; `market_keyword_id`; `source` ENUM(manual, gsc,
  discovery); `status` ENUM(active, removed); `added_by`, `added_at`, `removed_at`, `last_requested_at`; stan bieżący: `last_snapshot_id`,
  `last_context_id`, `last_checked_at`, `last_found`, `last_rank`, `last_rank_absolute`, `last_url_id`, `last_depth`, `last_featured`,
  `prev_snapshot_id`, `prev_found`, `prev_rank`, `change_type`, `change_value`, `top10_change`. UNIQUE `project_market_keyword`, indeksy
  `project_rank` (`project_id`, `status`, `last_rank`), `project_requested` (`project_id`, `status`, `last_requested_at`).
- **`osf_serp_runs`** — przebiegi (`trigger_type`, `slot_key`, `status`, liczby, koszty, błąd); UNIQUE `project_slot` (`project_id`, `slot_key`),
  indeksy `project_run`, `status_run`.
- **`osf_serp_snapshots`** — pomiary (ULID = `tag`, projekt, fraza, kontekst, przebieg, `market_task_id`, `provider_task_id`, `status`, koszt,
  czasy, metadane strony, wynik projektu); indeksy `tracked_history` (`tracked_keyword_id`, `context_id`, `checked_at`), `project_snapshot`,
  `collect` (`status`, `next_check_at`), `provider_task`, `run`, `market_task`.
- **`osf_serp_results`** — PK(`snapshot_id`, `item_index`); `result_type`, `rank_group`, `rank_absolute`, `page`, `domain_id`, `url_id`,
  `snippet_id`, `flags`; indeks `domain_snapshot` (`domain_id`, `snapshot_id`, `rank_group`).
- **`osf_serp_domains`** (`host_hash` UNIQUE, `host`, `host_rev` + indeks), **`osf_serp_urls`** (`url_hash` UNIQUE, `domain_id`, `url`),
  **`osf_serp_snippets`** (`snippet_hash` UNIQUE, `title`, `description`, `breadcrumb`, `website_name`, `extra` JSON).

Tabele SERP nie należą do `GscDataStore::DATA_TABLES` — reset property ich nie usuwa.

**Luki SEO** (od schematu 9, migracja `M0009CreateKeywordGap` — nowe tabele i kolumny, sekcja 14.3): `osf_gap_domains` (wspólne zbiory
domen), `osf_gap_domain_keywords` (frazy zbioru, PK `domain_id` + `market_keyword_id`), `osf_gap_domain_pages`, `osf_gap_domain_events`,
`osf_gap_runs`, `osf_gap_run_targets`, `osf_gap_settings`, `osf_gap_keywords` (luki fraz projektu), `osf_gap_clusters` (grupy, luka treści),
`osf_gap_competitor_pages`; kolumny `market_keywords.core_key`, `market_keywords.other_language`, `serp_competitors.brand_terms` oraz
wartość `gap` w `serp_tracked_keywords.source`. Tabele Luk SEO nie należą do `GscDataStore::DATA_TABLES`.

**Strategia** (od schematu 10, migracja `M0010CreateStrategy` — tylko nowe tabele, sekcja 15.4): `osf_strategy_settings` (stan przeliczenia,
klucz danych, rewizja mutacji ręcznych), `osf_strategy_keywords` (kandydaci: fraza rynkowa projektu, źródła, fakty GSC i SERP, odwołania do
modułów, dowody). Tabele Strategii nie należą do `GscDataStore::DATA_TABLES`.

**Wersja schematu**: opcja `osf_seo_db_version` (autoload), podbijana po każdej udanej migracji.

**Migracje** (`src/Database/Migrator.php`):
- migracja po wdrożeniu jest niezmienna; zmiana schematu = nowa migracja + aktualizacja `Schema.php`,
- `up()` idempotentne (`CREATE TABLE IF NOT EXISTS`, sprawdzanie kolumn/indeksów przed `ALTER`),
- blokada `GET_LOCK` (nazwa zależna od bazy i prefiksu) serializuje równoległe uruchomienia,
- po uzyskaniu blokady wersja czytana wprost z bazy (inny proces mógł ją podbić),
- uruchamiane: przy aktywacji, przy starcie pluginu po zmianie wersji pluginu **lub** schematu
  (z backoffem 5 min po błędzie — awaria nie zatrzymuje obsługi żądań) oraz ręcznie `wp osf-seo db:migrate`,
- żadna migracja nie usuwa danych bez osobnej, jawnej decyzji; dezaktywacja pluginu niczego nie usuwa.

### 6.2a Zmiany względem pierwotnego planu (STEP 2)

| Zmiana | Powód |
|---|---|
| ID encji `INT UNSIGNED` zamiast `BIGINT` (`user_id` pozostaje BIGINT jak `wp_users.ID`) | tabele faktów powtarzają klucze w PK i w każdym indeksie wtórnym — 4 zamiast 8 bajtów na kolumnę przy dziesiątkach milionów wierszy; zakres 4,29 mld wystarcza |
| `sync_runs.trigger` → `trigger_type` | `TRIGGER` jest słowem zastrzeżonym MySQL |
| `pages.path` VARCHAR(2048) zamiast 512 | ścieżka nie może być dłuższa niż URL, ale może przekraczać 512 znaków — bez obcinania danych |
| `connections.google_sub` VARCHAR(255) ascii zamiast VARCHAR(64) | OIDC dopuszcza `sub` do 255 znaków ASCII; ascii skraca klucz UNIQUE |
| `public_id`, `provider`, `google_sub`, `dataset`, `refresh_token_enc` w `ascii_bin` | identyfikatory techniczne: krótsze indeksy, porównanie binarne |
| `osf_gsc_page_daily` przesunięta do MVP 2 | tabela potrzebna dopiero dla widoku Pages |
| `sync_state.status` jako ENUM(idle, queued, running, failed) | wcześniej nieokreślony typ |

### 6.3 Zapytania (bez N+1, bez ładowania historii do PHP)

1. Jedno zapytanie na `query_daily` agreguje **oba okresy w jednym skanie**
   (`WHERE project_id = ? AND date BETWEEN prev_start AND cur_end GROUP BY keyword_id`)
   z warunkowymi sumami, filtrami w `HAVING`, sortowaniem po kolumnach z białej listy, `LIMIT` i `COUNT(*) OVER()`.
2. Dla fraz z bieżącej strony: jedno dociągnięcie strony docelowej (`query_page_daily`) i jej URL-i.
3. Cache dashboardu z kluczem (projekt, okres, ostatnia data, `last_synced_at`, źródło danych) — sekcja 8.3.

### 6.4 Skala i `query_page_daily`

| Scenariusz | `query_daily` / rok | `query_page_daily` / rok |
|---|---|---|
| 100 projektów × 300 wierszy/dzień | ok. 11 mln | ok. 30 mln |
| 100 projektów × 1500 wierszy/dzień | ok. 55 mln | ok. 150 mln |

`query_page_daily` najpierw mierzymy na kilku realnych projektach. Jeśli skala okaże się problemem:
dane dzienne przez 90 dni + rollup tygodniowy starszych danych.

### 6.5 Rezerwacje

Crawler (MVP 3): `osf_crawl_runs`, `osf_crawl_urls`, `osf_crawl_pages`, `osf_crawl_links` — łączone
z `osf_pages.id`. SERP (przyszłość, np. DataForSEO SERP API): osobna tabela snapshotów z dokładną pozycją, za interfejsem
dostawcy — nigdy mieszana ze średnią pozycją GSC.

## 7. Google OAuth

Zaimplementowane w STEP 5 (`plugins/osf-seo/src/Google`, `src/Http`; UI: `SearchConsoleController`).

Redirect URI (dokładnie ten zarejestrowany w Google Cloud) = `home_url('/oauth/google/callback')`:
**`https://seo.ohsofresh.top/oauth/google/callback`** na stagingu (lokalnie odpowiednik dla domeny Local z SSL).

```
[Projekt → Search Console → „Połącz z Google Search Console”]
   POST /projects/{project}/search-console/connect   (nonce + Origin, ResolveProject:osf_seo_manage_connections)
   ▼
OAuthFlow::start: PKCE — code_verifier = 32 losowe bajty (base64url), code_challenge = S256
state = 32 losowe bajty; transient osf_seo_oauth_<sha256(state)> → {user_id, project public_id, verifier, expires_at}
TTL 10 min, jednorazowy (usuwany przy pierwszym użyciu, także nieudanym); w bazie nie ma samego `state`
   ▼
302 → accounts.google.com/o/oauth2/v2/auth
      client_id, redirect_uri, response_type=code, scope=webmasters.readonly openid email,
      access_type=offline, prompt=consent, state, code_challenge, code_challenge_method=S256
   ▼   (logowanie i zgoda w Google)
GET /oauth/google/callback?state&code | ?state&error     (Authenticate: zalogowany użytkownik WP)
OAuthFlow::complete:
   1. state: istnieje, nie zużyty, nie wygasł, ten sam użytkownik WP (inaczej: state_invalid/expired/user_mismatch)
   2. ProjectGuard::authorize(projekt ze state, użytkownik, osf_seo_manage_connections) — ponowna autoryzacja
   3. ?error= (np. access_denied) → koniec bez wymiany kodu
   4. POST oauth2.googleapis.com/token (code, code_verifier, redirect_uri, client_id, client_secret)
   5. scope musi zawierać webmasters.readonly (ekran zgody pozwala go odznaczyć) — inaczej revoke i błąd
   6. id_token: iss = accounts.google.com, aud = nasz client_id, exp → sub + e-mail (podpisu nie
      weryfikujemy: token przychodzi bezpośrednio z endpointu tokenów przez TLS — OIDC Core §3.1.3.7)
   7. refresh token → TokenVault → osf_connections (owner = użytkownik, google_sub, e-mail, scopes)
      brak refresh tokenu → tylko istniejące aktywne połączenie tego konta, inaczej błąd
   8. projects.connection_id = połączenie (zmiana połączenia czyści wybrane property GSC)
   ▼
302 → /projects/{project}/search-console (komunikat); wybór property — kolejny etap
```

- **TokenVault**: XChaCha20-Poly1305 IETF (libsodium; bez rozszerzenia działa polyfill `sodium_compat`
  z WordPressa), losowy 24-bajtowy nonce, koperta `v1.<key id>.<base64url(nonce‖szyfrogram)>`,
  AAD = właściciel + `google_sub` (szyfrogramu nie da się przenieść do innego wiersza). Klucz
  `OSF_SEO_ENCRYPTION_KEY` tylko w `wp-config.php`/env; inny klucz → `key_mismatch` (połączenie nie
  zmienia statusu — to błąd konfiguracji, nie cofnięcie dostępu).
- **Access token** wyłącznie w pamięci procesu (`AccessTokenProvider`) do `expires_in − 60 s`; każdy nowy
  proces (np. zadanie synchronizacji) odświeża go z refresh tokenu. Rotowany refresh token zapisujemy
  zaszyfrowany.
- **401 z API** (`GoogleApi`) → wymuszone odświeżenie i jedno ponowienie; drugie 401 wraca do wywołującego.
  Bearer wysyłany wyłącznie do `https://*.googleapis.com`.
- **`invalid_grant`** (token cofnięty/wygasły) → połączenie `needs_reauth` (+ `last_error`), wyjątek
  `ReauthorizationRequired`, bez kolejnych prób; w UI „Połącz ponownie”. Inne błędy Google/sieci nie
  zmieniają statusu.
- **Rozłączenie** (`POST …/search-console/disconnect`): odpięcie projektu; jeśli połączenia nie używa
  inny projekt — `revoke` w Google i usunięcie z bazy (lokalnie usuwamy także wtedy, gdy revoke się nie
  powiedzie; UI podpowiada ręczne odwołanie). Revoke unieważnia całe nadanie dostępu dla konta Google.
- **Logi**: bez kodów, `state`, weryfikatorów, tokenów i sekretu klienta (Redactor + brak takich danych
  w kontekście); błędy Google tylko jako status HTTP + kod `error`.
- **Konfiguracja**: `wp osf-seo google:status`, panel → Ustawienia (tylko nazwy brakujących stałych).
- Tryb Testing (External): refresh token wygasa po 7 dniach, maks. 100 test users — przed produkcją
  publikacja aplikacji OAuth (weryfikacja wymagań Google w konsoli).

### 7.1 Property Search Console (STEP 6)

Zaimplementowane w `plugins/osf-seo/src/Gsc` (`GscClient::listSites`, `PropertyService`, `GscDataStore`);
UI: `SearchConsoleController` (`show`, `selectProperty`); CLI: `gsc:properties`, `gsc:select-property`.

- Lista properties pochodzi zawsze z `sites.list` połączonego konta (`GET https://www.googleapis.com/webmasters/v3/sites`),
  także przy zapisie — wartość z formularza nie jest zaufana (musi wystąpić na liście konta).
- Identyfikator Google (`sc-domain:example.pl`, `https://www.example.pl/`) jest zapisywany i wysyłany **bez zmian**
  (bez zmiany wielkości liter, ukośników itp.); w URL-u API jako jeden zakodowany segment (`rawurlencode`).
- Uprawnienia (`permissionLevel`): `siteOwner`, `siteFullUser`, `siteRestrictedUser` — dane Search Analytics dostępne;
  `siteUnverifiedUser` (i nieznane wartości) — odrzucane przy wyborze, widoczne na liście jako niedostępne.
  Poziom zapisujemy w `projects.gsc_permission`.
- Sugestia z domeny projektu (`DomainNormalizer`): property domenowa (100) > prefiks `https://` katalogu głównego (90)
  > `http://` (80) > prefiks z podścieżką (50/40); subdomeny i obce domeny nie są sugerowane. Sugestia jest tylko
  zaznaczona w formularzu — **zapis wymaga kliknięcia „Zapisz property”** (żadnego automatycznego wyboru).
- Listę widzą i property zmieniają wyłącznie użytkownicy z `osf_seo_manage_connections` (klient widzi tylko wybraną property).
- **Zmiana property (D18)**: `projects.gsc_data_property` = property, z której pochodzą zapisane dane
  (`gsc_property` jest czyszczona przy odłączeniu/zmianie połączenia, dane zostają).
  - brak danych → zapis property, nowe dane będą z niej pochodzić,
  - dane z tej samej property (np. po ponownym połączeniu konta) → zapis bez resetu, dane zostają,
  - dane z innej albo nieustalonej property → `reset_required`; po jawnym potwierdzeniu (`reset_data=1`,
    CLI `--reset-data`): (1) w transakcji z blokadą wiersza projektu odpięcie property i znacznika danych,
    (2) usunięcie partiami (`DELETE … LIMIT 5000`) faktów, słowników fraz/URL-i i stanu synchronizacji projektu,
    (3) zapis nowej property. Przerwany reset zostawia dane o nieustalonym pochodzeniu → kolejny wybór znów wymaga resetu.
  - Kontrola danych i zapis property odbywają się pod blokadą wiersza projektu (`SELECT … FOR UPDATE`) — tą samą,
    którą importer bierze przy zatwierdzaniu danych (sekcja 8), więc dane dwóch properties nie mogą się wymieszać.
- Błędy: brak połączenia, połączenie `needs_reauth` (także `invalid_grant` przy odświeżeniu tokenu), błąd API
  (z ponowieniami jak w sekcji 8), błąd klucza szyfrowania — komunikat w panelu, projekt bez zmian.

## 8. Przepływ danych GSC i reguły obliczeń

```
Google Search Console API  (sites.list, searchAnalytics.query)
        │  WP HTTP API (timeout 60 s), rowLimit 25 000 + startRow, ponowienia w żądaniu (8.1)
        ▼
Gsc\GscClient ── Google\GoogleApi (Bearer tylko do *.googleapis.com) ── AccessTokenProvider (token w pamięci)
        ▼
Sync\SyncRunner (kolejka sync_runs, sekcja 9) → Gsc\GscImporter (jedno zadanie = projekt × dataset × okno dat)
        │  strony → słownik fraz/URL-i → osf_gsc_import_staging (wsadowo)
        ▼
Baza (jedna transakcja z blokadą wiersza projektu): kontrola property → DELETE zakresu → INSERT … SELECT → COMMIT
        │  po sukcesie: sync_state (pokrycie, kursory), projects.last_synced_at (klucz cache raportów)
        ▼
Analytics\KeywordReport / OverviewReport (ProjectContext)  →  kontroler Sage  →  Blade + Alpine + Chart.js
```

### 8.0 Reguły obliczeń

Testowane jednostkowo (`Analytics\Metrics`) i integracyjnie (SQL raportów na ręcznie wyliczonych danych):

- **Średnia pozycja (GSC)** to średnia pozycja z wyświetleń, a nie dokładna pozycja w Google; w UI zawsze
  „Średnia pozycja (GSC)”. Okresu = `SUM(position_sum) / SUM(impressions)` (`position_sum = position × impressions`),
  nigdy `AVG(position)`.
- **CTR** = `SUM(clicks) / SUM(impressions)`, nigdy średnia CTR wierszy.
- `zmiana_pozycji = pozycja_poprzednia − pozycja_obecna` → dodatnia = **wzrost** (15 → 7 = +8, ↑), ujemna = spadek.
- Fraza obecna tylko w jednym okresie: „nowa” (brak zmiany pozycji) / „utracona” (poza listą bieżącego okresu).
- Różnice kliknięć/wyświetleń: bezwzględne; procentowe (KPI) przy 0 w poprzednim okresie — brak.
- **Sumy projektu** (KPI) pochodzą z `gsc_site_daily` (zapytanie `[date]`), nie z sumy fraz. GSC pomija zapytania
  zanonimizowane i ma limit wierszy na dzień, więc suma kliknięć fraz bywa mniejsza — to oczekiwane, nie „naprawiamy”
  tego. Dashboard pokazuje, jaka część kliknięć pochodzi z widocznych fraz.
- **Daty** GSC są w czasie pacyficznym (PT), zapisywane bez przeliczania; UI pokazuje „Dane GSC do: <data>”.
- **Okres** raportu: ostatnie N dni (7/28/90, domyślnie 28) **do ostatniej zaimportowanej daty** (nie „dziś”);
  porównanie z poprzednim okresem tej samej długości. Dashboard: koniec = najnowsza data obecna zarówno w sumach
  witryny, jak i we frazach.

### 8.1 Klient Search Console API (STEP 7)

`plugins/osf-seo/src/Gsc/GscClient.php` (na `Google\ApiRequester` = `GoogleApi`: Bearer tylko do `*.googleapis.com`,
po 401 jedno odświeżenie tokenu; timeout HTTP 60 s):

- `listSites()` — `GET /webmasters/v3/sites`,
- `query()` — `POST /webmasters/v3/sites/{rawurlencode(siteUrl)}/searchAnalytics/query` z treścią:
  `startDate`, `endDate`, `dimensions` (dowolne z `date, query, page, country, device, searchAppearance`),
  `type=web`, `dataState=final` (domyślnie; `all` tylko w probe), `aggregationType=auto`, `rowLimit` (1–25 000), `startRow`,
- `pages()` — generator stron: kolejne `startRow = n × rowLimit`, koniec na stronie krótszej niż `rowLimit`
  (także pustej). Ochrona: maks. `dni × 50 000 / rowLimit + 1` stron (Google udostępnia maks. ok. 50 000 wierszy
  dziennie na typ wyszukiwania; twardy limit 400), wykrywanie powtórzonej strony (odcisk: liczba, pierwszy i ostatni
  wiersz). 25 000 wierszy ≠ komplet danych — GSC pomija zapytania zanonimizowane.
- Walidacja każdego wiersza: `keys` = liczba wymiarów (same stringi), data w żądanym zakresie, `clicks`/`impressions`
  całkowite ≥ 0 (≤ INT UNSIGNED; JSON `5.0` akceptowane), `position` liczba ≥ 0. Naruszenie → `malformed_response`
  (cała strona odrzucona, nic nie jest zapisywane). CTR z API jest pomijany — liczymy go z sum.
- Błędy (`GscApiException` + `ErrorCategory`, kod trafia do `sync_runs.error_code`):

  | Kategoria | Źródło | Ponowienie w żądaniu | Ponowienie zadania (kolejka) |
  |---|---|---|---|
  | `rate_limited` | 429; 403 z powodem `rateLimitExceeded`, `userRateLimitExceeded`, `quotaExceeded`, `dailyLimitExceeded`, `RESOURCE_EXHAUSTED` | tak | tak |
  | `transient` | 500, 502, 503, 504 (także endpoint tokenów) | tak | tak |
  | `network` | brak odpowiedzi HTTP (DNS, timeout, TLS) | tak | tak |
  | `malformed_response`, `pagination` | treść niezgodna z kontraktem, niestabilna paginacja | nie | tak |
  | `permission_denied` (403), `not_found` (404), `bad_request` (400), `unauthorized` (401 po odświeżeniu), `http_error` | — | nie | nie |

  W żądaniu: maks. 2 ponowienia z przerwą 1 s i 3 s; `Retry-After` (sekundy) honorowany do 10 s, dłuższy → bez
  czekania, decyzję podejmuje kolejka. `invalid_grant` → `ReauthorizationRequired` (połączenie `needs_reauth`, sekcja 7).
- Logi: kategoria, status HTTP, powód Google — bez nagłówków (`Authorization`), treści żądań i odpowiedzi.

### 8.2 Import (STEP 8)

`plugins/osf-seo/src/Gsc/GscImporter.php` — jeden import = projekt × dataset × okno dat:

| Dataset | Wymiary | Tabela | Uwagi |
|---|---|---|---|
| `site` | `[date]` | `gsc_site_daily` (`device = 0` = wszystkie urządzenia) | prawdziwe sumy property, z zapytaniami zanonimizowanymi |
| `query` | `[date, query]` | `gsc_query_daily` | frazy widoczne w GSC |
| `query_page` | `[date, query, page]` | `gsc_query_page_daily` | agregacja Google per strona (`byPage`) |

`gsc_page_daily` (`[date, page]`) — MVP 2 (schemat jej nie ma).

1. **Pobranie**: `GscClient::pages()` (rowLimit 25 000, `startRow`), każda strona po walidacji → słownik
   fraz/URL-i → wsadowy INSERT do `osf_gsc_import_staging` (klucz `run_id`; PHP trzyma najwyżej jedną stronę).
   Zduplikowany klucz (data, fraza, strona) w odpowiedziach = niestabilna paginacja → błąd `pagination` (ponawiany).
2. **Zatwierdzenie** — jedna transakcja: `SELECT … FOR UPDATE` wiersza projektu; kontrola, że property zadania =
   `projects.gsc_property` = `projects.gsc_data_property` (inaczej `ImportAborted`, zadanie anulowane);
   `DELETE` zakresu dat projektu w tabeli faktów; `INSERT … SELECT` ze stagingu; `first_seen`/`last_seen` słowników
   z zatwierdzonych danych. Błąd Google/sieci/walidacji/bazy przed `COMMIT` zostawia poprzednie dane (test z triggerem
   `SIGNAL` w trakcie `INSERT` potwierdza wycofanie `DELETE`).
3. **Sprzątanie**: wiersze stagingu usuwane po sukcesie i po błędzie (osierocone — zadanie w STEP 9).

Import jest idempotentny: ten sam zakres zaimportowany ponownie daje identyczne wiersze (zamiana zakresu),
dane poza zakresem i innych projektów są nietknięte.

**Normalizacja** (`Gsc\Dictionary`):
- fraza = dokładny ciąg z GSC (bez zmiany wielkości liter, przycinania, normalizacji Unicode); tożsamość =
  MD5 dokładnych bajtów UTF-8 w `keyword_hash` (UNIQUE z `project_id`) — `Buty` i `buty` to dwie frazy, jak w GSC,
- URL = dokładny adres z GSC (`url_hash`), `path` (ścieżka + zapytanie) tylko do wyświetlania,
- tekst dłuższy niż kolumna (500 / 2048 znaków) jest skracany w kolumnie, skrót liczony z pełnej wartości,
- najpierw `SELECT` istniejących (paczki po 1000), `INSERT` tylko brakujących — `INSERT … ON DUPLICATE KEY` dla
  istniejących rezerwowałby przy każdym imporcie wartości AUTO_INCREMENT (ryzyko wyczerpania INT UNSIGNED).

**Pozycja**: `position_sum = position × impressions`; wiersz z 0 wyświetleń → `position_sum = 0` (nie wpływa na
średnie; GSC w praktyce nie zwraca takich wierszy). Średnia = `SUM(position_sum) / SUM(impressions)`.

**Wsady**: `Database\BulkInsert` — 1000 wierszy i maks. 512 KB SQL na zapytanie (wartości przez `$wpdb->prepare`).
Pomiar na MariaDB 10.11 (50 000 wierszy stagingu): 100 → ~79 tys. wierszy/s, 500 → ~112 tys., **1000 → ~123 tys.**
(ok. 48 KB/zapytanie), 5000 → ~129 tys. — powyżej 1000 zysk znikomy, a rośnie rozmiar pakietu (typowy
`max_allowed_packet` na hostingu 16 MB, minimalny spotykany 1–4 MB). Słownik: 25 000 nowych fraz ~0,6 s, istniejących ~0,2 s.

**Probe** (`GscProbe`, `wp osf-seo gsc:probe --project=<public_id>`): jedno zapytanie, domyślnie `[date]`, 7 dni
kończących się 3 dni przed „dziś” w PT, `rowLimit` 10 (maks. 1000), `dataState=final`; raport: property, uprawnienia,
zakres dat, liczba wierszy, kliknięcia, wyświetlenia, CTR (z sum), średnia pozycja ważona wyświetleniami, typ agregacji,
liczba żądań, próbka wierszy. **Nic nie zapisuje do bazy**, nie wypisuje tokenów. Wymaga `osf_seo_manage_connections`
(z `--user`) albo operatora CLI.

### 8.3 Analityka: frazy, TOP N, wzrosty i spadki, dashboard (STEP 10)

`plugins/osf-seo/src/Analytics`: `KeywordReport` (lista fraz), `OverviewReport` (dashboard), `Period`, `KeywordFilters`,
`Visibility`, `ReportCache`. UI: `KeywordsController` (`/projects/{project}/keywords`), `ProjectController::show`.

- **Lista fraz**: jedno zapytanie agreguje oba okresy w jednym skanie `gsc_query_daily` (zakres PK `project_id, date`)
  sumami warunkowymi; filtry w `HAVING` (pozycja od/do, min. wyświetleń, wzrosty/spadki), wyszukiwanie przez
  `keyword_id IN (SELECT … LIKE)` z escapowaniem `%`/`_`, sortowanie wyłącznie z białej listy (kliknięcia, Δ kliknięć,
  wyświetlenia, Δ wyświetleń, CTR, średnia pozycja, zmiana pozycji, fraza; brak wartości zawsze na końcu, stabilny
  tie-break), paginacja `LIMIT/OFFSET` (25/50/100) i liczba wyników `COUNT(*) OVER()` w tym samym zapytaniu
  (wymaga MariaDB ≥ 10.2 / MySQL ≥ 8.0). Do PHP trafia wyłącznie bieżąca strona.
- **Strona docelowa** frazy: jedno zapytanie dla fraz bieżącej strony (`gsc_query_page_daily`, indeks
  `project_id, keyword_id, date, page_id`) — strona z największą liczbą kliknięć (potem wyświetleń) w bieżącym okresie.
- **TOP 3/10/20/50/100** (`Visibility`): liczba fraz, których średnia pozycja (GSC) w okresie jest ≤ progu —
  **progi skumulowane** (TOP 10 zawiera TOP 3), granica włącznie (3,0 ∈ TOP 3), porównanie z poprzednim okresem.
  UI podkreśla, że to średnia pozycja GSC, a nie dokładny ranking SERP. (Wykres TOP N w czasie i `visibility_daily` — kolejny etap.)
- **Wzrosty/spadki**: sortowanie po zmianie średniej pozycji; tylko frazy z co najmniej
  `KeywordReport::MOVERS_MIN_IMPRESSIONS` = 10 wyświetleniami **w obu okresach** (stała; nadpisanie:
  `OSF_SEO_MOVERS_MIN_IMPRESSIONS`), bez własnego „score”. Dashboard: po 10 fraz.
- **Dashboard**: KPI (kliknięcia, wyświetlenia, CTR, średnia pozycja (GSC)) z sum witryny z porównaniem; TOP N;
  wzrosty/spadki; udział widocznych fraz; wykres dzienny (Chart.js ładowany osobnym plikiem tylko na dashboardzie,
  jedna oś, przełącznik kliknięcia/wyświetlenia, poprzedni okres przerywaną linią, tabela danych); stan synchronizacji.
- **Cache dashboardu** (`ReportCache`, transient 6 h): klucz zawiera `public_id`, okres, ostatnią datę,
  `projects.last_synced_at` i źródło danych — każdy import (i reset danych) zmienia klucz, więc dane nie są nieaktualne.
  Lista fraz nie jest cache'owana (wiele kombinacji filtrów).

**Wydajność** (`composer test:performance` = `tests/Performance/benchmark.php`: syntetyczne dane na osobnej bazie
testowej, czasy jako mediana 3 uruchomień, `EXPLAIN` zapytań; MariaDB 10.11 w kontenerze deweloperskim, bez strojenia):

| Zbiór | Lista fraz 28 dni | 90 dni (sort. zmiana pozycji) | Wyszukiwanie | Dashboard 28 dni | Dashboard 90 dni | Dashboard z cache |
|---|---:|---:|---:|---:|---:|---:|
| 2,4 mln wierszy `query_daily` (20 tys. fraz, ~5 tys./dzień, 480 dni) + 1 mln innego projektu | ~0,34 s | ~0,94 s | ~0,39 s | ~0,69 s | ~2,2 s | ~1 ms |
| 5,8 mln wierszy (60 tys. fraz, ~12 tys./dzień, 480 dni) + 2 mln innego projektu | ~1,2 s | ~2,8 s | ~0,86 s | ~2,1 s | ~7,4 s | ~1 ms |

`EXPLAIN`: agregacja fraz — `range` na `PRIMARY (project_id, date)` (skan tylko dni obu okresów jednego projektu;
dane innych projektów nie są czytane) + `Using temporary; Using filesort` dla `GROUP BY keyword_id`; złączenie ze
słownikiem — `range` na `project_last_seen` (po dodaniu warunku `k.project_id`; wcześniej pełny skan `keywords`);
strona docelowa — `range` na `project_keyword_date_page`; sumy witryny i seria — `range` na `PRIMARY` (≤ 180 wierszy);
`MAX(date)` — „Select tables optimized away”.

Wniosek: koszt rośnie liniowo z liczbą wierszy fraz w okresie (dni × frazy dziennie). Dla typowych projektów
(do kilku tysięcy fraz dziennie) czasy są poniżej sekundy; dla bardzo dużych property lista 90 dni i dashboard bez
cache trwają kilka sekund. Następne kroki przy takiej skali: tabela agregatów per fraza i okres przeliczana po
imporcie albo rollup tygodniowy (sekcja 6.4), ewentualnie indeks pokrywający `(project_id, date, keyword_id)` z metrykami.

## 9. Synchronizacja

Zaimplementowane w STEP 9 (`plugins/osf-seo/src/Sync`).

### 9.1 Kolejka i wykonanie (D19)

**Decyzja D19**: własna kolejka na tabeli `osf_sync_runs` + WP-Cron zamiast Action Scheduler. `sync_runs` od STEP 2
była zaprojektowana jako tabela zadań (status, próba, `queued_at`, indeks kolejki); Action Scheduler oznaczałby
dołączenie zewnętrznej biblioteki do publicznego repo, jej tabele i zależność od żądań loopback. Gdy skala tego
wymaga, kolejkę można przenieść na Action Scheduler bez zmiany importera i planisty.

```
WP-Cron „osf_seo_sync_tick” (co minutę)   albo   cron systemowy: wp osf-seo sync:run
   │  (co 5 min) SyncScheduler::planAll → SyncPlanner::plan dla projektów kwalifikujących się
   ▼
SyncRunner::run(budżet 20 s, maks. 25 zadań)  — GET_LOCK: jeden runner w całej instalacji
   │  zadania „running” bez żywego runnera → ponowienie (error_code interrupted)
   │  claimNext: status queued|retrying, available_at <= teraz, ORDER BY priority, available_at, id
   ▼
ProjectGuard::authorizeSystem (tylko WP-CLI i WP-Cron) → GscImporter (sekcja 8.2) → stan datasetu → SyncPlanner (kolejne okno)
```

- Budżet sprawdzany między zadaniami (pojedynczy import może trwać dłużej; `set_time_limit` ≥ 120 s,
  `wp_raise_memory_limit`). Brak nieskończonej pętli: limit czasu i liczby zadań na uruchomienie.
- Statusy zadań (`sync_runs.status`): `queued`, `running`, `retrying` (czeka na ponowienie, `available_at` w przyszłości),
  `success`, `failed`, `skipped` (projekt wstrzymany/zarchiwizowany), `cancelled` (zmiana property, reset danych,
  `needs_reauth`). Zadanie zapisuje: okno dat, dataset, źródło (`trigger_type`), próbę, `rows_fetched`, `rows_written`,
  `api_requests`, `error_code` (kategoria), `error_message` (skrócony, bez tokenów), czasy, property.
- Stan projektu dla UI (`SyncService::status`): `queued`/`running`/`retrying` (z zadań), `success`, `partial`
  (część datasetów z błędem), `failed`, `never`, `needs_reauth`, `not_ready`.
- Lokalnie (LocalWP, bez crona systemowego) WP-Cron uruchamia ruch na stronie — także wejście do panelu;
  na produkcji: `define('DISABLE_WP_CRON', true);` i cron systemowy co minutę:
  `wp --path=<ścieżka> osf-seo sync:run` (zalecane; planowanie + kolejka) albo `wp cron event run --due-now`,
  a bez WP-CLI: `wget -q -O - https://seo.ohsofresh.top/wp-cron.php?doing_wp_cron >/dev/null 2>&1`.
  Hostinger: hPanel → Zaawansowane → Cron Jobs (minimalny interwał zależny od planu; co 1–5 min wystarcza).
- Heartbeat: opcja `osf_seo_sync_heartbeat` (ostatnie uruchomienie runnera) — `wp osf-seo status` (`sync_queue`)
  i `wp osf-seo gsc:status` pokazują, czy kolejka żyje.
- Utrzymanie (raz na dobę, w runnerze): usunięcie zakończonych zadań starszych niż 90 dni i osieroconego stagingu.

### 9.2 Planowanie (backfill i odświeżanie)

`WindowPlanner` (czysta logika, testy jednostkowe) + `SyncPlanner` (idempotentnie, pod blokadą wiersza projektu):
po jednym oczekującym zadaniu na dataset i rodzaj (`refresh` — najnowsze dane, `backfill` — historia).
Projekt kwalifikuje się, gdy jest aktywny, ma aktywne połączenie, wybraną property i dane z tej samej property.
Daty GSC liczone w czasie pacyficznym („dziś” w `America/Los_Angeles`).

| Etap | Okno |
|---|---|
| Pierwszy import sum (`site`, po wyborze property) | cała historia: dziś − 16 miesięcy … wczoraj, jedno zadanie (kilkaset wierszy) → najstarsza i najnowsza data z danymi (ostatnia data `final`) |
| Pierwsze okno fraz (`query`, `query_page`) | najnowsze dni: [ostatnia data − w + 1, ostatnia data] → dashboard działa po kilku zadaniach |
| Backfill | od najstarszej zaimportowanej daty wstecz, okno po oknie (**najnowsze → najstarsze**), do max(dziś − 16 mies., najstarsza data sum) |
| Odświeżanie sum (co ≥ 20 h) | [najnowsza − 6 dni, wczoraj] — okno kroczące + nowe dni |
| Odświeżanie fraz (po odświeżeniu sum albo nowej dacie) | od min(najnowsza + 1, ostatnia data − 6) do ostatniej daty, okno po oknie (`sync_state.refresh_cursor`) |

- **Okno kroczące 7 dni** (`OSF_SEO_SYNC_REFRESH_DAYS`, 3–30): dane GSC dopracowują się po pierwszym pojawieniu,
  a `dataState=final` pojawia się z opóźnieniem ok. 2–3 dni; codziennie importujemy ponownie ostatnie 7 dni, a nie
  tylko „wczoraj”. Starsza historia nie jest pobierana ponownie. Po przerwie (np. cron nie działał) okno zaczyna się
  od najnowszej zaimportowanej daty — luka jest uzupełniana.
- **Historia**: `OSF_SEO_GSC_HISTORY_MONTHS` (domyślnie 16, maks. 16) — nie zakładamy, że Google gwarantuje stałą liczbę
  miesięcy: dolną granicą backfillu jest najstarsza data, dla której Google zwrócił sumy witryny.
- **Szerokość okna** z gęstości danych (wiersze/dzień ostatniego udanego zadania): cel 50 000 wierszy na zadanie
  (≈ 2 strony API); `query` 1–31 dni (domyślnie 7), `query_page` 1–14 dni (domyślnie 3).
- **Priorytety**: ręczne −5; `site` 10; odświeżanie fraz 20/25; backfill ostatnich 56 dni 30; starszy backfill 40/50.
- Pokrycie datasetu jest ciągłe: `[oldest_date, newest_date]`; postęp backfillu = pokryte dni / dni dostępnej historii.

### 9.3 Błędy i ponowienia

| Błąd | Reakcja |
|---|---|
| 429, 403 z powodem limitu, 5xx, sieć | w żądaniu: 2 ponowienia (1 s, 3 s; `Retry-After` ≤ 10 s); potem zadanie `retrying`: 1 min → 5 min → 30 min → 2 h (`Retry-After` wydłuża, maks. 6 h); po 5. próbie `failed` + przerwa planowania datasetu 6 h |
| uszkodzona odpowiedź, niestabilna paginacja, błąd bazy, przerwany proces | jak wyżej (ograniczone ponowienia zadania) |
| 400, 403 (brak dostępu do property), 404, błąd klucza szyfrowania | `failed` bez ponowień + przerwa 24 h (bez pętli błędów) |
| `invalid_grant` | połączenie `needs_reauth`; zadanie `failed` (`needs_reauth`); oczekujące zadania wszystkich projektów tego połączenia `cancelled`; planowanie wstrzymane do ponownego połączenia (potem wznawia się samo — kursory w `sync_state` zostają) |
| zmiana property / reset danych w trakcie | zadanie `cancelled`, importer nie zatwierdza danych (kontrola pod blokadą wiersza projektu) |

„Synchronizuj teraz” zdejmuje przerwę po błędach (`retry_after`).

### 9.4 Ręczna synchronizacja

`POST /projects/{project}/search-console/sync` (`osf_seo_manage_connections`, nonce + Origin) → `SyncService::requestSync`:
w transakcji z blokadą wiersza projektu (równoległe kliknięcia są serializowane) — limit jednego zlecenia na 5 minut
(`rate_limited`), brak nowych zadań, gdy odświeżanie czeka lub trwa (`already_queued`), inaczej wymuszone odświeżenie
(sumy → frazy). Strona Search Console pokazuje stan, postęp backfillu, pokrycie datasetów, ostatnie zadania; w trakcie
synchronizacji odświeża postęp z `GET …/search-console/status` (JSON, dostęp do projektu) co 10 s.

CLI: `gsc:sync` (`--force` pomija limit 5 min, `--run` wykonuje zadania projektu od razu), `gsc:backfill [--run]`,
`gsc:status [--format=json]`, `sync:run [--time-limit] [--max-jobs]`.

### 9.5 Operacje na stagingu (pierwsze uruchomienie z prawdziwym Google)

Wszystkie komendy w katalogu instalacji (`wp --path=<ścieżka WordPressa>` albo z katalogu `public_html`); żadna nie
wypisuje tokenów. `<id>` = `public_id` projektu (`wp osf-seo project:list`).

```bash
wp osf-seo db:migrate                      # schemat 4 (wykonuje się też sam po wdrożeniu); dane i połączenie zostają
wp osf-seo status                          # w tym sync_queue (heartbeat kolejki)
wp osf-seo gsc:properties --project=<id>   # properties konta (site_url, uprawnienia, suggested)
wp osf-seo gsc:select-property --project=<id> --property='sc-domain:example.pl'   # albo w panelu: Search Console
wp osf-seo gsc:probe --project=<id>                                   # 7 dni [date], 10 wierszy, bez zapisu
wp osf-seo gsc:probe --project=<id> --dimensions=query --limit=10    # próbka fraz
wp osf-seo gsc:sync --project=<id> --run --time-limit=300           # pierwszy import (sumy → najnowsze frazy) od razu
wp osf-seo gsc:status --project=<id>                                 # stan, pokrycie, postęp, ostatnie zadania
wp osf-seo gsc:backfill --project=<id> --run --time-limit=600       # historia (można przerwać i powtórzyć)
```

- Wybór property w panelu sam planuje pierwszy import; `--run` tylko przyspiesza wykonanie (bez czekania na WP-Cron).
- Backfill jest bezpieczny do wielokrotnego uruchamiania: zamiana zakresu jest idempotentna, planista nie dubluje zadań,
  a jeden runner (GET_LOCK) wyklucza równoległe importy; przerwanie (Ctrl+C, timeout) → zadanie wraca do kolejki.
- Cron: w hPanelu dodać zadanie co minutę `wp --path=<ścieżka> osf-seo sync:run --time-limit=50` (i opcjonalnie
  `define('DISABLE_WP_CRON', true);`); bez crona systemowego WP-Cron wykonuje kolejkę tylko przy ruchu na stronie.
- Weryfikacja danych: `gsc:probe --project=<id> --start=<pierwszy dzień okresu> --end=<ostatni> --limit=31` (`[date]`)
  daje te same kliknięcia/wyświetlenia co KPI dashboardu dla tego zakresu; raport „Skuteczność” w Search Console
  (te same daty) — sumy kliknięć i wyświetleń jak w KPI; CTR i średnia pozycja liczone z sum.

## 10. Szanse SEO (STEP 11)

Zaimplementowane w STEP 11 (`plugins/osf-seo/src/Opportunities`; UI: `OpportunitiesController`, `/projects/{project}/opportunities`).
Moduł analizuje **wyłącznie dane Google Search Console już zapisane w bazie** i odpowiada na pytania: gdzie są realne
szanse, dlaczego dana podstrona/fraza się pojawiła, co sprawdzić i od czego zacząć.

> **Szanse to sygnały do sprawdzenia, nie gwarancja wzrostu.** Pozycja to średnia pozycja (GSC), a nie dokładny ranking.
> System nie analizuje HTML, title, meta description, treści, linków ani wyników konkurencji — rekomendacje są hipotezami
> i kolejnymi krokami do ręcznego sprawdzenia. Wykrywanie, priorytet i pewność opierają się wyłącznie na danych GSC; dane rynkowe
> DataForSEO (STEP 12) są w szczegółach szansy tylko dodatkowym kontekstem (D28). Bez SERP API i AI.

### 10.1 Architektura i zapis (D20)

```
gsc_query_daily ─┐                    OpportunityDataSource (agregaty SQL obu okresów, bez historii dziennej w PHP)
gsc_query_page_daily ─┤  ──►  KeywordReport::aggregate, pary fraza × podstrona, sumy podstron, segmenty kandydatów
gsc_site_daily ─┘                     ▼
                              OpportunityDetector (czysta logika: CtrModel, OpportunityScorer, ConfidenceModel)
                                      ▼  Candidate (typ, odcisk, priorytet, pewność, dowody JSON)
                              OpportunityRepository::replaceDetections (transakcja, blokada wiersza projektu, kontrola property)
                                      ▼
osf_opportunities (stan pracy, trwały) + osf_opportunity_detections (wykrycia okresu, pochodne) + osf_opportunity_analyses
                                      ▼
                              OpportunityService (ProjectContext) → OpportunitiesController → Blade
```

**Model hybrydowy (D20)**: dowody są wyliczane z danych GSC (źródłem prawdy pozostają tabele faktów — nie kopiujemy ich),
a stan pracy jest trwały:

- `osf_opportunities` — jedna szansa = projekt × **stabilny odcisk** (`UNIQUE(project_id, fingerprint)`), `public_id` (ULID)
  w URL-ach; typ, property, podstrona/fraza; `state` (stan wykrycia) i `status` (stan pracy), notatka, data wdrożenia,
  baseline, pierwsze/ostatnie wykrycie, ostatni snapshot dowodów (historia po zniknięciu sygnału),
- `osf_opportunity_detections` — wynik ostatniej analizy okresu (7/28/90 dni): priorytet, pewność, dowody, tekst do wyszukiwania;
  `PK(opportunity_id, period_days)`, indeks `(project_id, period_days, priority)` pod listę; **zastępowane** przy każdej analizie okresu,
- `osf_opportunity_analyses` — stan analizy per projekt × okres (klucz danych, data końca, czas, wynik, powód pominięcia).

Analiza nigdy nie zmienia pól pracy (status, notatka, data wdrożenia, baseline). Lista i filtry działają w SQL na wykryciach
(paginacja 25), więc strona nie wykonuje agregacji historii GSC — utrwalone wykrycia pełnią rolę cache wyników analizy
(unieważnianej kluczem danych). `ReportCache` jest używany dla obserwacji po wdrożeniu.

### 10.2 Kategorie i reguły wykrywania

Progi podane dla okresu 28 dni; progi ilościowe są skalowane liniowo do 7/90 dni z dolną granicą (sekcja 10.9).
Metryki zawsze z sum: CTR = `SUM(clicks) / SUM(impressions)`, pozycja = `SUM(position_sum) / SUM(impressions)`,
zmiana pozycji = poprzednia − obecna. Frazy: `gsc_query_daily` (pozycja frazy); podstrony i kanibalizacja: `gsc_query_page_daily`.

| Typ (UI) | Reguła (fraza) |
|---|---|
| **Niski CTR przy dużej widoczności** (`low_ctr`) | średnia pozycja ≤ 20, ≥ 100 wyświetleń, CTR ≤ 0,6 × **referencyjny CTR** przedziału pozycji (10.3) i luka kliknięć (wyświetlenia × ref − kliknięcia) ≥ 5 |
| **Blisko TOP 3 / TOP 10** (`near_top`) | pozycja (3; 10] → cel TOP 3, (10; 20] → cel TOP 10; ≥ 50 wyświetleń |
| **Duża widoczność, słaba pozycja** (`weak_position`) | pozycja (20; 100], ≥ 200 wyświetleń (rozłączne z „blisko TOP”) |
| **Istotny spadek** (`decline`) | poprzedni okres w pełni zaimportowany i fraza miała w nim ≥ 100 wyświetleń, oraz co najmniej jeden sygnał: **kliknięcia** (baza ≥ 10, strata ≥ 5 i ≥ 30%), **wyświetlenia** (strata ≥ 100 i ≥ 30%), **pozycja** (≥ 50 wyświetleń teraz, pogorszenie ≥ max(2, 20% poprzedniej pozycji)). Dodatkowo **spadek całej podstrony** (suma jej widocznych fraz): kliknięcia (baza ≥ 20, strata ≥ 10 i ≥ 30%) albo wyświetlenia (strata ≥ 300 i ≥ 30%) — także gdy żadna fraza z osobna nie przekracza progów |
| **Możliwa kanibalizacja** (`cannibalization`) | fraza z ≥ 100 wyświetleniami (suma adresów), ≥ 2 adresy z udziałem ≥ 15% i ≥ 20 wyświetleniami, **nie wszystkie** z pozycją ≤ 3 (dwa wyniki w czołówce / sitelinki to nie problem); adresy różniące się tylko `#fragmentem` = jedna podstrona |

- **Szum**: 1 → 0 kliknięć, 2 → 0 wyświetleń, 7,1 → 7,3 przy małej liczbie wyświetleń nie dają spadku (próg bazy + próg
  bezwzględny + próg względny). Bez pełnego poprzedniego okresu nie ma spadków (brak porównania).
- **Pierwszeństwo**: fraza ze spadkiem trafia tylko do „Spadków” (nie do niskiego CTR, blisko TOP ani słabej pozycji) —
  jedna fraza, jedno pilne działanie. Niski CTR i „blisko TOP” mogą dotyczyć tej samej frazy (różne działania: opis wyniku vs treść).
- **Kanibalizacja — sygnały**: udział wyświetleń każdego adresu, kliknięcia, średnia pozycja, zmiana dominującego adresu
  (najwięcej kliknięć, potem wyświetleń) między okresami i w kolejnych segmentach okresu (7 dni dla okresów ≥ 28 dni,
  1 dzień dla 7 dni). Etykieta zawsze „Możliwa kanibalizacja”.

### 10.3 Referencyjny CTR (zależny od pozycji)

CTR silnie zależy od średniej pozycji, więc niski CTR jest oceniany względem przedziału pozycji (`CtrModel`, granice w połowie,
bo pozycje GSC są średnimi):

| Przedział | 1 | 2–3 | 4–5 | 6–10 | 11–20 | 21–50 | 51–100 |
|---|---:|---:|---:|---:|---:|---:|---:|
| Pozycja | < 1,5 | 1,5–3,5 | 3,5–5,5 | 5,5–10,5 | 10,5–20,5 | 20,5–50,5 | ≥ 50,5 |
| Domyślny CTR | 28% | 14% | 7% | 3% | 1% | 0,4% | 0,1% |

Referencja przedziału = **mediana CTR fraz projektu** w okresie (CTR frazy = jej kliknięcia / jej wyświetlenia; frazy z ≥ 30
wyświetleniami), gdy próbka ma ≥ 8 fraz; inaczej wartość domyślna (ostrożne przybliżenie). To rozkład referencyjny, nie CTR
okresu — CTR fraz, grup i projektu zawsze liczymy z sum. Mediana zamiast sumy ważonej: kilka fraz brandowych z bardzo wysokim
CTR nie zawyża punktu odniesienia. Referencja trafia do dowodów (źródło: projekt / domyślna, liczba fraz).

### 10.4 Priorytet (Opportunity Score 0–100)

„Jak bardzo warto to sprawdzić” — nie prognoza wzrostu. `L(x, cap) = min(1, log10(1 + x) / log10(1 + cap))` — skala
logarytmiczna z limitem, więc jedna ogromna fraza nie dominuje liniowo. Limity dla 28 dni: `demand_cap` = 11 200 wyświetleń,
`clicks_cap` = 280 kliknięć (skalowane do okresu).

**Priorytet = Popyt (0–30) + Skala (0–50) + Trend (0–20)**, zaokrąglony i ograniczony do 0–100:

| Typ | Popyt (0–30) | Skala (0–50) | Trend (0–20) |
|---|---|---|---|
| Niski CTR | 30 × L(wyświetlenia fraz) | 30 × L(luka kliknięć) + 20 × (1 − min(1, kliknięcia / kliknięcia przy referencji)) | 20 × min(1, spadek CTR vs poprzedni okres / 50%) |
| Blisko TOP | jw. | 30 × L(potencjał kliknięć) + 20 × bliskość celu | 10 × min(1, wzrost wyświetleń) + 10 × min(1, poprawa pozycji / 5) |
| Słaba pozycja | jw. | 30 × L(potencjał kliknięć do TOP 20) + 20 × (100 − pozycja) / 80 | jw. |
| Spadek | 30 × L(wyświetlenia w poprzednim okresie) | 30 × L(utracone kliknięcia) + 20 × min(1, strata względna) | 20 × min(1, pogorszenie pozycji / 5) |
| Możliwa kanibalizacja | 30 × L(wyświetlenia fraz) | 30 × wyrównanie podziału + 20 × min(1, (liczba fraz − 1) / 4) | 20 × udział wyświetleń fraz ze zmianą dominującego adresu |

- Potencjał kliknięć = Σ max(0, wyświetlenia × CTR referencyjny celu − kliknięcia) (cel TOP 3 → przedział 2–3, TOP 10 → 6–10,
  TOP 20 → 11–20) — szacunek, nie obietnica. Bliskość: TOP 3 `(10 − poz.) / 7`, TOP 10 `(20 − poz.) / 10` (ważona wyświetleniami).
- Utracone kliknięcia = max(strata kliknięć, strata wyświetleń × CTR poprzedniego okresu); strata względna liczona od kliknięć tylko
  przy bazie ≥ 10 kliknięć (inaczej od wyświetleń) — 2 → 0 kliknięć nie daje „−100%”.
- Wyrównanie podziału = min(1, (1 − udział największego adresu) / 0,5), ważone wyświetleniami fraz.
- Składniki trendu liczone tylko przy wystarczającej próbie w poprzednim okresie (wzrost z 20 wyświetleń to szum).
- UI pokazuje rozbicie punktów i wartości wejściowe (dowody `score`).

**Zmiana względem pierwotnej specyfikacji (MVP 2)**: poprzednia wersja opisywała jedną kategorię (frazy na pozycjach 4–20)
z „Bliskością TOP 35 + Popytem 30 (percentyl wyświetleń) + Luką CTR 20 + Impetem 15”. Zachowane: luka CTR, potencjał kliknięć,
impet (trend), popyt. Zmienione: (1) moduł ma 5 kategorii i grupy podstron, więc „skala” zależy od typu; (2) percentyl
wyświetleń zastąpiony skalą logarytmiczną z limitem — percentyl zmienia się z każdą inną frazą (niestabilny między przeliczeniami)
i nasyca się dla grup kilku fraz; (3) pewność wydzielona z priorytetu.

### 10.5 Pewność (Niska / Średnia / Wysoka)

Osobno od priorytetu (np. priorytet 60, pewność niska = duży potencjał na małej próbie). Punkty 0–4:
próba — wyświetlenia grupy ≥ 1000 → 2 pkt, ≥ 300 → 1 pkt (28 dni, skalowane); porównanie — poprzedni okres w pełni
zaimportowany i grupa miała w nim wyświetlenia → 1 pkt; spójność → 1 pkt: niski CTR także w poprzednim okresie / pozycja
stabilna (≥ 50% wyświetleń z fraz w tym samym zakresie pozycji wcześniej) / spadek w ≥ 2 metrykach lub ≥ 2 frazach /
podział wyświetleń także w poprzednim okresie albo zmiana lidera w segmentach. 0–1 = Niska, 2–3 = Średnia, 4 = Wysoka.
Bez pozorowania pewności statystycznej. **Nakład pracy (effort) celowo pominięty** — z samych danych GSC byłaby to fałszywa precyzja.

### 10.6 Grupowanie, deduplikacja i odcisk

- Fraza jest przypisana do **strony docelowej**: adres (bez `#fragmentu`) z największą liczbą kliknięć, potem wyświetleń
  w bieżącym okresie; dla utraconych fraz — w poprzednim. Bez danych `query_page` grupą jest sama fraza.
- Szansa = **typ × podstrona** (15 fraz blisko TOP 10 jednej podstrony = jedna szansa z 15 frazami w dowodach); kanibalizacja =
  **typ × para dwóch głównych adresów** (wiele fraz tej samej pary = jedna szansa). Jedna podstrona może mieć kilka szans różnych
  typów (różne działania). Dowody: do 25 najważniejszych fraz/zapytań (`evidence_keywords`), wyszukiwanie obejmuje wszystkie.
- Limit 300 szans na typ i okres (najwyższy priorytet) — lista zadań, nie zrzut fraz.
- **Odcisk** = MD5(property, typ, klucz encji: adres bez fragmentu / dokładna fraza z GSC / posortowana para adresów) —
  z tekstów GSC, nie z ID słowników, więc przetrwa ponowny import; property w odcisku: szanse różnych properties się nie łączą.
- Widok „wg podstron”: podstrona → typy szans → dowody (grupowanie w SQL po `page_hash`, paginacja po podstronach).

### 10.7 Praca nad szansą

| Status | UI |
|---|---|
| `new` | Nowa (ustawiany przy pierwszym wykryciu) |
| `review` | Do analizy |
| `planned` | Zaplanowana |
| `in_progress` | W trakcie |
| `completed` | Zrealizowana (z datą wdrożenia, domyślnie dziś, nie w przyszłości) |
| `dismissed` | Odrzucona |

- Zmiany wymagają `osf_seo_manage_opportunities` (administrator, `osf_seo_admin`); klient widzi szanse tylko do odczytu.
  Notatka do 2000 znaków. Zapis: kto i kiedy zmienił status. Domyślny filtr listy: statusy otwarte (Nowa … W trakcie).
- **Stan wykrycia** (ustawia analiza): `active` (wykryta w co najmniej jednym okresie), `inactive` (sygnał nie spełnia już
  kryteriów — szansa nie jest usuwana; zostaje status, notatka i ostatni snapshot dowodów; wraca do `active`, gdy sygnał wróci),
  `archived` (dane poprzedniej property, 10.8). Status pracy przetrwa każdą analizę (także `completed`/`dismissed` przy ponownym wykryciu).
- **Baseline i obserwacja po wdrożeniu**: przy przejściu na „Zrealizowana” (i zmianie daty wdrożenia) zapisujemy frazy z dowodów
  i ich sumy (`gsc_query_daily`) w okresie tej samej długości **przed** datą wdrożenia. Gdy dane obejmują cały okres **po**
  wdrożeniu, szczegóły pokazują „Po wdrożeniu kliknięcia tych fraz zmieniły się o X względem okresu bazowego” z zastrzeżeniem,
  że to obserwacja, nie dowód przyczynowości. Ponowne otwarcie szansy czyści datę wdrożenia i baseline.

### 10.8 Przeliczanie, okresy i reset property

- **Okresy** 7/28/90 dni (domyślnie 28) do **ostatniej kompletnej daty** = min(ostatnia data sum witryny, fraz, fraz × podstron) —
  nie „dziś”; porównanie z poprzednim okresem tej samej długości. Okres jest analizowany tylko, gdy bieżący okres jest w pełni
  zaimportowany (frazy i frazy × podstrony) — inaczej „pominięto” (np. w trakcie backfillu). Poprzedni okres niepełny → bez spadków,
  niższa pewność.
- **Automatycznie** (D21): osobny krok po przebiegu kolejki synchronizacji (`SyncScheduler::onAfterRun` — WP-Cron
  `osf_seo_sync_tick` i `wp osf-seo sync:run`), **nie** część importera — błąd lub czas analizy nie wpływa na import.
  Co 5 min sprawdza projekty z property i danymi, maks. 3 analizy na uruchomienie (budżet czasu kolejki); pomija projekt,
  gdy czeka odświeżanie najnowszych danych; analizuje tylko przy zmianie **klucza danych** (wersja analizy, progi, property,
  ostatnia data, pokrycie zakresu obu okresów — backfill poza zakresem nie wywołuje analizy).
- **Ręcznie**: „Przelicz szanse” (POST, nonce + Origin, `osf_seo_manage_opportunities`, limit 1 / 60 s na projekt) oraz
  `wp osf-seo opportunities:analyze --project=<id> [--days=7|28|90] [--force]`.
- Idempotentnie i bezpiecznie przy ponowieniu: blokada `GET_LOCK` per projekt, zapis wykryć okresu w jednej transakcji
  pod blokadą wiersza projektu z kontrolą property (jak import GSC); upsert po odcisku — bez duplikatów.
- **Reset property** (D18 → D22): w transakcji resetu (blokada wiersza projektu) wykrycia i stan analiz projektu są usuwane,
  a szanse oznaczane jako `archived` — z historią i stanem pracy, poza listą aktywnych rekomendacji (filtr „Archiwalne”).
  Nowa property ma inne odciski, więc nowe dane tworzą nowe szanse; analiza starej property przerwana w trakcie nie zapisze
  wyników (kontrola property). Tabele szans nie należą do `GscDataStore::DATA_TABLES` — reset danych GSC ich nie kasuje.
  Odłączenie konta Google bez zmiany property: szanse zostają, ale nie są aktualizowane (komunikat w UI).

### 10.9 Progi i konfiguracja

Wszystkie progi w `OpportunityConfig` (bez progów w SQL). Każdy można nadpisać stałą w `wp-config.php` lub zmienną środowiskową
`OSF_SEO_OPP_<NAZWA>` (np. `OSF_SEO_OPP_LOW_CTR_MIN_IMPRESSIONS=150`); wartości spoza zakresu są przycinane, a zmiana progów
zmienia klucz danych (ponowna analiza). Progi ilościowe dla 28 dni → `max(dolna granica, round(wartość × dni / 28))`.

| Próg (28 dni) | Wartość | Dolna granica |
|---|---:|---:|
| `keyword_min_impressions` / `pair_min_impressions` (wczytanie fraz / par) | 10 / 5 | 3 / 1 |
| `reference_min_impressions`, `reference_min_keywords` (referencyjny CTR) | 30, 8 fraz | 10 |
| `low_ctr_min_impressions`, `low_ctr_min_click_gap`, `low_ctr_max_ratio`, `low_ctr_max_position` | 100, 5, 0,6, 20 | 30, 2 |
| `near_top_min_impressions` | 50 | 15 |
| `weak_position_min_impressions` | 200 | 50 |
| `decline_min_previous_impressions`, `decline_min_previous_clicks`, `decline_min_click_loss`, `decline_min_impression_loss` | 100, 10, 5, 100 | 30, 4, 3, 30 |
| `decline_min_relative`, `decline_min_position_drop`, `decline_relative_position_drop` | 30%, 2, 20% | — |
| `decline_min_current_impressions_for_position` | 50 | 15 |
| `decline_page_min_previous_clicks`, `decline_page_min_click_loss`, `decline_page_min_impression_loss` | 20, 10, 300 | 6, 4, 60 |
| `cannibalization_min_impressions`, `cannibalization_min_url_impressions`, `cannibalization_min_share`, `cannibalization_top_position` | 100, 20, 15%, 3 | 30, 6 |
| `confidence_medium_impressions`, `confidence_high_impressions` | 300, 1000 | 80, 250 |
| `demand_cap`, `clicks_cap` (priorytet) | 11 200, 280 | 2800, 70 |
| `max_groups_per_type`, `evidence_keywords` | 300, 25 | — |

### 10.10 Ograniczenia

- Dane GSC: zapytania zanonimizowane i limit wierszy — sumy fraz i podstron są mniejsze niż sumy projektu; podstrony wyłącznie
  z `query_page` (agregacja Google per strona). Szanse nie obejmują podstron bez widocznych fraz.
- Średnia pozycja (GSC) to średnia z wyświetleń — wyniki rozszerzone, personalizacja i lokalizacja wpływają na nią i na CTR.
  Referencyjny CTR jest przybliżeniem; przyczyny niskiego CTR, spadku czy podziału adresów trzeba sprawdzić ręcznie.
- Kanibalizacja nie rozróżnia intencji — wiele adresów bywa poprawne. Sitelinki rozpoznajemy tylko heurystycznie (wszystkie adresy ≤ 3).
- Obserwacja po wdrożeniu porównuje frazy z dowodów (do 25) w równych okresach przed i po dacie wdrożenia — bez kontroli
  sezonowości i bez atrybucji przyczynowej; frazy dłuższe niż 500 znaków (skracane w słowniku) mogą nie zostać odnalezione.
- Brak nakładu pracy (effort), brak powiadomień, brak przypisywania szans do osób — możliwe kolejne etapy.

### 10.11 Wydajność (pomiar)

`composer test:performance` (MariaDB 10.11 w kontenerze deweloperskim, bez strojenia, mediana 3 uruchomień): 2,4 mln wierszy
`query_daily` (20 tys. fraz, ~5 tys. dziennie, 480 dni) i 270 tys. `query_page_daily` (90 dni) projektu + dane innego projektu
w tych samych tabelach (1 mln wierszy faktów, 200 tys. fraz i 20 tys. adresów w słownikach, 30 tys. szans / 90 tys. wykryć).

| Operacja | Czas | Uwagi |
|---|---:|---|
| Analiza 28 dni (agregaty + wykrywanie + zapis 603 szans) | ~0,9–1,2 s | pamięć PHP analizy ~50 MB |
| Analiza 7 dni | ~0,5–0,6 s | |
| Analiza 90 dni | ~1,7–1,9 s | |
| Lista szans (strona 1 / z wyszukiwaniem i typem / wg podstron) | 3–6 ms | dane z tabel szans, bez agregacji GSC |

`EXPLAIN`: agregat fraz — `range` na `PRIMARY (project_id, date)` + słownik `eq_ref` po PRIMARY (`STRAIGHT_JOIN`; bez niego planista
skanował cały słownik wszystkich projektów); pary fraza × podstrona i sumy podstron — `range` na `PRIMARY` `query_page_daily`;
segmenty kandydatów — `range` na `project_keyword_date_page`; adresy i teksty fraz — `range` na `PRIMARY` (listy IN po 500, bo MariaDB
zamienia listy ≥ 1000 na podzapytanie ze skanem całej tabeli); lista szans — `ref` na `project_period_priority` + `eq_ref`; członkowie
grup podstron — `range` na `project_page` + `eq_ref`. Analiza działa w tle (raz na zmianę danych), więc strony panelu nie wykonują
agregacji historii. Nowych indeksów na tabelach GSC nie było potrzeba.

## 11. Dane rynkowe (DataForSEO, STEP 12)

Zaimplementowane w STEP 12 (`plugins/osf-seo/src/Market`, `src/DataForSeo`; UI: `MarketDataController`, kolumny w `KeywordsController`,
kontekst w szczegółach szansy, karta w Ustawieniach; CLI `wp osf-seo dataforseo:*`).

### 11.1 Zasady

- **GSC pozostaje źródłem prawdy** o skuteczności strony: kliknięcia, wyświetlenia, CTR, średnia pozycja (GSC), strony docelowe, historia.
  DataForSEO jest **dodatkowym** źródłem danych rynkowych o frazie: wolumen (średnia miesięczna), historia wolumenu, CPC, konkurencja Ads,
  trudność SEO. Dane rynkowe nigdy nie zastępują ani nie zmieniają metryk GSC.
- **Rozdzielone pojęcia** (etykiety w UI): „Średnia pozycja (GSC)” ≠ dokładna pozycja SERP; „Trudność SEO” (Keyword Difficulty, szacunek
  trudności wejścia do organicznego TOP 10) ≠ „Konkurencja Ads” (konkurencja reklamodawców w płatnych wynikach Google Ads); „CPC” w USD.
- **Brak danych = NULL**, w UI „—” (z podpowiedzią: brak danych u dostawcy / jeszcze nie pobrano) — nigdy 0. Prawdziwe 0 od dostawcy jest pokazywane jako 0.
- **Abstrakcja (D23)**: aplikacja zależy od `KeywordMetricsProvider` (rynek, reguły fraz, zlecenie i odbiór wolumenu, trudność, szacunek kosztu,
  opis endpointów). `DataForSeoProvider` + `DataForSeoClient` to jedyna implementacja. Płatne metody wywołuje wyłącznie `MarketSyncService`
  — nigdy kontroler, widok, raport, analiza szans ani importer GSC. Wyszukiwanie nowych fraz (STEP 13) ma osobny interfejs i wysyła płatne
  żądania wyłącznie z `DiscoveryRunner`, w ramach tych samych limitów i blokady (sekcja 12, D30).
- **Awaria DataForSEO nie psuje Wibble**: synchronizacja GSC, dashboard, frazy i szanse działają bez danych rynkowych i bez dostawcy;
  krok w tle jest osobny (D26), a odczyt danych rynkowych w szansach ma własną obsługę błędu.

### 11.2 Endpointy, tryby i ceny

Weryfikacja: oficjalna dokumentacja i cennik DataForSEO (docs.dataforseo.com, dataforseo.com) — w środowisku implementacji domeny te były
zablokowane przez politykę sieci, więc dane potwierdzono cytatami oficjalnych stron w wynikach wyszukiwania (październik 2026).
**Przed pierwszym płatnym użyciem potwierdź ceny w panelu DataForSEO**; ceny w kodzie służą tylko do szacunku (stałe `OSF_SEO_DATAFORSEO_PRICE_*`),
limity liczą koszt zgłoszony przez API.

| Metryki | Endpoint (`https://api.dataforseo.com/v3/…`) | Tryb | Limit | Cena |
|---|---|---|---|---|
| wolumen, historia 12 mies. (`monthly_searches`), CPC, konkurencja Ads (`competition` LOW/MEDIUM/HIGH, `competition_index` 0–100), stawki top of page | `keywords_data/google_ads/search_volume/task_post` → `…/task_get/{id}` | **Standard** (wynik w 1–3 h, dostępny 30 dni; `40601/40602` = jeszcze w kolejce) | do 1000 fraz w zadaniu (maks. 80 znaków i 10 słów na frazę); do 100 zadań w POST; 2000 wywołań/min | **0,06 USD za zadanie** (Live: 0,09 USD i 12 żądań/min) |
| trudność SEO (`keyword_difficulty` 0–100) | `dataforseo_labs/google/bulk_keyword_difficulty/live` | **Live** (Labs nie ma Standard) | do 1000 fraz w żądaniu | **0,012 USD za żądanie + 0,00012 USD za zwrócony element** |
| weryfikacja rynków | `dataforseo_labs/locations_and_languages` (GET) | Live | — | bezpłatny |

- Dlaczego tak: wolumen nie jest potrzebny w czasie rzeczywistym — Standard jest o ⅓ tańszy i nie ma limitu 12 żądań/min. Alternatywa
  `dataforseo_labs/google/keyword_overview/live` (wszystkie metryki w jednym żądaniu, taniej przy pełnym pokryciu) zwraca dane tylko dla fraz
  z bazy DataForSEO — długi ogon fraz z GSC zostałby bez wolumenu, dlatego wolumen pochodzi z Google Ads.
- Uwierzytelnienie: Basic Auth (`Authorization: Basic base64(login:hasło API)`) budowane w chwili żądania z `OSF_SEO_DATAFORSEO_LOGIN`
  i `OSF_SEO_DATAFORSEO_PASSWORD` (hasło API z zakładki „API Access”, nie hasło do konta).
- Odpowiedź: koperta z `status_code` (20000 = OK), `cost` i `tasks[]` (każde zadanie z własnym `status_code`, `cost`, `result`).
- Paczka: jedno zadanie na żądanie, do 1000 fraz; frazy niespełniające reguł Google Ads (`KeywordRules`: > 80 znaków, > 10 słów,
  `` , ! @ % ^ ( ) = { } ; ~ ` < > ? \ | ― ``, `*`, `"`, `[ ]`, znaki 4-bajtowe, znaki sterujące, operatory `site:` itd.) są pomijane przed wysłaniem —
  jedna niedozwolona fraza potrafi odrzucić całą paczkę.

### 11.3 Rynek projektu

- Rynek = kraj i język z ustawień projektu (`projects.country`, `projects.language`, kody ISO) → katalog `DataForSeoMarkets` →
  identyfikatory dostawcy: Polska/polski → `location_code` 2616, `language_code` `pl`; Niemcy/niemiecki → 2276/`de`;
  Wielka Brytania/angielski → 2826/`en`; USA/angielski → 2840/`en` (identyfikatory lokalizacji Google Ads, których używa DataForSEO).
  Kody potwierdza bezpłatne `wp osf-seo dataforseo:locations --country=PL`. Rynek spoza katalogu nie jest zgadywany (brak danych rynkowych).
- Nowy rynek = nowy wiersz katalogu, bez zmiany schematu (klucz danych zawiera lokalizację i język). Projekt OhSoFresh: `pl`/`pl` (domyślne
  wartości projektu) → Polska/polski.

### 11.4 Model danych i normalizacja (D24)

- `market_keywords` — jedna fraza na rynku: dostawca × `location_code` × `language_code` × `keyword_key` (sekcja 6.2). Wolumen i trudność
  mają osobne `*_fetched_at` i `*_stale_after` (TTL), wolumen dodatkowo `volume_pending_until` (zlecone zadanie Standard — nie zlecamy ponownie).
  `*_fetched_at` ≠ NULL przy wartości NULL = „dostawca nie ma danych” (z tym samym TTL — nie płacimy co przebieg za frazy bez danych).
- Historia: `market_keyword_monthly` (miesiąc → wolumen); odświeżenie nadpisuje nakładające się miesiące, starsze zostają (seria rośnie
  w czasie, bez duplikatów) — pod sezonowość i trend w kolejnych etapach.
- **Normalizacja rynkowa** (`MarketKeyword`) — osobna od tożsamości GSC (`keyword_hash` = dokładne bajty, bez zmian):
  Unicode NFC (gdy dostępne `intl`), małe litery UTF-8 (`Ł` → `ł`), białe znaki (także twarda spacja i znaki zerowej szerokości) → jedna spacja,
  bez spacji na krańcach. Polskie znaki i interpunkcja zostają (`żółw` ≠ `zolw`). Klucz = MD5 (binarnie) postaci znormalizowanej.
  Warianty GSC różniące się wielkością liter lub spacjami (`Buty` / `buty`) to jedna fraza rynkowa (jeden płatny odczyt).
  Frazy liczbowe (`2024`) są zawsze tekstem — PHP zamienia takie klucze tablic na int (poprawka błędu w STEP 13, test regresji).
- **Mapowanie wyników** (`ResultMapper`): fraza z odpowiedzi → wysłana fraza po postaci znormalizowanej, pomocniczo po postaci „luźnej”
  (bez interpunkcji) tylko gdy jednoznaczna; niedopasowane wyniki są pomijane (liczone), wysłana fraza bez wyniku = brak danych.
- `keywords.market_key` wylicza `MarketKeyBackfill` w tle (co 5 min, paczkami) i przed planem synchronizacji — importer GSC jest bez zmian.
  Reset property usuwa słownik GSC (D18), ale nie `market_*`; po ponownym imporcie klucze są wyliczane od nowa, a metryki wracają bez płatnych żądań.
- `market_tasks` — rejestr zadań i kosztów (podstawa limitów); `market_sync_state` — jawne włączenie i stan per projekt.

### 11.5 Wybór fraz

`MarketCandidateSelector` (jedno zapytanie: zakres PK `gsc_query_daily` → słownik po PRIMARY → `market_keywords` po UNIQUE):
frazy projektu z ≥ `OSF_SEO_DATAFORSEO_MIN_IMPRESSIONS` (50) wyświetleniami w ostatnich `OSF_SEO_DATAFORSEO_WINDOW_DAYS` (90) dniach danych
(do ostatniej zaimportowanej daty), malejąco po wyświetleniach i kliknięciach; tylko bez metryk lub z metrykami po TTL; bez wolumenu oczekującego
na wynik; z pominięciem fraz odrzucanych przez reguły dostawcy; warianty scalone. Limit fraz na synchronizację `OSF_SEO_DATAFORSEO_SYNC_LIMIT`
(1000) albo `--limit`. Frazy szans SEO spełniają tę regułę (progi szans to ≥ 50–200 wyświetleń w 28 dni), więc nie są wybierane osobno.
Pierwszy przebieg: zacznij od małej paczki (`--limit=10`, sekcja 11.12), sprawdź koszt i wyniki, potem zwiększaj.

### 11.6 Bezpieczniki kosztów (D27)

1. **Plan bez API** — `SyncPlan` (frazy, zadania per endpoint, szacowany koszt, ocena limitów): `dataforseo:sync --dry-run` i podgląd w panelu.
   Ręczna synchronizacja wysyła tylko plan nie większy niż potwierdzony podgląd (`expected_keywords`).
2. **Batching** — maks. 1000 fraz na zadanie; wolumen i trudność przeplatane, więc przy limicie zadań najważniejsze frazy dostają obie metryki.
3. **TTL** — wolumen i trudność ważne `OSF_SEO_DATAFORSEO_VOLUME_TTL_DAYS` / `…_DIFFICULTY_TTL_DAYS` (30 dni); historia odświeżana z wolumenem.
   Świeże metryki nie są pobierane ponownie (także po `--force` wolumen oczekujący na wynik nie jest zlecany drugi raz).
4. **Twardy limit zadań na przebieg** — `OSF_SEO_DATAFORSEO_MAX_TASKS_PER_RUN` (4).
5. **Lokalne limity kosztów** — `OSF_SEO_DATAFORSEO_DAILY_COST_LIMIT` (1 USD / doba UTC) i `…_MONTHLY_COST_LIMIT` (10 USD / miesiąc UTC):
   suma `COALESCE(cost zgłoszony, estimated_cost)` z `market_tasks`; sprawdzane **przed każdym** płatnym zadaniem — zadanie, które by przekroczyło
   limit, nie jest wysyłane, synchronizacja się zatrzymuje. Błędy, przy których dostawca na pewno nic nie wykonał (logowanie, środki, limit,
   nieprawidłowe żądanie), mają koszt 0; niepewne (sieć, 5xx) — koszt szacowany (ostrożnie). To bezpiecznik, nie rozliczenie.
6. **Bez automatycznego startu po wdrożeniu** — tło odświeża tylko projekty po pierwszej jawnej synchronizacji (`market_sync_state.enabled_at`);
   `OSF_SEO_DATAFORSEO_AUTO_REFRESH=0` wyłącza automatykę.
7. **Jedna synchronizacja naraz** (GET_LOCK), ręczna najwyżej raz na 5 minut na projekt, przycisk blokowany po kliknięciu.
8. **Wstrzymanie po błędzie konta** — logowanie lub środki: automatyka wstrzymana na 24 h (`osf_seo_market_pause`); błąd przerywa przebieg.

Szacunek: 1000 fraz bez metryk ≈ 0,06 USD (wolumen) + 0,132 USD (trudność, gdy wszystkie mają wynik) ≈ 0,19 USD.

### 11.7 Cykl zadań, błędy i ponowienia

- **Wolumen (Standard)**: plan → kontrola limitów → wiersz `market_tasks` → `task_post` (koszt z odpowiedzi) → frazy `volume_pending_until`
  = teraz + 48 h → odbiór w tle `task_get/{id}` (bezpłatny) po 5, 10, 20, 30, 60 min… → zapis metryk i historii → `completed`.
  Brak wyniku po 48 h → `expired`, frazy zwolnione. Błąd zadania (np. 404xx) → `failed`, frazy zwolnione.
- **Trudność (Live)**: kontrola limitów → wywołanie → zapis od razu.
- **Klasyfikacja** (`DataForSeoStatus`): 40100/40104/40201/40204 i HTTP 401/403 — logowanie; 40200/40210/40203 i HTTP 402 — środki / limit
  kosztów u dostawcy; 40202/40209 i HTTP 429 — limit żądań; 40501 i inne 4xxxx — nieprawidłowe żądanie; 404xx w zadaniu — błąd zadania;
  50000+, 40101, HTTP 5xx — przejściowy; brak odpowiedzi — sieć; zła koperta — nieoczekiwana odpowiedź.
- **Ponowienia w żądaniu** (maks. 2: 1 s, 3 s; `Retry-After` do 10 s): GET — błędy przejściowe, sieć, limit; **płatny POST — tylko po jawnym
  limicie żądań** (dostawca nic nie wykonał); timeout/5xx przy POST nie jest ponawiany (zadanie mogło zostać opłacone). Błędy trwałe nigdy w pętli.
- Logi i wyjątki: kategoria, kod statusu, ścieżka endpointu — bez nagłówków, danych logowania i treści żądań.

### 11.8 Synchronizacja ręczna i w tle (D26)

- **Ręcznie**: panel → Dane rynkowe → „Synchronizuj dane fraz” (podgląd planu i kosztu, potwierdzenie, `osf_seo_manage_market_data`,
  nonce, Origin) albo `wp osf-seo dataforseo:sync --project=<id>`. Pierwsza jawna synchronizacja włącza automatykę projektu.
- **W tle**: `MarketSyncService::runBackground` jako krok po przebiegu kolejki GSC (`SyncScheduler::onAfterRun` — WP-Cron i `wp osf-seo sync:run`),
  co 5 minut: klucze rynkowe fraz (bezpłatnie), odbiór wyników zadań Standard (maks. 10), a dla włączonych projektów, których termin minął
  (co 24 h; przy limicie zadań — za 1 h) — synchronizacja nieaktualnych i nowych fraz w ramach limitów (maks. 3 projekty).
  Nie korzysta z `sync_runs` (D26) i nie wpływa na import GSC; błąd jest logowany.

### 11.9 UI i szanse SEO (D28)

- **Frazy**: kolumny „Wolumen” i „Trudność SEO” (sortowanie; filtry „Min. wolumen”, „Maks. trudność SEO” — wymagają znanej wartości),
  rozwijane „Dane rynkowe” w komórce frazy: CPC, Konkurencja Ads (poziom i indeks), historia 12 miesięcy, rynek, data aktualizacji.
- **Dane rynkowe** projektu: stan DataForSEO (bez sekretów), rynek i język, liczba fraz z danymi, oczekujące zadania, automatyka, ostatni błąd;
  dla `osf_seo_manage_market_data`: koszty i limity, podgląd planu i przycisk, ostatnie zadania. Klient widzi tylko stan.
- **Ustawienia**: konfiguracja (nazwy brakujących stałych), limity, zużycie, TTL, reguła wyboru.
- **Szanse SEO**: w szczegółach szansy wolumen i trudność SEO fraz z dowodów jako kontekst; wykrywanie, priorytet, pewność i klucz danych
  analizy bez zmian — szanse działają bez danych rynkowych i przy awarii ich odczytu.

### 11.10 CLI

```bash
wp osf-seo dataforseo:status [--project=<id>] [--format=json]        # konfiguracja (bez sekretów), rynek, metryki, zadania, koszty, limity
wp osf-seo dataforseo:sync --project=<id> --dry-run [--limit=10]     # plan bez żadnego żądania
wp osf-seo dataforseo:sync --project=<id> [--limit=<n>] [--force] [--wait=<s>]   # płatna synchronizacja w ramach limitów
wp osf-seo dataforseo:keyword --project=<id> --keyword="<fraza>"     # zapisane metryki frazy (bez API)
wp osf-seo dataforseo:run [--collect-only]                           # krok w tle raz (odbiór wyników; --collect-only bez nowych zadań)
wp osf-seo dataforseo:locations [--country=PL]                       # bezpłatna lista lokalizacji i języków (weryfikacja kodów)
```

### 11.11 Wydajność

Benchmark `composer test:performance` (MariaDB 10.11, 2,4 mln wierszy `query_daily` projektu + 1 mln szumu, 20 000 fraz;
dane rynkowe: 214 000 metryk — PL dla 70% fraz projektu i 200 000 fraz rynku DE — oraz 60 000 wierszy historii):

| Przypadek | Czas (mediana) |
|---|---:|
| Frazy 28 dni — bez danych rynkowych / z danymi rynkowymi (A/B, mediana z 7) | 314 / 339 ms |
| Frazy 90 dni, sortowanie po zmianie pozycji — bez / z danymi rynkowymi (A/B) | 775 / 865 ms |
| Frazy 28 dni, strona 50 — bez / z danymi rynkowymi (A/B) | 289 / 308 ms |
| Frazy 28 dni, sortowanie po wolumenie | 350–530 ms |
| Frazy 90 dni, sortowanie po trudności SEO | ~820–870 ms |
| Frazy: filtr min. wolumen 1000 + maks. trudność SEO 30 | 305–352 ms |
| Plan synchronizacji (wybór kandydatów, bez API) | 256–270 ms |
| Odczyt 25 fraz po kluczach (dowody szansy) | 1 ms |
| Analiza szans 28 / 90 dni (bez zmian względem STEP 11) | ~0,9–1,1 s / ~1,7–1,9 s |
| Wyliczenie kluczy rynkowych 220 000 fraz (`MarketKeyBackfill`, partie po 2000) | 10,8 s |

- Domyślna lista fraz (sortowanie po metrykach GSC, bez filtrów rynkowych) nie dołącza `market_keywords` w zapytaniu głównym:
  metryki i historia dla bieżącej strony to dwa dodatkowe zapytania (0,7–0,9 ms każde; `k` range PRIMARY ≤ 50 wierszy +
  `m` eq_ref `market_keyword`; historia: range PRIMARY). Różnice A/B mieszczą się w rozrzucie pomiarów (ten sam przypadek
  bez danych rynkowych: 784–891 ms między uruchomieniami).
- Sortowanie i filtrowanie po wolumenie / trudności SEO dołącza dane rynkowe w zapytaniu głównym z `STRAIGHT_JOIN`
  (agregat → `k` eq_ref PRIMARY → `m` eq_ref `market_keyword`), żeby planer nie zaczynał od zakresu tabeli `keywords`
  (bez tego +26% czasu domyślnej listy).
- Wybór kandydatów: jedno zapytanie (agregat → `k` eq_ref PRIMARY → `m` eq_ref); klucze bez wartości: ref `project_market_key`;
  suma kosztów do limitów: range `created`.

### 11.12 Bezpieczny smoke test na stagingu (5–10 fraz)

Wykonuje osoba z dostępem do stagingu — nie agent. Zakłada wdrożony kod STEP 12, schemat 6 i stałe DataForSEO w `wp-config.php`.

1. `wp osf-seo status` (schemat 6, `dataforseo: configured`), `wp osf-seo dataforseo:status --project=<id>` (rynek Polska, 0 zadań, limity).
2. `wp osf-seo dataforseo:locations --country=PL` — bezpłatnie potwierdza dane logowania i kody (Poland → 2616, Polish → `pl`).
3. `wp osf-seo dataforseo:sync --project=<id> --limit=10 --dry-run` — plan: ≤ 10 fraz, 1 zadanie wolumenu + 1 żądanie trudności, szacunek
   ≤ 0,0732 USD (0,06 + 0,012 + 10 × 0,00012); zero żądań.
4. (Opcjonalnie dodatkowa asekuracja) `define('OSF_SEO_DATAFORSEO_MAX_TASKS_PER_RUN', 2);` i limit kosztów konta w panelu DataForSEO.
5. `wp osf-seo dataforseo:sync --project=<id> --limit=10` — wysyła dokładnie plan z kroku 3; trudność zapisana od razu, wolumen czeka na wynik.
6. Po 1–3 h: `wp osf-seo dataforseo:run --collect-only` (albo automatycznie w tle), potem `dataforseo:keyword --keyword="…"` dla 2–3 fraz,
   lista fraz w panelu (kolumny, „—” dla braku danych) i `dataforseo:status` (koszt zgłoszony przez API ≈ szacunek).
7. Porównać koszt w `dataforseo:status` z panelem DataForSEO; dopiero potem zwiększać `--limit`.

### 11.13 Ograniczenia

- Ceny i limity zweryfikowane pośrednio (11.2); lokalne limity nie są rozliczeniem.
- Rynki: katalog 4 rynków (PL, DE, GB, US); inne wymagają dopisania kodów potwierdzonych `dataforseo:locations`.
- Normalizacja bez `intl` nie łączy rozłożonych znaków diakrytycznych (GSC zwraca zwykle NFC).
- Frazy odrzucone przez reguły Google Ads (np. z „?”) nie mają danych rynkowych; nowe frazy GSC dostają klucz rynkowy w ciągu ~5 min.
- Odbiór wyników Standard zależy od crona (bez ruchu i crona systemowego wyniki czekają; do 30 dni u dostawcy, 48 h w Wibble — potem ponowne zlecenie).
- Brak wpływu na priorytet szans, monitoringu konkurencji i dokładnego rankingu SERP — kolejne etapy (odkrywanie nowych fraz: STEP 13, sekcja 12).

## 12. Nowe frazy (STEP 13)

Zaimplementowane w STEP 13 (`plugins/osf-seo/src/Discovery`, `src/DataForSeo/DataForSeoDiscoveryProvider.php`; UI: `DiscoveryController`,
moduł „Nowe frazy”; CLI `wp osf-seo discovery:*`). Cel: znaleźć frazy, które mogą być wartościowe dla projektu, a na które strona
jeszcze nie ma widoczności (albo ma słabą). Przepływ: **seedy → wyszukiwanie (płatne) → metryki rynkowe (z tej samej odpowiedzi) →
deduplikacja → widoczność w GSC → trafność i priorytet → decyzja (praca nad frazą)**.

### 12.1 Zasady

- **Bez AI i bez zgadywania** — wszystkie reguły (seedy, deduplikacja, widoczność, priorytet, strona docelowa) są deterministyczne
  i opisane w tej sekcji. Nic nie jest zakodowane pod konkretny projekt (brak wbudowanych seedów i wykluczeń).
- **Płatne żądania wyłącznie z `DiscoveryRunner`** (krok w tle albo `wp osf-seo discovery:run`), pod wspólną blokadą i wspólnymi limitami
  kosztów danych rynkowych (D30) — nigdy z kontrolera, widoku, raportu, analizy szans ani importera GSC. Panel tylko kolejkuje przebieg
  po potwierdzeniu planu; wdrożenie niczego nie uruchamia.
- **Abstrakcja (D29)**: aplikacja zależy od `KeywordDiscoveryProvider` (rynek, reguły fraz, metody, endpointy, limity, szacunek kosztu,
  wyszukiwanie); `DataForSeoDiscoveryProvider` (na `DataForSeoClient` ze STEP 12) jest jedyną implementacją.
- **Priorytet odkrycia** to sygnał „warto sprawdzić”, nie wartość biznesowa ani prognoza ruchu (12.7).
- **GSC bez zmian**: wyszukiwanie tylko czyta dane GSC (widoczność, strona docelowa, podpowiedzi seedów). Reset property nie usuwa
  kandydatów ani decyzji (12.6). **Szanse SEO bez zmian**: wykrywanie, priorytet i pewność szans nie korzystają z nowych fraz.
- Poza zakresem: monitoring konkurencji, harmonogram śledzenia SERP, Keyword Gap, pełna strategia i backlog, linki, AI.

### 12.2 Endpointy, ceny i wybór (D29)

Weryfikacja jak w 11.2: docs.dataforseo.com był zablokowany przez politykę sieci środowiska implementacji — parametry i pola potwierdzono
w oficjalnym kliencie DataForSEO (repozytorium GitHub z dokumentacją endpointów), ceny cytatami oficjalnego cennika w wynikach wyszukiwania
(październik 2026). **Przed pierwszym płatnym użyciem potwierdź ceny w panelu DataForSEO.**

| Endpoint (`https://api.dataforseo.com/v3/…`) | Wejście | Wynik | Limit / paginacja | Cena | Decyzja |
|---|---|---|---|---|---|
| `dataforseo_labs/google/related_keywords/live` | 1 seed, `depth` 0–4 | frazy z „Podobne wyszukiwania” Google w głąb od seeda (głębokość 1/2/3/4 → maks. ok. 8/72/584/4680 fraz); każda z `keyword_data` i `depth` | `limit` ≤ 1000, `offset`, `filters` (do 8 warunków), `order_by` (do 3) | 0,012 USD za żądanie + 0,00012 USD za zwrócony element | **wybrany** — frazy tematycznie bliskie seedowi; głębokość = kontrola liczby wyników i kosztu; relacja z seedem (głębokość) |
| `dataforseo_labs/google/keyword_suggestions/live` | 1 seed | frazy zawierające seed (długi ogon) | `limit` ≤ 1000, `offset` (i `offset_token`), `exact_match`, filtry | jak wyżej | **wybrany** — długi ogon frazy seeda, paginacja |
| `dataforseo_labs/google/keyword_ideas/live` | do 200 seedów | frazy z tych samych kategorii produktowych | `limit` ≤ 1000, `offset` | jak wyżej | pominięty — wynik nieprzypisany do seeda (nie da się zachować „z którego seeda”), szerszy i mniej trafny |
| Labs `keywords_for_site`, `ranked_keywords` | domena | frazy domeny / pozycje SERP domeny | — | jak wyżej | pominięte — analiza domen (konkurencji) i SERP poza zakresem STEP 13 |
| `keywords_data/google_ads/keywords_for_keywords` | do 20 seedów | pomysły Google Ads | Standard / Live | 0,06 / 0,09 USD za zadanie | pominięty — bez trudności SEO i intencji (wymagałby płatnego wzbogacenia każdej frazy), bez relacji z seedem |

- Oba wybrane endpointy działają tylko w trybie **Live** (Labs nie ma Standard), jeden seed na żądanie. Element wyniku (`keyword_data`
  albo element listy) zawiera wszystko, czego potrzebuje kandydat: `keyword_info` (wolumen, `monthly_searches`, CPC, `competition` 0–1,
  `competition_level`, stawki), `keyword_properties` (`keyword_difficulty`, `core_keyword`, `is_another_language`, `detected_language`)
  i `search_intent_info.main_intent` — **bez osobnych płatnych żądań wzbogacających** (D32).
- Wysyłane parametry: `keyword`, `location_code`/`language_code` rynku projektu (11.3), `limit`, `offset`, `include_seed_keyword` tylko
  na pierwszej stronie (dane samego seeda), `include_serp_info: false` (SERP to kolejny etap), `ignore_synonyms: false`, sortowanie po wolumenie
  malejąco, filtry dostawcy `search_volume >= min` i opcjonalnie `keyword_difficulty <= max` (mniej zwróconych elementów = niższy koszt);
  Related: `depth`; Suggestions: `exact_match: false`. **`include_clickstream_data` nie jest wysyłane nigdy** (podwójna cena).
- Szacunek kosztu żądania = `0,012 + (limit + 1) × 0,00012` USD (limit elementów + dane seeda) — górna granica; limity kosztów liczą koszt
  zgłoszony przez API (`cost`), a bez niego szacunek. Ceny w stałych `OSF_SEO_DATAFORSEO_PRICE_DISCOVERY_REQUEST` / `…_ITEM`.
- Mapowanie jak w STEP 12: `competition` 0–1 → `competition_index` 0–100, poziom LOW/MEDIUM/HIGH, CPC w USD, `monthly_searches` → historia.
- **Intencja** = `search_intent_info.main_intent` (informacyjna, nawigacyjna, komercyjna, transakcyjna) z odpowiedzi discovery, zapisywana
  w `market_keywords.search_intent`. Bez lokalnej heurystyki i bez płatnego endpointu Search Intent; brak intencji = „—”.

### 12.3 Seedy

- Źródła: **ręczne** (pole tekstowe: wiersze, przecinki, średniki), **podpowiedzi z GSC**, **podpowiedzi z szans SEO**. Podpowiedzi są
  pokazywane przed wysłaniem czegokolwiek i wybierane przez użytkownika — nic nie trafia do API automatycznie.
- Podpowiedzi GSC (`SeedSuggester`, bez API): frazy z największą liczbą kliknięć w ostatnich 90 dniach, ≥ 50 wyświetleń, średnia pozycja
  (GSC) ≤ 20 (tematy, w których strona już ma trafność), 1–4 słowa, bez fraz z nazwą marki z domeny projektu (heurystyka: pierwszy człon
  domeny). Projekt nie ma osobnej listy tematów — tematy projektu to jego frazy GSC.
- Podpowiedzi z szans: główna fraza otwartych, aktywnych szans typów „blisko TOP”, „słaba pozycja”, „niski CTR” z analizy 28 dni (wg priorytetu).
- Seedy są normalizowane jak dane rynkowe (`MarketKeyword`), duplikaty scalane; przed planem odrzucane: krótsze niż 2 znaki, niespełniające
  reguł dostawcy (`KeywordRules`: > 80 znaków, > 10 słów, niedozwolone znaki), ponad `OSF_SEO_DISCOVERY_MAX_SEEDS` (20).

### 12.4 Przebieg: plan, uruchomienie i cykl życia (D30)

1. **Plan bez API** (`DiscoveryPlanner`) — rynek, seedy, metoda, głębokość, filtry, limit. Na seed: limit elementów = min(limit kandydatów
   przebiegu / liczba seedów, maks. wyników metody — np. 72 przy głębokości 2), żądania (strony) = ⌈elementy / 1000⌉, najwyżej 5 stron,
   maks. koszt. Seedy z cache (12.9) = 0 żądań. Suma: żądania, maks. elementów, **maks. koszt**, pozostały budżet dzienny i miesięczny
   i czy plan się w nim mieści. Suma limitów seedów ≤ limit kandydatów, więc maksymalny koszt jest znany przed wysłaniem.
2. **Uruchomienie** (`DiscoveryService::start`, `osf_seo_manage_keyword_discovery`) — plan przeliczany ponownie; przebieg nie powstaje, gdy:
   dostawca nieskonfigurowany, rynek nieobsługiwany, brak seedów, plan większy lub droższy niż potwierdzony podgląd (`expected_requests`,
   `expected_cost` → „plan się zmienił”), wszystko z cache („nic do pobrania”), wstrzymanie po błędzie konta, plan ponad limit kosztów,
   inny aktywny przebieg projektu (blokada `discovery_start_{projekt}` + sprawdzenie w bazie), ręczne uruchomienie < 60 s od poprzedniego.
   Wynik: przebieg `queued` — **żądanie przeglądarki niczego nie wysyła do API**.
3. **Wykonanie** (`DiscoveryRunner`) — w tle (12.10) albo w CLI, pod wspólną blokadą `MarketSyncService::LOCK`. Każde żądanie (seed × strona):
   kontrola wspólnych limitów → wiersz `market_tasks` (`trigger_type = discovery`, endpoint `labs_related_keywords` /
   `labs_keyword_suggestions`, koszt szacowany) → wywołanie → koszt zgłoszony przez API → metryki, kandydaci i źródła → postęp.
4. **Stany przebiegu**: `queued` (w kolejce) → `running` (w trakcie) → `completed` (wszystkie seedy pobrane lub z cache) / `partial` (część
   seedów z błędem) / `failed` (wszystkie z błędem) / `cancelled` (anulowany — wysłane żądania zostają w rejestrze kosztów; seed pobierany
   w chwili anulowania zapisuje opłaconą stronę, kolejne strony nie są wysyłane). Przebieg zatrzymany limitem kosztów pozostaje aktywny
   z `blocked_by` i wznawia się, gdy limit pozwoli (albo można go anulować).
5. **Stany seeda**: `pending`, `running`, `done`, `cached` (bez kosztu), `failed`, `cancelled`; seed ma `next_offset`, `pages_done`,
   `task_id` (ostatnie zadanie w `market_tasks`), koszt, liczbę elementów i nowych fraz, kod błędu, `next_attempt_at`.
6. **Paginacja**: kolejna strona od `offset`, aż: mniej elementów niż limit strony, limit na seed, `total_count` dostawcy albo 5 stron.
7. **Błędy** (klasyfikacja jak w 11.7): jawny limit żądań dostawcy (nic nie wykonano) → seed wraca do kolejki za 5 min (maks. 3 próby);
   błąd konta (logowanie, środki) → wspólne wstrzymanie DataForSEO na 24 h, seed czeka, przebieg zablokowany (`paused`); sieć, 5xx,
   uszkodzona odpowiedź → seed `failed` **bez ponawiania** (żądanie mogło zostać opłacone — koszt szacowany zostaje w limicie); nieprawidłowe
   żądanie → `failed`, koszt 0. Seed „w trakcie” od ponad godziny (proces padł) → `failed` (`interrupted`) bez ponawiania.
8. **Zapis przebiegu**: kiedy i kto (`created_by`), rynek, dostawca, metoda, głębokość, filtry, limity, `forced`, seedy (pozycja, źródło),
   zadania w `market_tasks`, koszt szacowany i zgłoszony, odebrane elementy, nowe i ponownie znalezione frazy, odrzucenia wg powodu,
   ostatni błąd (kod i komunikat — bez danych logowania).

### 12.5 Model kandydata i deduplikacja (D31)

- Tabele `discovery_runs`, `discovery_run_seeds`, `discovery_candidates`, `discovery_candidate_sources`, `discovery_settings` (sekcja 6.2).
- **Kandydat = projekt × fraza rynkowa** (`UNIQUE(project_id, market_keyword_id)`). Metryki (wolumen, historia, trudność SEO, CPC,
  konkurencja Ads, intencja) **nie są kopiowane** — odczyt z `market_keywords`, wspólnych ze STEP 12 i innymi projektami tego rynku.
  Kandydat ma: `public_id` (ULID), status pracy, notatkę, kto i kiedy zmienił status, liczbę seedów i najsilniejsze powiązanie, widoczność
  GSC (klasa, wyświetlenia, kliknięcia, średnia pozycja GSC), stronę docelową, priorytet i jego składniki (`score`), `excluded`,
  `provider_meta` (fraza główna, kategorie, wykryty język — z pierwszego odkrycia), pierwszy i ostatni przebieg, `discovered_at`, `last_seen_at`.
- **Źródła** (`discovery_candidate_sources`, PK fraza × seed × metoda): seed, przebieg, powiązanie 0–100, głębokość, pozycja w wynikach,
  pierwsze i ostatnie wystąpienie. Ta sama fraza z dwóch seedów = jeden kandydat z dwoma źródłami („Znaleziona z 2 seedów”).
- **Deduplikacja deterministyczna** po kluczu rynkowym `MarketKeyword` (NFC, małe litery UTF-8, białe znaki → jedna spacja): „Strona  Internetowa”
  = „strona internetowa”. Polskie znaki zostają, **bez stemmingu i lematyzacji**: „strona internetowa” ≠ „strony internetowe” (odmiany to osobne
  frazy z własnym wolumenem w Google), „żółw” ≠ „zolw”. Świadomie zachowawczo — błędne scalenie ukryłoby frazę z własnym popytem.
- Ponowne odkrycie (inny seed lub przebieg) aktualizuje `last_seen_at`, `last_run_id` i źródła; status i notatka zostają.
- Nowe frazy przebiegu są ograniczone limitem kandydatów (`max_candidates`; nadmiar liczony jako odrzucenie `limit`).

### 12.6 Widoczność w GSC (D35)

Dopasowanie po `keywords.market_key` — wszystkie warianty frazy GSC projektu o tym samym kluczu rynkowym (wielkość liter, spacje); okno
`OSF_SEO_DISCOVERY_WINDOW_DAYS` (90) dni do ostatniej zaimportowanej daty; wyświetlenia i kliknięcia = sumy, **średnia pozycja (GSC) =
Σ position_sum / Σ impressions** (nigdy średnia pozycji). Klasy (`VisibilityClassifier`, w tej kolejności):

| Klasa | Reguła |
|---|---|
| Nieznana | projekt nie ma danych GSC (brak importu albo po resecie property, przed nowym importem) |
| Brak widoczności | < `OSF_SEO_DISCOVERY_MIN_IMPRESSIONS` (10) wyświetleń w oknie — także fraza nieobecna w GSC |
| Słaba widoczność | średnia pozycja (GSC) > `OSF_SEO_DISCOVERY_VISIBLE_POSITION` (10) |
| Słaba widoczność (sporadyczna) | pozycja ≤ 10, ale wyświetlenia < `OSF_SEO_DISCOVERY_VISIBLE_SHARE` (10%) oczekiwanych (wolumen × dni okna / 30) |
| Już widoczna | pozostałe: pozycja ≤ 10 i stała obecność |

- Przykłady: średnia pozycja 45 przy wolumenie 500 → Słaba widoczność, luka 13,1 z 15 pkt — fraza zostaje wartościowa; pozycja 2 i 3000
  wyświetleń w 90 dniach przy wolumenie 1000 → Już widoczna, luka 0, domyślnie ukryta (filtr „luka widoczności” = brak, słaba, nieznana).
- Etykiety w UI: „Widoczność GSC” i „Średnia pozycja (GSC)” — to dane GSC, nie dokładny ranking SERP.
- **Strona docelowa** (wskazówka): adres z największą liczbą kliknięć, potem wyświetleń, dla tej frazy w GSC (query × page, to samo okno) —
  tylko gdy GSC go zna; inaczej „Brak przypisanej strony”. Bez zgadywania po treści lub adresach.
- **Reset property**: kandydaci, źródła i decyzje zostają; widoczność → Nieznana, klucz przeliczenia unieważniony — po nowym imporcie
  widoczność i priorytet przeliczają się w tle bez płatnych żądań.
- Przeliczenie (`DiscoveryRefresher`) po wynikach przebiegu, zmianie wykluczeń i w tle co 5 min, ale tylko po zmianie klucza danych
  (wersja reguł, ostatnia data GSC, `last_synced_at`, property danych, rynek, odcisk kandydatów i metryk, wykluczenia, progi).

### 12.7 Priorytet odkrycia 0–100 (D33)

Przejrzysta suma ograniczonych składników (`DiscoveryScorer`); wolumen i CPC w skali logarytmicznej z limitem
`L(x, cap) = min(1, log10(1 + x) / log10(1 + cap))`, więc jedna ogromna fraza nie dominuje liniowo:

| Składnik | Maks. | Wzór | Brak danych |
|---|---:|---|---|
| Popyt | 35 | 35 × L(wolumen, 10 000) | 0 |
| Osiągalność | 25 | 25 × (1 − trudność SEO / 100) | 12,5 (neutralnie) |
| Trafność | 20 | 20 × min(1, najsilniejsze powiązanie / 100 + 0,1 × (liczba seedów − 1)) | — |
| Luka GSC | 15 | brak widoczności 15; słaba 15 × max(0,2; min(1; (pozycja − 10) / 40)); sporadyczna 6; już widoczna 0 | nieznana 7,5 |
| Sygnał komercyjny | 5 | 5 × L(CPC, 10 USD) — celowo mała waga: CPC nie może dominować priorytetu SEO | 0 |

Priorytet = zaokrąglona suma (0–100); składniki zapisane w `score` i pokazywane w szczegółach frazy.
**Powiązanie z seedem** (0–100): sam seed 100; Related Keywords głębokość 1/2/3/4 → 80/60/40/30; Keyword Suggestions: fraza zawiera frazę seeda
jako ciąg całych słów → 80, w pozostałych przypadkach → 60.
Przykład: wolumen 1000, trudność SEO 30, CPC 2 USD, głębokość 1, jeden seed, brak widoczności → 26,3 + 17,5 + 16 + 15 + 2,3 = **77**; ta sama
fraza już widoczna → 62. Nazwa w UI: „Priorytet” (priorytet odkrycia) — nigdy „wartość biznesowa”.

### 12.8 Filtry jakości i wykluczenia

- **Po stronie dostawcy** (mniej płatnych elementów): min. wolumen (domyślnie `OSF_SEO_DISCOVERY_MIN_VOLUME` = 10) i opcjonalnie maks. trudność
  SEO 0–99 (domyślnie bez limitu — filtr nie jest agresywny).
- **Lokalnie przy zapisie** (odrzucenia liczone w przebiegu wg powodu): pusta fraza, duplikat w odpowiedzi, wykluczenie projektu, inny język
  (`is_another_language`; domyślnie odrzucane, opcja „uwzględnij inne języki”), wolumen < min albo brak wolumenu, trudność > maks.,
  limit kandydatów. Trafność seeda nie odrzuca — wchodzi do priorytetu.
- **Wykluczenia projektu** (`discovery_settings.excluded_terms`, maks. 200, prowadzi agencja): dopasowanie po całych słowach postaci
  znormalizowanej, `*` na końcu słowa = dowolna końcówka („darmow*”), wielowyrazowe = ciąg słów. Zmiana listy przelicza kandydatów:
  pasujący dostają `excluded` (ukryci; filtr „pokaż wykluczone”), a nowe wyniki z pasującymi frazami nie są zapisywane. Brak listy wbudowanej.
- **Limit kandydatów na przebieg jest obowiązkowy**: 100 / 250 / 500 / 1000 w panelu (domyślnie 250), w CLI `--limit`; górna granica
  `OSF_SEO_DISCOVERY_MAX_CANDIDATES` (1000, zakres 10–5000). Ogranicza elementy pobierane od dostawcy i nowe frazy przebiegu.

### 12.9 Cache seedów, TTL i ponowne użycie metryk (D32, D34)

- Seed nie jest pobierany ponownie (status `cached`, koszt 0), gdy w projekcie w ciągu `OSF_SEO_DISCOVERY_TTL_DAYS` (30 dni) zakończono
  pobranie tego seeda tą samą metodą, u tego samego dostawcy i na tym samym rynku, z co najmniej tak szerokimi parametrami (głębokość ≥,
  limit na seed ≥, min. wolumen ≤, maks. trudność ≥ albo bez limitu). Jego kandydaci już są w projekcie.
- **Pobranie ponowne mimo cache** (`force`) — tylko z `osf_seo_manage_keyword_discovery` (agencja, administrator); klient nie uruchamia
  wyszukiwania w ogóle.
- Cache jest per projekt (przebieg tworzy kandydatów projektu): ten sam seed w innym projekcie na tym samym rynku to nowe żądanie,
  ale metryki fraz są wspólne (`market_keywords`).
- **Metryki**: odpowiedź discovery zapisuje wolumen z historią, CPC, konkurencję Ads i trudność SEO do wspólnych `market_keywords` tylko,
  gdy fraza nie ma danych albo minął TTL (świeży wolumen Google Ads ze STEP 12 nie jest nadpisywany; NULL od dostawcy niczego nie zmienia);
  intencja jest aktualizowana zawsze, gdy dostawca ją zwrócił. Wolumen Labs to ta sama miara (średnia miesięczna liczba wyszukiwań Google),
  więc zapisane metryki obowiązują też w liście fraz i synchronizacji STEP 12 (z tym samym TTL) — fraza odkryta, a potem widoczna w GSC,
  nie wymaga ponownego płatnego wzbogacenia. Kandydaci nigdy nie wywołują osobnych żądań wzbogacających.

### 12.10 Przetwarzanie w tle (D30)

- `DiscoveryService::runBackground` — krok po kolejce GSC i po kroku danych rynkowych (`SyncScheduler::onAfterRun`: WP-Cron i
  `wp osf-seo sync:run`), wyłącznie w procesie systemowym (cron, CLI) — nigdy przy renderowaniu strony.
- Kolejność: zamknięcie przerwanych seedów → zakończenie przebiegów bez pracy → (gdy dostawca skonfigurowany, bez wstrzymania i przy wolnej
  wspólnej blokadzie) najwyżej `OSF_SEO_DATAFORSEO_MAX_TASKS_PER_RUN` (4) żądań w budżecie czasu `OSF_SEO_SYNC_TIME_BUDGET` (20 s) → przeliczenie kandydatów projektów
  z nowymi wynikami → co 5 min sprawdzenie klucza danych pozostałych projektów (przeliczenie tylko po zmianie).
- Wspólna blokada sprawia, że synchronizacja danych rynkowych i wyszukiwanie nigdy nie wysyłają płatnych żądań równocześnie; limit żądań
  na przebieg dotyczy każdego kroku osobno, **limity kosztów są wspólne**.
- Izolacja od GSC: osobne tabele, bez `sync_runs`; każdy krok po kolejce ma własną obsługę błędu (log), więc awaria wyszukiwania nie
  zatrzymuje importu GSC, danych rynkowych ani analizy szans.
- Postęp w panelu: JSON stanu odpytywany co 5 s tylko, gdy przebieg trwa (Alpine); stronę można opuścić — przebieg trwa w tle.
  Bez crona systemowego tło działa przy ruchu na stronie (WP-Cron); `wp osf-seo discovery:run` wykonuje przebieg od razu.

### 12.11 Praca nad frazą

Statusy: **Nowa** (`new`) → **Do analizy** (`review`) → **Zaakceptowana** (`accepted`) / **Odrzucona** (`dismissed`), dowolne przejścia;
notatka (do 2000 znaków); zapis, kto i kiedy zmienił status. Zmiana pojedynczo (szczegóły frazy) i zbiorczo (zaznaczone na liście).
Domyślna lista pokazuje frazy „do decyzji” (Nowa, Do analizy). Ponowne odkrycie nie zmienia decyzji. Strategia i backlog — kolejny etap.

### 12.12 UI — moduł „Nowe frazy”

- **Lista** (`/projects/{project}/discovery`): Fraza (+ intencja), Wolumen, Trudność SEO, Priorytet, Widoczność GSC, Średnia pozycja (GSC),
  Źródło (seedy), Status; rozwijane szczegóły: CPC, Konkurencja Ads, intencja, seedy, strona docelowa, data odkrycia. Filtry serwerowe:
  szukaj, status, widoczność, min. wolumen, maks. trudność SEO, min. priorytet, intencja, wykluczone; sortowanie: priorytet, wolumen, trudność
  SEO, średnia pozycja (GSC), data odkrycia, fraza; paginacja po 50. Brak danych rynkowych = „—”, nigdy 0.
- **Szczegóły frazy**: metryki, historia wolumenu, widoczność i strona docelowa, składniki priorytetu, źródła (seed, metoda, powiązanie,
  przebieg), status, notatka, kto i kiedy zmienił status.
- **„Znajdź nowe frazy”** (`/discovery/new`): seedy (pole + podpowiedzi GSC i szans widoczne przed wysłaniem), metoda i głębokość, limit
  kandydatów, min. wolumen, maks. trudność SEO, inne języki, pobranie mimo cache → **„Sprawdź koszt”** (bezpłatny plan: żądania, maks. elementów,
  maks. koszt, seedy z cache, pozostały budżet dzienny i miesięczny, rynek) → **„Uruchom wyszukiwanie”** z jawnym potwierdzeniem
  (przycisk blokowany po kliknięciu; formularz niesie potwierdzone `expected_requests` i `expected_cost`).
- **Przebieg** (`/discovery/runs/{run}`): postęp na żywo, seedy, odrzucenia, koszt (tylko z capability), anulowanie.
- **Dane rynkowe**: wydatki dziś i w miesiącu względem wspólnych limitów (wszystkie projekty, łącznie z wyszukiwaniem), dla projektu podział
  miesiąca na „wzbogacanie fraz” / „wyszukiwanie nowych fraz” i suma dnia; zadania wyszukiwania w tabeli ostatnich zadań. **Ustawienia**: konfiguracja wyszukiwania (TTL, limity, progi widoczności).
- **Klient** (`osf_seo_client`): lista, szczegóły i przebiegi tylko do odczytu — bez kosztów, przycisków, zaznaczania i wykluczeń.

### 12.13 CLI

```bash
wp osf-seo discovery:suggest --project=<id> [--format=json]                        # podpowiedzi seedów z GSC i szans (bez API)
wp osf-seo discovery:plan --project=<id> --seeds="a, b" [--method=related|suggestions] [--depth=1-3] [--limit=<n>] \
  [--min-volume=<n>] [--max-kd=<n>] [--include-other-languages] [--force] [--format=json]   # plan: ZERO żądań do API
wp osf-seo discovery:run --project=<id> --seeds="a, b" [opcje planu] [--yes] [--queue-only] [--max-seconds=300]   # PŁATNE: plan → potwierdzenie → wykonanie
wp osf-seo discovery:status --project=<id> [--run=<id>] [--format=json]            # aktywny przebieg, ostatnie przebiegi, liczby (bez API)
wp osf-seo discovery:list --project=<id> [--status=open|all|…] [--visibility=gap|all|…] [--sort=…] [--search=…] [--page=<n>] [--format=json]
wp osf-seo discovery:cancel --project=<id> --run=<id>                              # anulowanie (wysłane żądania zostają w kosztach)
wp osf-seo discovery:refresh --project=<id>                                        # przeliczenie widoczności i priorytetu (bez API)
```

`--seeds-file=<plik>` czyta seedy z lokalnego pliku. `--format=json` zwraca wyłącznie JSON (plan: `dry_run: true`, `api_requests: 0`).
Komendy płatne i zmieniające wymagają `--user` z `osf_seo_manage_keyword_discovery`; bez `--user` — dostęp systemowy (jak inne komendy).

### 12.14 Uprawnienia i bezpieczeństwo

- Nowa capability **`osf_seo_manage_keyword_discovery`** (wersja 0.13.0; administrator i `osf_seo_admin`): plan i podgląd kosztu, uruchomienie,
  `force`, anulowanie, decyzje, notatki, wykluczenia, widok kosztów. Odczyt listy i szczegółów: dostęp do projektu (`osf_seo_access`).
- `ProjectGuard` → `ProjectContext`; przebieg i kandydat z URL-a szukane wyłącznie po (`project_id` z kontekstu, `public_id`) — identyfikator
  innego projektu, wewnętrzne ID i nieprawidłowy ULID → 404. Mutacje: trasa `ResolveProject:osf_seo_manage_keyword_discovery` + kontrola
  w `DiscoveryService` (obrona w głąb), nonce i zgodny Origin.
- Frazy od dostawcy i URL-e GSC to dane zewnętrzne — zawsze escapowane; linki tylko `http(s)` z `rel="noopener noreferrer"`.
- Logi i błędy: kategoria, kod i identyfikator przebiegu — bez danych logowania i treści żądań; JSON postępu dla klienta bez kosztów.
- `discovery_candidates.public_id` jest `utf8mb4_bin` (nie `ascii_bin` jak w innych tabelach): `$wpdb` traktuje tabelę z kolumnami ascii
  i utf8mb4 bez kolumny binarnej jako ASCII i odrzuca zapytania z polskimi znakami; komunikaty błędów przebiegu zapisywane przez `update()`.

### 12.15 Wydajność (pomiar)

Benchmark `composer test:performance` (MariaDB 10.11; dane jak w 11.11 plus 25 000 kandydatów — projekt: 5000, w tym 3000 fraz z danymi GSC;
inny projekt/rynek: 20 000 — 33 333 źródła, 330 przebiegów z 3300 seedami):

| Przypadek | Czas (mediana z 3) |
|---|---:|
| Lista domyślna (do decyzji, luka widoczności, wg priorytetu) | 27 ms |
| Wszystkie, wg wolumenu, min. wolumen 1000 + maks. trudność SEO 40 | 33 ms |
| Wyszukiwanie + intencja komercyjna | 22 ms |
| Wg średniej pozycji (GSC), strona 20 | 39 ms |
| Szczegóły frazy (źródła, historia) | 1 ms |
| Przeliczenie widoczności GSC i priorytetu 5000 kandydatów (wymuszone) | 938 ms |
| Plan 10 seedów (cache, bez API) | 1 ms |
| Podpowiedzi seedów (GSC 90 dni + szanse) | 283 ms |
| Stan modułu (liczby, aktywny przebieg) | 16 ms |

- Lista: `c` range `project_priority` (kandydaci projektu) → `m` eq_ref PRIMARY (`STRAIGHT_JOIN`), sortowanie w pamięci po ≤ liczbie kandydatów
  projektu, `COUNT(*) OVER()`. Szczegóły: const `public_id`; źródła ref PRIMARY (`candidate_id`); historia range PRIMARY.
- Widoczność GSC: paczki po 500 kluczy, `keywords` range `project_market_key` → `gsc_query_daily` ref `project_keyword_date`; strona docelowa:
  → `gsc_query_page_daily` ref `project_keyword_date_page`. Liczba seedów: `c` ref `project_priority` → źródła ref PRIMARY.
- Cache seedów: `discovery_runs` ref `project_run` → seedy ref PRIMARY. Aktywny przebieg: range `status_run`.
- Liczby modułu i odcisk przeliczenia wybierają pełny skan kandydatów, gdy projekt ma dużą część tabeli (tu 20%); przy wielu projektach
  planer użyje `project_priority`/`project_market_keyword`. Wszystkie zapytania bez N+1.

### 12.16 Bezpieczny smoke test na stagingu (1–2 seedy)

Wykonuje osoba z dostępem do stagingu — nie agent. Zakłada wdrożony kod STEP 13, schemat 7, stałe DataForSEO w `wp-config.php` i projekt
z danymi GSC (rynek Polska/polski).

1. `wp osf-seo status` (schemat 7, `dataforseo: configured`), `wp osf-seo dataforseo:status --project=<id>` (koszty dziś/miesiąc, limity).
2. `wp osf-seo discovery:suggest --project=<id>` — wybierz 1–2 seedy (bezpłatnie).
3. `wp osf-seo discovery:plan --project=<id> --seeds="<seed 1>, <seed 2>" --depth=1 --limit=20` — plan bez żądań: 2 żądania, ≤ 16 fraz,
   maks. **0,02616 USD** (2 × (0,012 + 9 × 0,00012)); jeden seed: 1 żądanie, maks. 0,01308 USD.
4. `wp osf-seo discovery:run --project=<id> --seeds="<seed 1>, <seed 2>" --depth=1 --limit=20` — pokazuje ten sam plan i pyta o potwierdzenie;
   wykonuje przebieg od razu (pod wspólną blokadą i limitami).
5. `wp osf-seo discovery:status --project=<id> --run=<id>` (koszt zgłoszony ≈ szacunek), `discovery:list --project=<id> --visibility=all`,
   panel „Nowe frazy” (lista, szczegóły, widoczność, „—” dla braków), „Dane rynkowe” (koszt w podziale „wyszukiwanie nowych fraz”).
6. Powtórzenie kroku 3 z tymi samymi seedami — plan: seedy z cache, 0 żądań, 0 USD. Porównać koszt z panelem DataForSEO; dopiero potem
   zwiększać liczbę seedów, głębokość i limit.

### 12.17 Ograniczenia

- Ceny i parametry zweryfikowane pośrednio (12.2); lokalne limity są bezpiecznikiem, nie rozliczeniem.
- Metody: Related Keywords i Keyword Suggestions; bez Keyword Ideas, fraz domen konkurencji i SERP (kolejne etapy).
- Metryki kandydata odświeżają się, gdy fraza zostanie znaleziona ponownie albo trafi do synchronizacji STEP 12 (fraza w GSC) — bez
  osobnego odświeżania kandydatów; `provider_meta` pochodzi z pierwszego odkrycia.
- Intencja tylko z odpowiedzi discovery (frazy wyłącznie z GSC nie mają intencji).
- Cache seedów per projekt (nie między projektami); głębokość 4 niedostępna (do ~4680 fraz na seed).
- Deduplikacja bez lematyzacji — odmiany frazy to osobni kandydaci (świadomie).
- Strona docelowa i widoczność wymagają danych GSC (inaczej „Nieznana” / „Brak przypisanej strony”); tło zależy od crona (jak kolejka GSC).

## 13. Pozycje SERP i konkurenci (STEP 14)

Zaimplementowane w STEP 14 (`plugins/osf-seo/src/Serp`, `src/DataForSeo/DataForSeoSerpProvider.php`, migracja M0008 — schemat 8;
UI: `PositionsController`, `CompetitorsController`, moduły „Pozycje” i „Konkurenci”; CLI `wp osf-seo serp:*`, `competitors:*`).
Cel: obserwowana **Pozycja SERP** monitorowanych fraz (pełne wyniki organiczne Google z pomiaru DataForSEO), jej historia i zmiany oraz
pozycje konkurentów — odczytywane z tych samych zapisanych wyników.

### 13.1 Zasady

- **Pozycja SERP ≠ średnia pozycja GSC.** Pozycja SERP to `rank_group` najlepszego wyniku **organicznego** rodziny domeny projektu
  (domena + subdomeny, 13.4) w ostatnim pomiarze. `rank_absolute` (miejsce wśród wszystkich elementów strony) zapisujemy, ale nie jest
  „Pozycją SERP”. **Wyróżniony fragment** zapisujemy i pokazujemy osobno — nigdy jako #1. Średnia pozycja GSC pozostaje osobną metryką
  (kolumna obok, nigdy zamiennik); dane i semantyka GSC są bez zmian.
- **Płatne zlecenia wyłącznie z `SerpSubmitter`** — w kroku w tle (`SerpTrackingService::runBackground`, WP-Cron / `sync:run`) albo
  z `wp osf-seo serp:run`, pod wspólną blokadą płatnych żądań i wspólnymi limitami kosztów DataForSEO (D40). Kontroler, widok i raport
  nigdy nie wysyłają płatnego żądania: plan i podgląd kosztu są lokalne, „Sprawdź pozycje teraz” tylko kolejkuje potwierdzony plan.
- **Abstrakcja (D36)**: aplikacja zależy od `SerpProvider` (rynek, endpoint, limit zadań w zleceniu, szacunek kosztu, zlecenie, lista
  gotowych zadań, odbiór); `DataForSeoSerpProvider` (na `DataForSeoClient` ze STEP 12) jest jedyną implementacją.
- **Płatne pomiary domyślnie wyłączone.** Wdrożenie niczego nie uruchamia; harmonogram włącza osoba z uprawnieniem po podglądzie kosztu
  i jawnym potwierdzeniu. Nie monitorujemy automatycznie wszystkich fraz ani wszystkich znalezionych fraz — tylko jawnie dodane (D39).
- **Pomiary są per projekt** (bez współdzielenia SERP-ów między projektami, D38): fraza monitorowana w dwóch projektach to dwa zadania.
- Poza zakresem: Keyword Gap, Content Gap, AI, backlinki, crawl konkurencji, generowanie treści, `calculate_rectangles`, płatne ładowanie
  AI Overview, klikanie „Ludzie pytają też” (bez zgody — każda z tych opcji zwiększa koszt).

### 13.2 Endpoint, tryb i ceny (D36)

| Element | Wartość |
|---|---|
| Zlecenie | `POST serp/google/organic/task_post` — kolejka **Standard** (priorytet zwykły), do **100 zadań** w jednym zleceniu, jedno zadanie = jedna fraza |
| Lista gotowych | `GET serp/google/organic/tasks_ready` (bezpłatna; zadania z ostatnich 3 dni, z `tag`) |
| Odbiór | `GET serp/google/organic/task_get/advanced/{id}` (bezpłatny; wynik do 30 dni) |
| Treść zadania | `keyword`, `location_code`, `language_code`, `device` (`desktop`/`mobile`), `os` (`windows`/`android`), `depth`, `max_crawl_pages = ceil(depth/10)`, `tag` = ULID pomiaru, `remove_from_url: ["srsltid"]` — bez `priority`, bez płatnych opcji |
| Kody | 20100 utworzone; 40601/40602 w kolejce; 40102 brak wyników (= poza TOP); błędy konta (401xx/402xx) wstrzymują płatne wywołania wszystkich modułów |
| Domyślnie | Google Organic, Polska (2616), polski, desktop, **TOP100**, co tydzień |

Cena (konfigurowalna): pierwsza strona wyników `OSF_SEO_DATAFORSEO_PRICE_SERP_PAGE` = 0,0006 USD + każda kolejna strona
`OSF_SEO_DATAFORSEO_PRICE_SERP_NEXT_PAGE` = 0,00045 USD (75% ceny strony). Szacowany maksymalny koszt frazy: TOP10 0,0006, TOP20 0,00105,
TOP50 0,0024, **TOP100 0,00465 USD**. Przykład w FAQ dostawcy podaje inną kwotę dla 100 wyników — dlatego w UI i CLI zawsze
„szacowany **maksymalny** koszt”, a **rozstrzygający jest koszt zgłoszony przez dostawcę**: zapisujemy go per zadanie (`serp_snapshots.cost`)
i per zlecenie (`market_tasks.cost`) i porównujemy w smoke teście (13.15). Stałych ceny nie aktualizujemy automatycznie.

### 13.3 Model danych (M0008, D37)

| Tabela | Zawartość |
|---|---|
| `serp_contexts` | kontekst pomiaru: wyszukiwarka, typ, lokalizacja, język, urządzenie, system, głębokość (klucz MD5) — zmiana któregokolwiek = nowa seria |
| `serp_settings` | ustawienia projektu: włączone (domyślnie 0), częstotliwość, urządzenie, głębokość, termin (`next_run_at`), blokada po pominięciu (`retry_after`), ostatni powód pominięcia |
| `serp_tracked_keywords` | monitorowana fraza projektu (ULID, `market_keyword_id`, źródło, status, `last_requested_at`) + **stan bieżący**: ostatni i poprzedni porównywalny pomiar, pozycja, URL, wyróżniony fragment, typ i wartość zmiany, zmiana TOP10 — lista bez skanowania historii |
| `serp_runs` | przebieg (harmonogram / ręczny): stan, liczby, koszt szacowany i zgłoszony, błąd; `UNIQUE (project_id, slot_key)` = jeden przebieg na termin |
| `serp_snapshots` | pomiar = jedno zadanie dostawcy: ULID (`tag`), kontekst, przebieg, zlecenie kosztów, `provider_task_id`, stan, koszt, czasy, metadane strony (domena Google, liczba wyników, typy elementów jako maska bitowa, korekta zapytania) i wynik projektu |
| `serp_results` | **pełne TOP N każdego pomiaru, wszystkie domeny**: `(snapshot_id, item_index)`, typ (organiczny / wyróżniony fragment), `rank_group`, `rank_absolute`, strona, `domain_id`, `url_id`, `snippet_id`, flagi — same liczby |
| `serp_domains`, `serp_urls`, `serp_snippets` | słowniki (MD5 → ID): host + odwrócony host (indeks prefiksu rodziny domen), adres, prezentacja wyniku (tytuł, opis, breadcrumb, nazwa witryny, ograniczone dodatki) |
| `serp_competitors` | konkurenci projektu: ULID, nazwa, domena (znormalizowana), status `active` / `inactive` / `archived`; `UNIQUE (project_id, domain_key)` |

- Bez trwałego surowego JSON-a. Historia bez automatycznego usuwania; klucze rosnące (`snapshot_id`) pozwalają w przyszłości na
  archiwizację, partycjonowanie zakresami, rollupy (np. tylko TOP10 starszych pomiarów) i retencję — bez zmiany modelu.
- Wiersz `serp_results` ma ~26 B danych (+ indeks `domain_snapshot` do konkurentów); TOP100 = 100 wierszy na pomiar (13.14).
- Słowniki: najpierw odczyt istniejących po hashu, wstawiane tylko brakujące (`ON DUPLICATE KEY UPDATE id = id`) — bez marnowania
  AUTO_INCREMENT przy współbieżności; zapis wyników w jednej transakcji z warunkowym przejęciem pomiaru (zapis tylko raz).
- `serp_tracked_keywords.public_id` jest `utf8mb4_bin` (tabela z kolumną binarną) — `$wpdb` nie odrzuca zapytań z polskimi znakami.
- Reset property GSC nie dotyka tabel SERP (nie należą do `GscDataStore::DATA_TABLES`).

### 13.4 Dopasowanie domen (rodzina domeny)

`DomainFamily::normalize`: małe litery, bez `www.`, IDN → punycode, z URL-a host; odrzucone IP, localhost, pojedyncze etykiety.
Dopasowanie: `host === domena` albo `host` kończy się na `.domena` (granica etykiet — `przyklad.pl` ≠ `nieprzyklad.pl`, bez dopasowania
podciągu). W SQL rodzina domeny = `host_rev = 'pl.przyklad' OR host_rev LIKE 'pl.przyklad.%'` (zakres indeksu `host_rev`). Konkurent nie
może być domeną projektu, jej subdomeną ani domeną nadrzędną; subdomena konkurenta może być osobnym konkurentem.

### 13.5 Zmiana pozycji i konteksty

`RankChange` porównuje bieżący pomiar z **poprzednim w tym samym kontekście** (ta sama lokalizacja, język, urządzenie, głębokość):
#12 → #7 = `up` +5; #4 → #9 = `down` −5; poza TOP → znaleziona = „Weszła do TOP100”; znaleziona → poza TOP = „Wypadła z TOP100”; poza TOP
w obu = `out`; pierwszy pomiar = `new`; poprzedni pomiar tylko w innym kontekście = **„Nieporównywalne”** (bez liczby). Wartość liczbowa
nigdy z pustej pozycji. Osobno: wejście do TOP10 i wypadnięcie z TOP10. Stan bieżący jest przeliczany przy zapisie każdego wyniku według
`checked_at` (spóźniony starszy wynik nie nadpisuje nowszego).

### 13.6 Monitorowane frazy i limit (D39)

- Źródła: ręcznie (tekst), z listy **Frazy** (tylko frazy GSC projektu, dokładny tekst), z **Nowych fraz** (ULID kandydatów projektu).
  Fraza → klucz rynkowy (`MarketKeyword`: NFC, małe litery, spacje) → wiersz `market_keywords` (tworzony bez danych; **bez wzbogacania** —
  Wolumen/KD „—”, dopóki nie trafią tam z STEP 12/13). Reguły: maks. 200 znaków, bez operatorów (`site:` itd.) i znaków sterujących.
- **Limit miękki** `OSF_SEO_SERP_MAX_KEYWORDS` (domyślnie 500, zakres 1–1 000 000) — rekomendacja, nie ograniczenie architektury: przekroczenie
  = jasny komunikat (limit, obecnie, ile można dodać, ile wybrano) i **brak zmian** (bez cichego obcinania). Tabele, plan, paczki po 100,
  harmonogram i lista są projektowane na tysiące fraz w projekcie (13.14). Poza tą stałą w kodzie nie ma założenia 500 fraz.
- Usunięcie z monitorowania jest miękkie (`removed`): historia zostaje, ponowne dodanie przywraca frazę z historią.

### 13.7 Pomiar: plan, rezerwacja, wysyłka, odbiór (D40)

1. **Plan** (`SerpPlanner`, bez API): monitorowane frazy bez zlecenia w ostatnich `OSF_SEO_SERP_MIN_RECHECK_HOURS` (6 h), zadania, zlecenia
   po 100, szacowany maksymalny koszt, koszt pełnego pomiaru i miesięczny, pozostały limit dzienny i miesięczny, limit blokujący.
2. **Rezerwacja** (`SerpSubmitter::queue`, bez API, blokada `serp_reserve`): w jednej transakcji zajęcie fraz (`last_requested_at`, warunkowo),
   kontrola **pełnego** kosztu z limitami (cały pomiar albo wcale — bez arbitralnego częściowego pomiaru), przebieg, pomiary `queued`
   i po jednym wierszu `market_tasks` (`pending`, koszt szacowany) na zlecenie — **rezerwacja liczy się od razu do wspólnych limitów**,
   więc dane rynkowe i Nowe frazy nie wydadzą tego budżetu.
3. **Wysyłka** (`SerpSubmitter::submit`, pod `MarketSyncService::LOCK`): pomiary oznaczane `uncertain` **przed** wysłaniem. Odpowiedź:
   zadania dopasowane po `tag` → `submitted` (koszt zgłoszony); odrzucone → `failed` bez kosztu; zlecenie → `completed` z kosztem
   zgłoszonym albo zwolnione (koszt 0). Limit żądań/konto (dostawca nic nie wykonał) → paczka wraca do kolejki; błąd konta wstrzymuje
   wszystkie płatne wywołania. **Wynik nieznany** (sieć, 5xx, uszkodzona odpowiedź) → **bez ponawiania** (zadania mogły zostać opłacone),
   koszt szacowany zostaje w limicie, reszta przebiegu jest anulowana (rezerwacje zwolnione).
4. **Odbiór** (`SerpCollector`, bezpłatny, blokada `serp_collect`): lista gotowych zadań przyspiesza odbiór i **odzyskuje niepewne
   zlecenia po `tag`**; pozostałe sprawdzane bezpośrednio z narastającym odstępem (10 min … 2 h); po `OSF_SEO_SERP_EXPIRE_HOURS` (72 h)
   → `expired`; niepewne nieodnalezione przez 72 h → `failed` (`interrupted`, koszt szacowany zostaje). Zadania nieznane (np. innej
   instalacji na tym samym koncie) są ignorowane. Zapis: słowniki → transakcja (przejęcie, wstawienie wsadowe TOP N, metadane, stan bieżący).
5. **Anulowanie**: niewysłane paczki → `cancelled`, rezerwacje kosztu i frazy zwolnione; zlecone zadania są odbierane i zostają w kosztach.

### 13.8 Harmonogram, nakładanie się i odstępy (D41)

- Częstotliwość: codziennie / co 3 dni / co tydzień (domyślnie, wyłączone). Krok w tle wybiera projekty z terminem (`enabled`,
  `next_run_at ≤ teraz`, bez `retry_after`), maks. 25 na przebieg tła, i kolejkuje pełny pomiar z kluczem terminu `auto:<termin>`.
- **Nakładanie się**: `UNIQUE (project_id, slot_key)` — dwa procesy crona nie utworzą dwóch przebiegów tego samego terminu; zajęcie fraz
  z oknem 6 h — pomiar ręczny i cron (albo dwa kliknięcia) nie zlecą tej samej frazy dwa razy; wysyłka pod wspólną blokadą płatnych żądań.
- **Budżet**: gdy pełny pomiar nie mieści się w limicie, harmonogram zapisuje przebieg `skipped` z powodem (`daily_limit`/`monthly_limit`),
  pokazuje go w panelu i ponawia po odnowieniu limitu (następna doba UTC 00:05 / pierwszy dzień miesiąca) — nie wcześniej.
- Pomiar ręczny: odstęp 15 min na projekt (CLI bez odstępu), podgląd → potwierdzenie (`expected_tasks`, `expected_cost`) → kolejka;
  plan większy lub droższy niż podgląd nie zostanie zakolejkowany.

### 13.9 Konkurenci (D42)

- **Monitorowani**: dodawanie (domena + nazwa), edycja, wstrzymanie (`inactive` — znika z pozycji, dane zostają), archiwum, przywrócenie;
  walidacja (13.4), bez duplikatów. Pozycje konkurenta są **odtwarzane z zapisanych pełnych SERP-ów** (także konkurenta dodanego później —
  z całej historii), bez dodatkowych żądań: najlepszy wynik rodziny domeny w pomiarze, inne adresy, zmiana względem poprzedniego pomiaru.
- **Organiczni**: domeny z najnowszych pomiarów monitorowanych fraz (bez rodziny domeny projektu): liczba fraz, TOP3/10/20, średnia najlepszej
  pozycji (tylko frazy z domeną), frazy wspólne z projektem, liczba adresów — **fakty, bez ocen**; „Dodaj jako konkurenta”.

### 13.10 Koszty

Jeden wiersz `market_tasks` na zlecenie (`endpoint = google_organic_serp`, `keywords_count` = liczba zadań, `trigger_type` `serp_manual` /
`serp_schedule`). Utrzymanie i odbiór STEP 12 pomijają zlecenia SERP. „Dane rynkowe” pokazują podział **Dane rynkowe / Nowe frazy /
Pozycje SERP / RAZEM** (dziś i w miesiącu, limity wspólne). Koszt pomiaru: szacowany maksymalny przy planie, zgłoszony po zleceniu.

### 13.11 UI

- **Pozycje** (`/projects/{project}/positions`): karty (TOP3, TOP10, wzrosty, spadki, weszły/wypadły z TOP10, poza TOP, niesprawdzone),
  filtry (szukaj, pasmo pozycji, zmiana), sortowanie, paginacja po 50; kolumny Fraza, Wolumen, KD, **Pozycja SERP**, Zmiana, URL,
  **Średnia pozycja (GSC)** (osobno), Konkurenci (najlepsza pozycja aktywnych), Ostatni pomiar; zaznaczanie: „Sprawdź zaznaczone”,
  „Zakończ monitorowanie”.
- **Szczegóły frazy**: Pozycja SERP, element strony, wyróżniony fragment, zmiana, średnia GSC, wykres historii (projekt i konkurenci,
  oś odwrócona, przerwa = poza TOP; Chart.js ładowany tylko tam), tabela historii, wybór pomiaru archiwalnego, **pełne TOP N** z wyróżnieniem
  projektu i konkurentów, korekta zapytania i elementy strony (bez liczenia ich w pozycji).
- **Sprawdź pozycje teraz** (`/positions/check`): rynek, urządzenie, głębokość, frazy, zadania i zlecenia, koszt zadania, **szacowany
  maksymalny koszt**, pozostały limit → potwierdzenie → kolejka → strona pomiaru z postępem i anulowaniem.
- **Ustawienia** (`/positions/settings`): harmonogram, częstotliwość, urządzenie, głębokość (z ceną), koszt pełnego pomiaru i miesięczny,
  pozostały limit, ostrzeżenie, gdy pomiar przekracza limit dzienny; włączenie wymaga zaznaczenia potwierdzenia.
- **Dodaj frazy**, **Frazy** (kolumna „Pozycja SERP” tylko dla monitorowanych + „Monitoruj pozycję” pojedynczo i zbiorczo), **Nowe frazy**
  („Monitoruj pozycję” na liście i w szczegółach) — bez kosztów i bez żądań.
- **Konkurenci** (lista, szczegóły, organiczni), **Dane rynkowe** (podział kosztów), **Ustawienia** (cennik i limity SERP).
- **Klient**: Pozycje, szczegóły, Konkurenci i organiczni tylko do odczytu — bez kosztów, przycisków i zaznaczania.

### 13.12 CLI

```bash
wp osf-seo serp:plan --project=<id> [--keywords=<ULID|fraza,…>] [--format=json]  # plan: ZERO żądań (dry_run, api_requests: 0)
wp osf-seo serp:run --project=<id> [--keywords=…] [--yes] [--queue-only] [--wait=<s>]   # PŁATNE: plan → potwierdzenie → wysyłka (--wait: odbiór)
wp osf-seo serp:collect [--max-seconds=60]                                        # odbiór wyników (bezpłatny)
wp osf-seo serp:status --project=<id> [--run=<id>] [--format=json]               # ustawienia, liczniki, koszty, ostatnie pomiary
wp osf-seo serp:list --project=<id> [--band=…] [--change=…] [--sort=…] [--search=…] [--page=<n>] [--format=json]
wp osf-seo serp:snapshot --project=<id> --keyword=<ULID|fraza> [--snapshot=<ULID>] [--format=json]   # pełne TOP N pomiaru
wp osf-seo serp:track --project=<id> --keywords="a, b" [--from=manual|gsc|discovery]   # dodanie (bez API)
wp osf-seo serp:untrack --project=<id> --keywords="a, b"                          # zakończenie monitorowania (historia zostaje)
wp osf-seo serp:settings --project=<id> [--enable|--disable] [--frequency=…] [--device=…] [--depth=…] [--yes]
wp osf-seo competitors:list|add|update|organic --project=<id> …                   # konkurenci (bez API)
```

Wyjście po angielsku; `--format=json` wyłącznie JSON. Komendy zmieniające i płatne wymagają `--user` z `osf_seo_manage_serp_tracking`
(bez `--user` — dostęp systemowy, jak inne komendy).

### 13.13 Uprawnienia i bezpieczeństwo

- Nowa capability **`osf_seo_manage_serp_tracking`** (wersja 0.14.0; administrator i `osf_seo_admin`): ustawienia i włączenie pomiarów,
  plan i podgląd kosztu, pomiar ręczny, anulowanie, dodawanie i usuwanie fraz, konkurenci, widok kosztów. Odczyt: dostęp do projektu.
- Fraza, pomiar, przebieg i konkurent z URL-a szukane wyłącznie po (`project_id` z `ProjectContext`, `public_id`) — obce ID → 404.
  Mutacje: trasa `ResolveProject:osf_seo_manage_serp_tracking` + kontrola w usługach, nonce i zgodny Origin; powrót po akcji tylko na adres
  panelu tego projektu.
- Tytuły, opisy, domeny i URL-e z SERP to dane zewnętrzne — zawsze escapowane; linki tylko `http(s)` z `rel="noopener noreferrer nofollow"`;
  adresy bez fragmentu i parametru `srsltid`. JSON postępu dla klienta bez kosztów. Logi bez danych logowania i treści żądań.

### 13.14 Wydajność (pomiar)

Benchmark `composer test:performance:serp` (`tests/Performance/serp-benchmark.php`, MariaDB 10.11, osobna baza testowa; dane syntetyczne
generowane `INSERT … SELECT`): **100 projektów × 500 monitorowanych fraz** z pełnym TOP100 w ostatnim pomiarze, projekt nr 1 z **historią
52 tygodni** (500 fraz × 52 pomiary), projekt „duży” z **2500 frazami**; 5 konkurentów na projekt; słowniki: 50 202 domen (popularność
skośna), 1 004 040 adresów, 300 000 opisów.

| Tabela | Wiersze | Dane + indeksy |
|---|---:|---:|
| `serp_results` | 7 800 000 | 366 MB + 205 MB (~77 B/wiersz łącznie) |
| `serp_snapshots` | 78 000 | 13,5 + 13,6 MB |
| `serp_tracked_keywords` | 52 500 | 10,5 + 13,1 MB |
| `serp_urls` / `serp_snippets` / `serp_domains` | 1 004 040 / 300 000 / 50 202 | 142 / 86 / 10,5 MB |

| Przypadek | Czas (mediana z 3) |
|---|---:|
| Zapis TOP100 (`SerpStore::ingest`, słowniki + wstawienie wsadowe + stan bieżący), domeny i adresy już w słownikach | 20,5 ms (p95 38,6 ms) |
| Zapis TOP100 z nowymi domenami i adresami (wstawiane do słowników) | 26,0 ms (p95 61,3 ms) |
| Lista Pozycje: 500 fraz, 5 konkurentów, średnia GSC (10 zapytań, bez N+1) | 17,6 ms |
| Lista Pozycje: 2500 fraz — strona 1 / ostatnia / wg wolumenu / TOP10 + wyszukiwanie | 44 / 47 / 45 / 20 ms |
| Liczniki modułu (2500 fraz) | 6,4 ms |
| Szczegóły frazy: historia 52 pomiarów, konkurenci w historii, pełne TOP100 | 13,2 ms |
| Pełne TOP100 jednego pomiaru (wyniki + słowniki) | 1,5 ms |
| Konkurenci: lista 5 konkurentów (500 fraz) / szczegóły konkurenta | 35 / 18 ms |
| Konkurenci organiczni: 500 fraz / 2500 fraz (bez pamięci podręcznej) | 240 ms / 1194 ms |
| Konkurenci organiczni: kolejne wejście (pamięć podręczna, klucz = stan pomiarów) | 6,3 ms |
| Plan pomiaru bez API (2500 fraz, 25 zleceń) | 16,8 ms |
| Rezerwacja pomiaru 2500 fraz (zajęcie fraz, 25 paczek, wiersze kosztów; bez API) | 187 ms |
| Harmonogram: projekty z terminem (101 włączonych) / kolumna Pozycja SERP w Frazach (50 fraz) | 0,5 / 2,3 ms |

- Zapis pomiaru: 16–20 zapytań niezależnie od liczby wyników (słowniki: odczyt po hashach paczkami + wstawienie brakujących, wyniki jednym
  wstawieniem wsadowym, stan bieżący 3 zapytaniami). 100 000 zadań (np. 1000 fraz dziennie przez 100 dni) ≈ 35–45 min pracy tła łącznie.
- Lista: `t` ref `project_market_keyword` (frazy projektu) → `m`/`u` eq_ref (`STRAIGHT_JOIN`), sortowanie w pamięci po ≤ liczbie fraz projektu,
  `COUNT(*) OVER()`; filtr pasma: range `project_rank`. Pozycje konkurentów na stronie: range `domain_snapshot` (domeny rodziny × pomiary strony).
  Średnia GSC: range `project_market_key` → ref `project_keyword_date`.
- Historia: ref `tracked_history`; pełne TOP N: ref PRIMARY (`snapshot_id`) → słowniki eq_ref. Rodzina domeny: range `host_rev`.
- **Najwolniejsze zapytanie**: konkurenci organiczni (agregacja ~250 tys. wyników najnowszych pomiarów 2500 fraz: `t` ref → `r` ref PRIMARY,
  grupowanie po pomiarze i domenie, potem po domenie — bez pełnych skanów, ale z tabelą tymczasową). Wynik strony jest zapamiętywany
  (transient z kluczem zależnym od stanu pomiarów — nowy pomiar, dodanie lub usunięcie frazy zmienia klucz); pierwsze wejście po pomiarze
  ~0,24 s dla 500 fraz i ~1,2 s dla 2500 fraz. Przy dziesiątkach tysięcy fraz w projekcie: zestawienie wyliczane w tle po odbiorze wyników.
- Kod nie zakłada 500 fraz: liczba 500 występuje tylko jako domyślny miękki limit (`OSF_SEO_SERP_MAX_KEYWORDS`) i jako rozmiar paczek SQL.

### 13.15 Bezpieczny smoke test na stagingu (1 konkurent, 1 fraza, TOP100)

Wykonuje osoba z dostępem do stagingu — nie agent. Zakłada wdrożony kod STEP 14 (plugin 0.14.0, schemat 8), stałe DataForSEO w
`wp-config.php` i projekt na rynku Polska/polski. Pierwszy krok jest zawsze bezpłatny.

1. `wp osf-seo status` (schemat 8, `dataforseo: configured`), `wp osf-seo dataforseo:status --project=<id>` (koszty dziś/miesiąc, limity).
2. `wp osf-seo competitors:add --project=<id> --domain=<konkurent.pl> --user=<admin>` — 1 konkurent (bez API).
3. `wp osf-seo serp:track --project=<id> --keywords="<fraza>" --user=<admin>` — 1 fraza (bez API, bez wzbogacania).
4. **Bezpłatny podgląd**: `wp osf-seo serp:plan --project=<id>` (albo panel → Pozycje → „Sprawdź pozycje teraz”) — oczekiwane: 1 zadanie,
   1 zlecenie, Google Organic, PL/pl, desktop, TOP100, Standard, **szacowany maksymalny koszt 0,00465 USD**, `api_requests: 0`.
5. `wp osf-seo serp:run --project=<id> --user=<admin>` — ten sam plan, potwierdzenie, jedno zlecenie (automatyczny harmonogram pozostaje wyłączony).
6. Po kilku minutach: `wp osf-seo serp:collect`, `wp osf-seo serp:status --project=<id> --format=json` — porównać `estimated_cost` z `cost`
   (zgłoszonym) pomiaru i zlecenia (`dataforseo:status`, „Dane rynkowe” → Pozycje SERP) oraz z panelem DataForSEO. Rozbieżność zapisać —
   stałych ceny nie zmieniamy po jednym teście.
7. Sprawdzić panel: Pozycje (Pozycja SERP obok średniej pozycji GSC), szczegóły frazy (pełne TOP100, projekt i konkurent wyróżnieni,
   wyróżniony fragment osobno), Konkurenci i organiczni, `serp:snapshot` (pełne TOP100 w CLI).
8. Opcjonalnie druga fraza: `serp:track` → `serp:plan` (1 zadanie — pierwsza fraza pominięta przez okno 6 h) → `serp:run`. Dopiero po
   weryfikacji kosztów rozważyć więcej fraz i włączenie harmonogramu (panel → Ustawienia pomiarów, potwierdzenie kosztu miesięcznego).

### 13.16 Ograniczenia

- Cena i parametry zweryfikowane pośrednio (dokumentacja i SDK dostawcy, 13.2); lokalne limity są bezpiecznikiem, nie rozliczeniem.
- Pozycja SERP to pojedyncza obserwacja z lokalizacji rynku (kraj, nie miasto) i jednego urządzenia; wyniki Google są personalizowane i zmienne.
- Zapisujemy wyniki organiczne i wyróżnione fragmenty; pozostałe elementy strony (reklamy, mapy, „Ludzie pytają też”, AI Overview) tylko jako
  maska obecności — bez treści i bez płatnych parametrów.
- Wolumen i KD monitorowanej frazy pojawiają się, gdy fraza trafi do danych rynkowych STEP 12/13 (brak osobnego wzbogacania).
- Bez automatycznej retencji historii (świadomie; rozmiar w 13.14). Tło zależy od crona (jak kolejka GSC); `serp:run`/`serp:collect` działają od razu.

## 14. Luki SEO (STEP 15)

Zaimplementowane w STEP 15 (`plugins/osf-seo/src/Gap`, `src/DataForSeo/DataForSeoRankedKeywordsProvider.php`, migracja M0009 — schemat 9;
UI: `GapsController`, `GapKeywordsController`, `GapContentController`, moduł „Luki SEO”; CLI `wp osf-seo gap:*`). Cel: frazy, na które
rankują konkurenci projektu, a projekt nie albo słabiej (**Keyword Gap** — „Luki fraz”), oraz grupy takich fraz, dla których projekt może
nie mieć przekonującej strony (**Content Gap** — „Luki treści”). W nowych tekstach UI i dokumentacji produkt nazywa się **Whack-a-mole**;
identyfikatory techniczne (`osf-seo`, `OsfSeo\`, `osf_*`, `osf_seo_*`, `wp osf-seo`) pozostają bez zmian.

### 14.1 Zasady

- **Keyword Gap i Content Gap to osobne pojęcia.** Luka frazy to porównanie pozycji konkurenta z widocznością projektu dla jednej frazy;
  luka treści to heurystyka dla grupy fraz (14.10) — osobne listy, etykiety i statusy pracy.
- **Pozycja konkurenta pochodzi z bazy DataForSEO Labs** (Ranked Keywords — migawka Google z datą przy frazie), nie z naszego pomiaru SERP.
  W UI zawsze „Pozycja (Labs)” albo „#N (Labs)”; dokładny ranking mierzy moduł „Pozycje” (STEP 14 pozostaje jedynym monitoringiem pozycji).
- **Hierarchia dowodów widoczności projektu (D46)**: świeży pomiar SERP (STEP 14) → średnia pozycja (GSC) → punkt odniesienia DataForSEO Labs.
  **Sam brak frazy w GSC nigdy nie oznacza braku widoczności** — bez innego dowodu fraza jest „Nieznana”.
- **Płatne żądania wyłącznie z `GapImporter`** — w kroku w tle (`GapService::runBackground`, WP-Cron / `sync:run`, po Nowych frazach)
  albo z `wp osf-seo gap:run`, pod wspólną blokadą `MarketSyncService::LOCK` i wspólnymi limitami kosztów (zadania w `market_tasks`,
  `trigger_type = gap`, endpoint `labs_ranked_keywords`). Kontroler, widok i raport nigdy nie wysyłają płatnego żądania: plan i podgląd kosztu
  są lokalne, „Uruchom import” tylko kolejkuje potwierdzony plan.
- **Globalne limity DataForSEO bez zmian** (1 USD dziennie, 10 USD miesięcznie — decyzja STEP 15: podniesienie dopiero po realnym smoke
  teście). Import większy niż dzienny limit rozkłada się na kolejne dni (pauza i automatyczne wznowienie, 14.5).
- **Brak automatycznego startu.** Wdrożenie niczego nie pobiera; import uruchamia człowiek (panel, CLI), a harmonogram odświeżania jest
  domyślnie wyłączony i włączany tylko z potwierdzeniem szacowanego kosztu miesięcznego.
- Priorytet luki to **sygnał do sprawdzenia** (nigdy prognoza ruchu ani wartość biznesowa); luka treści to **heurystyka** (nigdy twierdzenie,
  że projekt „potrzebuje nowej strony”). Bez AI, embeddingów, crawla konkurencji, backlinków i generowania treści.

### 14.2 Endpoint, stronicowanie i ceny (D43, D44)

| Element | Wartość |
|---|---|
| Żądanie | `POST dataforseo_labs/google/ranked_keywords/live` — jedna domena, jedna strona wyników (do **1 000** fraz) |
| Treść | `target` (domena bez schematu i `www.`), `location_code`, `language_code`, `item_types: ["organic"]`, `historical_serp_mode: "live"`, `ignore_synonyms: false`, `load_rank_absolute: false`, filtry `ranked_serp_element.serp_item.rank_group <= N` i `keyword_data.keyword_info.search_volume >= M`, `order_by` wolumen malejąco + pozycja rosnąco, `limit`, `offset` |
| Odpowiedź | `total_count`, `items[]` (fraza z metrykami: wolumen, CPC, konkurencja, KD, intencja, `core_keyword`, inny język; element SERP: `rank_group`, `rank_absolute`, URL, tytuł, `etv`, data aktualizacji) |
| Odrzucane wiersze | inne niż organiczne, z innej domeny niż cel (host spoza rodziny domeny), bez prawidłowej frazy lub pozycji; duplikat frazy na stronie — najlepsza pozycja |
| Cena | `OSF_SEO_DATAFORSEO_PRICE_GAP_REQUEST` = 0,012 USD za żądanie + `OSF_SEO_DATAFORSEO_PRICE_GAP_ITEM` = 0,00012 USD za zwróconą frazę; pełna strona 0,132 USD, 10 000 fraz domeny = **1,32 USD** |

**Stronicowanie (D44) — wyłącznie potwierdzony mechanizm `limit` + `offset`.** Specyfikacja OpenAPI i oficjalne SDK DataForSEO
dokumentują dla Ranked Keywords `limit` (maks. 1000) i `offset` z ograniczeniem `offset + limit ≤ 10 000`; nie korzystamy z
niepotwierdzonych obejść (np. „okien wolumenowych” czy `offset_token`). Dlatego **maksymalnie 10 000 fraz na domenę i zakres**
(`MAX_ROWS_PER_DOMAIN`); większe domeny są **przycinane** do najmocniejszych fraz (wolumen malejąco). Bezpieczeństwo danych:

- `covered_min_volume` — dolna granica wolumenu, dla której nieobecność frazy jest wiarygodna: import kompletny → minimalny wolumen zakresu;
  import przycięty albo niepełny → wolumen ostatniej pobranej frazy + 1 (frazy o tym samym wolumenie mogły trafić na następną stronę);
- **zapas wolumenu** (`GapConfig::ABSENCE_VOLUME_MARGIN` = 1,5): filtr dostawcy działa na wolumenie z bazy Labs w chwili importu, a nasz wolumen
  frazy bywa z innego miesiąca lub źródła — brak frazy jest wiarygodny dopiero od `ceil(covered_min_volume × 1,5)` (bez filtra wolumenu: od 0);
- **niespójne strony** (przesunięcie danych u dostawcy w trakcie stronicowania) → nieobecność w ogóle niewiarygodna (`covered_min_volume = NULL`),
  powód w `gap_run_targets.unreliable`: `duplicates` (fraza powtórzona na kolejnej stronie), `total_changed` (inny `total_count` niż na poprzedniej
  stronie), `short_page` (mniej fraz niż `limit`, choć `total_count` zapowiada kolejne), `order` (pierwsza fraza strony z wolumenem większym niż
  ostatnia poprzedniej), `unreadable` (wynik bez frazy lub pozycji — nie wiemy, której frazy dotyczy), `empty` (pusta odpowiedź dla zbioru, który
  miał frazy — utrata wszystkiego dopiero po drugiej pustej odpowiedzi z rzędu);
- „Brak widoczności” z punktu odniesienia i zdarzenie „lost” tylko w wiarygodnym zakresie — nigdy z przyciętej części ani tuż nad granicą.

### 14.3 Model danych (M0009)

Migracja `M0009CreateKeywordGap` (schemat 9) — tylko nowe tabele i nowe kolumny (bez usuwania danych):

- `market_keywords`: + `core_key` BINARY(16) (grupa synonimów dostawcy), `other_language` (fraza w innym języku według dostawcy);
  `serp_competitors`: + `brand_terms` (warianty marki konkurenta); `serp_tracked_keywords.source`: + `gap`.
- **`osf_gap_domains`** — wspólny zbiór domeny na rynku: UNIQUE (`provider`, `location_code`, `language_code`, `domain_key`); stan
  (`empty`, `importing`, `ready`, `partial`), zakres ostatniego importu (`coverage_max_rank`, `coverage_min_volume`, `coverage_max_rows`),
  `complete`, `covered_min_volume`, `total_count`, `rows_present`, `labs_updated_at`, `import_run_id`, `imported_at`, `stale_after`.
- **`osf_gap_domain_keywords`** — PK (`domain_id`, `market_keyword_id`): `rank_group`, `rank_absolute`, `url_id` (słownik `serp_urls`), `etv`,
  `serp_on` (data migawki Labs), `first_seen`, `last_seen`, `seen_run_id`, `prev_rank`, `changed_on`, `present` (1 — w zakresie ostatniego
  importu, 0 — utracona, 2 — niepotwierdzona: brak w ostatnim imporcie tuż nad granicą wolumenu, bez zdarzenia); indeks `domain_url`.
- **`osf_gap_domain_pages`** — tytuły stron konkurentów (PK `domain_id`, `url_id`).
- **`osf_gap_domain_events`** — historia zbioru (14.12): PK (`domain_id`, `market_keyword_id`, `run_id`, `event`), indeks `domain_run`.
- **`osf_gap_runs`** / **`osf_gap_run_targets`** — przebiegi importu (stan, zakres, liczby, koszty, powód blokady; UNIQUE
  `active_project_id` = jeden aktywny przebieg na projekt) i domeny przebiegu (stan, `next_offset`, strony, frazy, żądanie w locie, próby,
  błąd, powód niewiarygodnej nieobecności `unreliable` (14.2), termin ponowienia, statystyki).
- **`osf_gap_settings`** — ustawienia projektu (próg znaczącej pozycji konkurenta, min. wolumen, maks. KD, słowa tematyczne, marka projektu,
  domyślny zakres importu, odświeżanie, harmonogram) i `data_key` (klucz danych przeliczenia).
- **`osf_gap_keywords`** — luka frazy projektu: UNIQUE (`project_id`, `market_keyword_id`), `public_id` utf8mb4_bin; status pracy i notatka;
  `active`, `listed`, `filter_reason`; typ luki, widoczność i jej źródło, pozycje (SERP, GSC, Labs), konkurenci (liczba, TOP10, najlepszy,
  pozycja, adres), metryki, strona docelowa, grupa i luka treści, priorytet i jego składniki. Indeksy `project_list`
  (`project_id`, `listed`, `active`, `gap_type`, `status`, `priority` — pokrywa domyślną listę), `project_volume`, `project_cluster`.
- **`osf_gap_clusters`** — grupy fraz (lider, etykieta, liczby, wolumen luk, konkurenci i ich podstrony, luka treści z powodem i pewnością,
  strona docelowa, priorytet, status pracy, notatka); **`osf_gap_competitor_pages`** — strony konkurencji projektu (agregaty).

Kolumny tekstowe `osf_gap_keywords` są w utf8mb4 (bez ascii): `$wpdb` traktuje tabelę mieszającą ascii i utf8mb4 bez kolumny binarnej
jako ASCII i odrzuca wyszukiwanie z polskimi znakami. Tabele Luk SEO nie należą do `GscDataStore::DATA_TABLES` — reset property ich nie usuwa
(luki przeliczają się z nowych danych GSC).

### 14.4 Wspólne zbiory domen i zakres (D45)

- Zbiór domeny jest wspólny dla wszystkich projektów na tym samym rynku (domena × lokalizacja × język × dostawca). Świeży zbiór (do
  `OSF_SEO_GAP_TTL_DAYS`, domyślnie 30 dni), który **obejmuje** żądany zakres (TOP N ≥, min. wolumen ≤, limit fraz ≥ albo zbiór kompletny),
  jest używany z pamięci — **bez opłaty**, także przez inny projekt. Węższy zakres mieści się w szerszym; szerszy wymaga importu.
- **Równoległe projekty**: zbiór importuje naraz tylko jeden przebieg — atomowe przejęcie (`GapDomainRepository::claimImport`: `status =
  importing`, `import_run_id`), wszystkie strony pod wspólną blokadą płatnych żądań (`MarketSyncService::LOCK`). Przebieg innego projektu z tą
  samą domeną nie wysyła żądań, dopóki import trwa (także wstrzymany limitem kosztów), a po jego zakończeniu korzysta ze zbioru bez opłaty, jeśli
  zakres wystarcza; z `force` — gdy zbiór zaimportowano już po zleceniu tego przebiegu (dane są co najmniej tak świeże, jak żądane).
- Plan: domena importowana właśnie przez inny przebieg z zakresem obejmującym żądany → „czeka na trwający import innego projektu (zwykle bez
  kosztu)”: oczekiwany koszt 0, ale **koszt maksymalny planu obejmuje jej import** (gdyby tamten się nie udał, ten przebieg pobierze zbiór sam,
  w granicach potwierdzonego maksimum). Trwający import węższego zakresu → zwykły import z kosztem.
- Domyślny zakres (decyzja STEP 15): **TOP30, wolumen ≥ 10, maks. 10 000 fraz na domenę**. Presety: szybki (TOP10, ≥ 50, 2 000), standardowy
  (TOP30, ≥ 10, 10 000), pełny (TOP100, każdy wolumen, 10 000) albo własny.
- **Punkt odniesienia projektu** (decyzja STEP 15): frazy domeny projektu z Ranked Keywords w TOP100, z tym samym minimalnym wolumenem i limitem
  fraz co konkurenci — pozwala odróżnić „Brak widoczności” od „Nieznanej” dla fraz bez GSC i bez pomiaru SERP. Można go wyłączyć w imporcie.

### 14.5 Import: plan, kolejka, limity kosztów, błędy (D47)

1. **Plan bez API** (`GapPlanner`): aktywni konkurenci projektu (bez domeny projektu i duplikatów) + punkt odniesienia; dla każdej domeny stan
   (z pamięci / czeka / import / odświeżenie), znana liczba fraz z poprzedniego importu o tych samych filtrach, maks. żądań, **koszt maksymalny**
   (pełny limit fraz) i **oczekiwany** (znana liczba fraz). Pozostały limit dzienny i miesięczny.
2. **Uruchomienie**: plan przeliczany ponownie; większy lub droższy niż potwierdzony podgląd → „plan się zmienił”. Blokada startu, gdy
   oczekiwany koszt przekracza pozostały limit miesięczny albo limit dzienny = 0; mniejszy limit dzienny tylko ostrzega (import potrwa kilka dni).
   Jeden aktywny przebieg na projekt (UNIQUE), ręczny start raz na 60 s, pauza konta DataForSEO blokuje start.
3. **Wykonanie w tle**: strona po stronie, najwyżej `OSF_SEO_GAP_MAX_REQUESTS_PER_TICK` (10) żądań na krok, **limit kosztów sprawdzany przed
   każdą stroną**. Limit dzienny lub miesięczny → przebieg `paused` (pobrane strony zostają) i wznawia się sam, gdy limit się odnowi; wstrzymany
   dłużej niż 7 dni → zakończony częściowo.
4. **Błędy**: niepewne (sieć, timeout, 5xx, niepoprawna odpowiedź) — **bez ponawiania** (żądanie mogło zostać opłacone), koszt szacowany w
   rejestrze, domena niepełna; limit żądań dostawcy → ponowienie po 5 min (maks. 3 próby); błąd konta (logowanie, środki) → wspólna pauza
   płatnych wywołań wszystkich modułów; żądanie w locie starsze niż godzina (proces padł) → domena niepełna bez ponawiania.
5. **Anulowanie**: bez kolejnych stron; rozpoczęty import zbioru domykany jako niepełny (pobrane strony zostają) — od razu, jeśli żaden proces
   nie wysyła właśnie płatnych żądań, inaczej przez ten proces po bieżącej stronie albo utrzymanie w kolejnym kroku tła. **Utrzymanie**
   (żądania przerwane, wygasłe wstrzymania, domknięcie anulowanych) działa wyłącznie pod wspólną blokadą płatnych żądań — nigdy w trakcie
   zapisu strony przez inny proces (np. `wp osf-seo gap:run`).
6. **Odświeżenie przerwane, wstrzymane albo niespójne nie traci poprzedniego zbioru**: strony są tylko dopisywane i aktualizowane (nowsze
   obserwacje), utrata fraz (`lost`) zapisywana wyłącznie po zakończonym, spójnym imporcie. Odświeżenie anulowane, nieudane, wygasłe albo
   niespójne (14.2) kończy domenę jako `partial`, a zbiór zachowuje zakres, kompletność, `covered_min_volume`, datę i świeżość poprzedniego
   udanego importu (kolejny plan pobierze go ponownie). Pierwszy import niespójny zapisuje pobrane frazy bez wiarygodnej nieobecności.
7. Koszt każdego żądania w `market_tasks` (koszt zgłoszony przez dostawcę rozstrzyga) — w „Danych rynkowych” kategoria „Luki SEO”.
8. Po zakończeniu przebiegu luki projektu są przeliczane (14.14).

### 14.6 Widoczność projektu — hierarchia dowodów (D46)

`VisibilityResolver`, w tej kolejności:

1. **Pomiar SERP** (STEP 14), jeśli fraza jest monitorowana i pomiar ma do `OSF_SEO_GAP_SERP_FRESH_DAYS` (30) dni: znaleziona → widoczna
   (pozycja ≤ 10) albo słaba; nieznaleziona przy głębokości ≥ 100 → brak.
2. **GSC** (okno `OSF_SEO_GAP_WINDOW_DAYS` = 90 dni, warianty zapisu po kluczu rynkowym): ≥ `OSF_SEO_GAP_MIN_IMPRESSIONS` (10) wyświetleń →
   średnia pozycja `SUM(position_sum) / SUM(impressions)`; > 10 → słaba; ≤ 10, ale wyświetlenia < 10% oczekiwanych z wolumenu → słaba
   (sporadycznie); inaczej widoczna.
3. **Punkt odniesienia Labs**: domena projektu rankuje → widoczna/słaba wg pozycji Labs; nie rankuje → **brak tylko wtedy**, gdy nieobecność
   dowodzi braku widoczności (`GapDomain::provesNoVisibility()`) **i** GSC ma dane projektu z < 10 wyświetleniami frazy. Nieobecność dowodzi
   braku widoczności tylko w zbiorze obejmującym **pełne TOP100** (zbiór domeny projektu pobrany jako konkurent innego projektu w TOP30 nie
   wyklucza pozycji 31–100), ze spójnego importu (14.2) i dla frazy o znanym wolumenie co najmniej `covered_min_volume` z zapasem — także przy
   imporcie przyciętym limitem 10 000 fraz albo przerwanym (granica = wolumen ostatniej pobranej frazy + 1). Fraza niepotwierdzona (`present = 2`)
   to brak frazy.
4. W pozostałych przypadkach **Nieznana** — panel frazy podaje powód niepewności (`GapDomain::absenceDoubt()`).

### 14.7 Typ luki

C — najlepsza pozycja konkurenta (Labs) w progu znaczącej pozycji (domyślnie TOP20, ustawienie projektu); P — pozycja projektu z wybranego dowodu.
Kolejność (`GapClassifier`): widoczność nieznana → **Nieznana**; brak → **Brak widoczności**; sporadyczna → **Słaba widoczność**; P < C →
**Projekt silniejszy**; P ≤ 10 albo P − C < 5 → **Porównywalna**; inaczej → **Słaba widoczność**. Domyślna lista „Do sprawdzenia” = brak,
słaba, nieznana; porównywalne i silniejsze są widoczne po zmianie filtra.

### 14.8 Filtry — frazy zostają, tylko nie są lukami

`listed = 0` z powodem (filtr „Odfiltrowane”), w kolejności: **marka projektu** i **marka konkurenta** (`BrandMatcher`: warianty podane przez
użytkownika pasują zawsze; warianty automatyczne z domeny i nazwy — gdy wariant wielowyrazowy występuje jako jeden wyraz albo fraza zawiera
wariant i dostawca oznaczył intencję nawigacyjną — dzięki temu domena-fraza nie ukrywa fraz branżowych), **wykluczenia projektu** (wspólne
z Nowymi frazami), **słowa tematyczne** (jeśli podane — tylko frazy z nimi), **minimalny wolumen**, **maksymalna trudność SEO**. Frazy, na
które żaden aktywny konkurent już nie rankuje w progu, są **nieaktualne** (`active = 0`) — status pracy i notatka zostają, a fraza wraca
z tym samym identyfikatorem, gdy dowód się pojawi.

**Inny język nie jest filtrem** (poprawka po pierwszym smoke teście): DataForSEO Labs oznacza `is_another_language` każdą frazę w innym języku
niż język rynku, a polscy użytkownicy wpisują też frazy angielskie („wordpress developer”, „heatmap”, „uxui designer”). Informacja zostaje
w `market_keywords.other_language` i jest pokazywana przy frazie jako „inny język” (lista luk, szczegóły, CLI `other_language`). Ustawienia
wykluczającego inne języki nie ma — do pomijania pojedynczych fraz służą wykluczenia projektu. Zmiana reguły podniosła wersję przeliczenia
(`GapRefresher::VERSION`), więc po wdrożeniu luki przeliczają się lokalnie w tle z zapisanych zbiorów — bez żadnego żądania do DataForSEO.

Fraza zbioru konkurenta, na którą konkurent rankuje poniżej progu **znaczącej pozycji** (ustawienia, domyślnie TOP20; import pobiera domyślnie
TOP30), nie jest luką i nie trafia do „Odfiltrowanych” — liczba fraz zbioru może więc być większa niż suma luk i odfiltrowanych. Zmiana progu
w ustawieniach to wyłącznie lokalne przeliczenie.

### 14.9 Priorytet luki (D48)

0–100, `GapScorer` (składniki ograniczone; `L(x, cap) = min(1, log10(1 + x) / log10(1 + cap))`):

| Składnik | Punkty |
|---|---|
| Popyt | 25 × L(wolumen, 10 000); brak wolumenu = 0 |
| Osiągalność | 15 × (1 − KD/100); brak KD = 7,5 |
| Siła sygnału | 12 × f(C) + 8 × min(1, (n − 1)/3); f: C 1–3 = 1; 4–10 = 0,85; 11–20 = 0,6; 21–30 = 0,3; dalej 0,15; n — konkurenci w progu |
| Luka | brak 30; słaba 30 × max(0,4; min(1; (P − C)/30)); sporadyczna 15; nieznana 15; porównywalna 4; silniejszy 0 |
| Intencja | transakcyjna/komercyjna 5, informacyjna 3, nawigacyjna 0, brak 2,5 |
| Sygnał komercyjny | 5 × L(CPC, 10 USD) — mała waga |

Mnożnik: porównywalna × 0,6, projekt silniejszy × 0,3. Priorytet grupy = 0,7 × najwyższy priorytet frazy + 30 × L(wolumen luk grupy, 50 000).
W szczegółach luki widoczne jest rozbicie na składniki.

### 14.10 Luka treści — heurystyka (D49)

Dla każdej grupy (14.11), `ContentGapClassifier` — bez AI, z powodem i pewnością (niska / średnia / wysoka):

- **Strona docelowa projektu**: pomiar SERP (najczęstszy adres projektu w grupie) → GSC (strona z ≥ 60% wyświetleń grupy; druga z ≥ 20% →
  wyświetlenia **rozproszone**) → punkt odniesienia Labs (adres ważony wolumenem) → adres projektu pasujący słowami do frazy wiodącej (≥ 60%).
- Brak danych projektu → **Niejasne**; rozproszone → **Niejasne**.
- Strona istnieje: fraza wiodąca porównywalna/silniejsza i < 50% fraz z luką → **Bez luki treści**; jedyną stroną jest strona główna, a
  konkurenci rankują podstronami → **Potencjalna luka treści**; inaczej → **Istniejąca strona — do wzmocnienia**.
- Brak strony: konkurenci rankują podstronami i wolumen luk ≥ 50 → **Potencjalna luka treści**; tylko stronami głównymi → **Niejasne**;
  inaczej → **Niejasne** (mały popyt).

UI zawsze opisuje wynik jako heurystykę do sprawdzenia, nigdy jako diagnozę.

### 14.11 Grupowanie fraz

`KeywordClusterer` — deterministyczne, wokół lidera (bez embeddingów): frazy z listy (najwyżej `OSF_SEO_GAP_MAX_CLUSTER_KEYWORDS` = 20 000
według priorytetu) w kolejności wolumen malejąco; fraza dołącza do pierwszej grupy, której **lider** jest powiązany: co najmniej 2 wspólne adresy
konkurentów (albo wspólny jedyny adres), ta sama grupa synonimów dostawcy (`core_keyword`), ta sama strona docelowa projektu, ten sam zbiór
wyrazów (bez kolejności i diakrytyków) albo ≥ 4 wspólne adresy w TOP10 naszych migawek SERP. Strony główne i „huby” (> max(300, 20%) fraz
zbioru) nie łączą fraz. Bez stemmingu. **Stabilne identyfikatory**: nowa grupa przejmuje identyfikator i status pracy starej, jeśli co najmniej
połowa jej fraz należała do tej samej starej grupy; grupy bez następcy stają się nieaktywne.

### 14.12 Historia zbiorów domen (D50)

Od **drugiego** importu zbioru zdarzenia na frazę: `new`, `lost` (tylko po zakończonym, spójnym imporcie, w TOP N importu i z wolumenem
z zapasem nad granicą; tuż nad granicą fraza staje się niepotwierdzona — bez zdarzenia, bez późniejszego `back`), `back`, `url`
(inny adres), `up`/`down` (zmiana o ≥ 5 pozycji albo przejście progu TOP3/10/20/50/100). To zmiany w bazie Labs między importami — **nie drugi
rank tracker**: nie liczymy z nich trendów pozycji ani alertów; właściwy monitoring pozycji to moduł „Pozycje” (STEP 14). Historia importów
domeny (liczby fraz, nowe/utracone, koszt) w `gap_run_targets`.

### 14.13 Strony konkurencji

Agregaty z zaimportowanych fraz aktywnych konkurentów (`gap_competitor_pages`): liczba fraz, TOP3/10/20, wolumen, `etv`, najlepsza pozycja,
liczba i wolumen fraz będących lukami projektu, frazy wspólne (projekt porównywalnie albo wyżej), główna intencja, grupa. Szczegóły strony:
frazy strony z widocznością projektu.

### 14.14 Przeliczenie (bez API)

`GapRefresher` przelicza luki projektu z zapisanych danych: po imporcie, w kroku w tle (projekty ze zmienionym **kluczem danych**: ustawienia
analizy, konkurenci i ich marki, stan zbiorów, wykluczenia, ostatnia data GSC, pomiary SERP, konfiguracja; plus raz dziennie — metryki
rynkowe) i z `wp osf-seo gap:recalculate`. Przycisk „Przelicz” w panelu tylko unieważnia klucz — przeliczenie wykonuje najbliższy krok tła
(pełne przeliczenie dużego projektu trwa kilkanaście sekund, 14.19). Nigdy przy renderowaniu strony.

### 14.15 Harmonogram i koszty

Harmonogram odświeżania (domyślnie wyłączony): co `refresh_days` (domyślnie 30) import nieświeżych zbiorów w domyślnym zakresie projektu — tylko
gdy **koszt oczekiwany** (liczba fraz z poprzedniego importu o tych samych filtrach; dla nowej domeny — maksimum) mieści się w dzisiejszym
i miesięcznym limicie; inaczej pominięcie z powodem i ponowienie następnego dnia. Limit jest i tak sprawdzany przed każdą stroną (pauza).
Projekty wstrzymane i w archiwum są pomijane (jak synchronizacja GSC).
Włączenie wymaga potwierdzenia szacowanego kosztu miesięcznego (plan przeskalowany do 30 dni). Koszt pierwszego importu dla jednego konkurenta
w domyślnym zakresie: maks. 10 stron = **1,32 USD**, a dla domeny z np. 2 000 frazami 2 strony ≈ 0,26 USD; punkt odniesienia projektu — tyle samo.

### 14.16 UI

Moduł „Luki SEO” (menu projektu): **Przegląd** (liczniki luk, wysoki priorytet, nowe od ostatniego importu, najważniejsze luki fraz i treści,
stan zbiorów domen z datą danych Labs, ostatnie importy), **Luki fraz** (filtry: typ, konkurent, widoczność, luka treści, status, intencja,
wolumen, KD, priorytet, odfiltrowane z powodem; sortowanie; zmiana statusu zbiorczo; „Monitoruj pozycję”), **szczegóły luki** (rozbicie
priorytetu, dowody projektu: pomiar SERP, GSC z wariantami i stronami, punkt odniesienia; pozycja każdego konkurenta z datą Labs i naszym
pomiarem SERP, grupa i luka treści, historia zbiorów, powiązane Nowe frazy i Szanse SEO, status i notatka), **Luki treści** (grupy z heurystyką
i pewnością, szczegóły z frazami i stronami konkurentów), **Strony konkurencji**, **Import** (domeny, presety zakresu, punkt odniesienia,
„Pobierz ponownie”, bezpłatny podgląd kosztu, potwierdzenie, postęp, anulowanie) i **Ustawienia** (progi analizy, domyślny zakres, harmonogram,
warianty marki projektu i konkurentów). Integracje: „Pozycje” (źródło „z Luk SEO”), „Dane rynkowe” (kategoria Luki SEO), karta konkurenta.
Komponenty `x-panel.gap-type`, `x-panel.content-gap`, `x-panel.project-visibility`. Klient: tylko odczyt, bez kosztów.

### 14.17 CLI

```
wp osf-seo gap:plan --project=<id> [--preset=quick|standard|full] [--max-rank=…] [--min-volume=…] [--max-rows=…] [--competitors=…] [--no-baseline] [--force] [--format=json]   # zero żądań
wp osf-seo gap:run --project=<id> [opcje planu] [--yes] [--queue-only]   # PŁATNE — tylko na polecenie użytkownika
wp osf-seo gap:status|cancel|recalculate --project=<id> …                 # stan, anulowanie (--run=<id>), przeliczenie (bez API)
wp osf-seo gap:list|keyword|content|pages --project=<id> …               # luki fraz, szczegóły, luki treści, strony konkurencji (bez API)
wp osf-seo gap:set-status --project=<id> --ids=… --status=… [--kind=keyword|cluster] [--note=…]
wp osf-seo gap:settings --project=<id> [--competitor-max-rank=…] [--min-volume=…] [--max-kd=…] [--include=…] [--brand=…] [--enable-schedule --yes|--disable-schedule]
wp osf-seo gap:brand --project=<id> --competitor=<id> --terms="…"        # warianty marki konkurenta (bez API)
```

Wyjście po angielsku; `--format=json` bez dodatkowych komunikatów.

### 14.18 Uprawnienia i bezpieczeństwo

- Capability `osf_seo_manage_keyword_gap` (wersja 0.15.0): plan i koszt, import, anulowanie, przeliczenie, ustawienia, harmonogram, warianty
  marki, status i notatki. Odczyt (luki, grupy, strony, postęp bez kosztów) — każdy, kto widzi projekt.
- Przebieg, luka, grupa i konkurent z URL-a szukane wyłącznie po (`project_id` z `ProjectContext`, `public_id`); strona konkurenta po
  (`project_id`, konkurent projektu, klucz adresu) — obce ID → 404 (testy IDOR); zmiany statusu cudzych ID → 0 zmian.
- Mutacje: nonce + zgodny Origin + capability (trasa `ResolveProject` i kontrola w `GapService`); uruchomienie tylko z potwierdzonym planem
  (`expected_requests`, `expected_cost`). Frazy, tytuły i URL-e od dostawcy escapowane; linki tylko `http(s)` z `rel="noopener noreferrer"`.
- Dane logowania DataForSEO jak w STEP 12 (tylko `wp-config.php`/env, Basic Auth budowany w chwili żądania, nigdy w logach ani HTML).

### 14.19 Wydajność (pomiar)

`composer test:performance:gap` (MariaDB 10.11, syntetyczne dane, osobna baza testowa; bez żadnego żądania do DataForSEO):

Dane: 40 wspólnych zbiorów konkurentów × 10 000 fraz (TOP30) z puli 60 000 fraz rynkowych, 20 projektów × 5 konkurentów, punkt odniesienia
2 000 fraz na projekt (TOP100); projekt nr 1: GSC 5 000 fraz × 30 dni w oknie 90 dni ze stronami, 500 monitorowanych fraz SERP.
W tabelach: 450 000 wierszy zbiorów (54 MB z indeksami), 537 000 luk fraz 20 projektów (255 MB), 34 000 grup, 30 000 stron konkurencji.

| Operacja | Wynik |
|---|---|
| Pełne przeliczenie projektu (5 × 10 000 fraz → 26 753 frazy konkurentów, 26 215 luk, 1 648 grup, 1 500 stron) | **12,1 s**, 2 029 zapytań (wsadowo, bez N+1), szczyt pamięci 90 MB; SQL ~9 s (upsert luk ~2,7 s), PHP ~4,5 s |
| Przeliczenie bez zmian danych (klucz danych) | 3,2 ms, 7 zapytań |
| Kolejne projekty na tych samych zbiorach | 11,4 s na projekt |
| Import 10 000 fraz domeny (10 stron po 1 000, atrapa HTTP) + przeliczenie | 17,1 s (import ~5 s), 2 355 zapytań, 95 MB |
| Ponowny import ze zmianami (zdarzenia: lost 439, url 740, up 407, down 951; 61 z 500 usuniętych fraz tuż nad granicą wolumenu → niepotwierdzone) | 16,4 s |
| Luki fraz — lista domyślna / ostatnia strona (25 630 luk) | **24 / 25 ms** (indeks `project_list` pokrywa filtry; najpierw ID strony, potem szczegóły 50 wierszy) |
| Luki fraz — sortowanie po wolumenie / filtr konkurenta (EXISTS) | 47 / 46 ms |
| Luki fraz — wyszukiwanie z min. wolumenem i KD / z polskimi znakami | 102 / 167 ms (`LIKE '%…%'` po frazach projektu) |
| Liczniki przeglądu / szczegóły luki / lista grup / grupa | 71 / 4 / 9 / 115 ms |
| Strony konkurencji: lista / jeden konkurent / strona | 3 / 2 / 12 ms |
| Plan importu bez API (5 domen + punkt odniesienia) | 2 ms |

EXPLAIN (pełny wydruk w wyniku benchmarku): lista i licznik — `range` na `project_list` z „Using index” (bez odczytu wierszy do sortowania);
szczegóły strony — `eq_ref` po PK; filtr konkurenta — półzłączenie z PK `gap_domain_keywords` (`domain_id`, `market_keyword_id`); zapytania
przeliczenia — paczki po 1 000 fraz po PK zbiorów i `market_keywords`, GSC po indeksie `project_market_key`.

Powtórzenie po review importu (claimImport, wykrywanie niespójnych stron, zapas wolumenu): import +5 zapytań (2 360), czasy w granicach
zmienności środowiska (import 20,6 s, pełne przeliczenie 14,4 s przy niezmienionym kodzie przeliczenia), EXPLAIN bez zmian.

### 14.20 Bezpieczny smoke test na stagingu (1 konkurent)

Tylko na wyraźne polecenie właściciela, po wdrożeniu i `wp osf-seo db:migrate` (schemat 9):

1. `wp osf-seo status` (0.15.0, schemat 9), `wp osf-seo dataforseo:status` (konfiguracja, limity 1 / 10 USD bez zmian, brak pauzy).
2. Jeden aktywny konkurent w projekcie (np. z modułu „Konkurenci”); `wp osf-seo gap:plan --project=<id> --preset=quick --no-baseline`
   — zero żądań; sprawdzić maks. koszt (TOP10, ≥ 50, maks. 2 000 fraz → maks. 2 strony = **0,264 USD**).
3. `wp osf-seo gap:run --project=<id> --preset=quick --no-baseline` → potwierdzenie → import (1–2 żądania).
4. Porównać koszt zgłoszony (`market_tasks.cost`, `gap:status`) z szacowanym; sprawdzić `total_count`, liczbę fraz, kompletność zbioru,
   datę danych Labs, `gap:list`, `gap:keyword`, `gap:content` i panel (widoczność „Nieznana” bez punktu odniesienia jest poprawna).
5. Dopiero potem ewentualnie punkt odniesienia projektu (`gap:run --preset=quick` bez `--no-baseline`, kolejne ~0,26 USD) i decyzja o
   domyślnym zakresie oraz limitach kosztów.

### 14.21 Ograniczenia

- **Maks. 10 000 fraz na domenę i zakres** (potwierdzone stronicowanie `limit` + `offset`); większe domeny przycięte do najmocniejszych fraz;
  nieobecność poniżej wolumenu ostatniej pobranej frazy (z zapasem 1,5×) nie jest wiarygodna.
- Wykrywanie niespójnych stron (14.2) opiera się na sygnałach z odpowiedzi; przesunięcia danych u dostawcy, które nie zmienią `total_count`,
  kolejności ani nie zdublują fraz, są niewykrywalne. Ich częstość (szczególnie przy imporcie rozłożonym na kilka dni przez limit kosztów)
  do sprawdzenia na prawdziwym API — powód niewiarygodności widać w `gap:status` i na stronie importu.
- Zbiór pobrany w węższym zakresie niż poprzedni (np. TOP30 po TOP100, gdy szerszy był już nieaktualny) przejmuje węższy zakres; wiersze spoza
  niego zostają z datą migawki Labs, ale nie dają już „Brak widoczności” (wymóg pełnego TOP100).
- Pozycje konkurentów to migawka bazy Labs (data przy frazie), nie bieżący ranking; dla dokładnej pozycji — monitorowanie w „Pozycjach”.
- Bez stemmingu: odmiany fraz łączy dopiero wspólny adres, grupa synonimów dostawcy lub strona projektu.
- Heurystyki (widoczność sporadyczna, luka treści, priorytet) to przybliżenia do kalibracji na prawdziwych projektach (`OSF_SEO_GAP_*`).
- Pełne przeliczenie projektu z 5 konkurentami × 10 000 fraz trwa ~12 s i ~90 MB pamięci (w tle); większe zbiory wymagają optymalizacji
  (przyrostowe przeliczenie) przed skalowaniem na setki projektów.
- Domyślne limity kosztów (1 / 10 USD) pozwalają na ok. 7 pełnych stron dziennie — import 10 000 fraz rozkłada się na 2 dni.

## 15. Strategia i SERP Intelligence (STEP 16)

Architektura zaakceptowana w STEP 16 (z korektami właściciela). Stan: **faza A (fundament) zaimplementowana** — schemat 10,
plugin 0.16.0, kandydaci i dowody per fraza, CLI. Fazy B–F są zaakceptowanym planem (15.10) i powstaną osobnymi, recenzowanymi etapami.
Cel: jedno miejsce „Strategia / Backlog SEO”, które łączy sygnały modułów (GSC, Szanse SEO, Nowe frazy, Luki fraz, Luki treści, Pozycje,
wpisy ręczne) w tematy pracy z dowodami — deterministycznie, bez AI (AI — STEP 17).

### 15.1 Zasady

- **Strategia nie zastępuje modułów.** Szanse SEO, Nowe frazy, Luki SEO i Pozycje pozostają źródłami danych i widokami specjalistycznymi;
  Strategia tylko czyta ich wyniki i decyzje (D51). Nie kopiujemy metryk rynkowych, historii GSC ani pełnych SERP-ów — odwołujemy się do nich.
- **Decyzje modułów są respektowane** (D52): fraza odrzucona w Nowych frazach, luka odrzucona w Lukach SEO albo szansa odrzucona w Szansach
  nie wchodzi do Strategii tym źródłem (może wejść innym — wtedy dowody pokazują decyzję modułu).
- **Brak widoczności nie dowodzi braku strony** (D57): GSC, Labs i SERP pokazują widoczność, nie zawartość witryny. Działanie „create” oznacza
  wyłącznie **„Kandydat na nową stronę”** (do sprawdzenia), nigdy potwierdzony brak strony; wysoka pewność luki strukturalnej wymaga indeksu
  stron projektu (`ProjectPageIndex`, przyszły crawler) albo ręcznego potwierdzenia. Przy niejednoznacznych danych — „investigate”.
- **Płatne żądania** (analiza SERP od fazy B) wyłącznie przez `SerpSubmitter` (STEP 14) — po podglądzie kosztu i jawnym potwierdzeniu, we wspólnych
  limitach kosztów DataForSEO. Strategia nie ma własnego budżetu kosztów ani własnego budżetu czasu tła (D56, D63).
- **Przeliczenie nigdy przy renderowaniu strony** — CLI albo krok w tle (od fazy E), z kluczem danych obejmującym także mutacje ręczne (15.6).

### 15.2 Tożsamość i źródła kandydatów (faza A, D51–D52)

- **Tożsamość frazy** = fraza rynkowa projektu: `market_keywords` (dostawca × lokalizacja × język × klucz) — ta sama, której używają Nowe frazy,
  Pozycje i Luki, a GSC przez `keywords.market_key` (warianty zapisu GSC = jedna fraza). Kandydat = `UNIQUE (project_id, market_keyword_id)`.
  `core_key` (grupa synonimów dostawcy) i `TextFold::tokenKey` to wyłącznie sygnały pomocnicze grupowania (D60), nigdy tożsamość.
  Adaptery czytają tylko frazy rynku projektu — po zmianie kraju lub języka projektu frazy poprzedniego rynku stają się nieaktywne.
- **Źródła** (adaptery `OsfSeo\Strategy\Sources\*`, interfejs `CandidateSource`: odcisk do klucza danych, sygnały członkostwa, dowody):

  | Źródło | Członkostwo (domyślnie) | Poziom | Waga w poziomie |
  |---|---|---:|---|
  | Ręcznie (`manual`) | frazy dodane w Strategii | 0 | — |
  | Pozycje (`serp`) | monitorowane frazy (`serp_tracked_keywords.status = 'active'`) | 1 | — |
  | Szanse SEO (`opportunity`) | frazy grup aktywnych, nieodrzuconych szans (wykrycia z 28 dni, 15.5) | 2 | priorytet szansy |
  | Nowe frazy (`discovery`) | zaakceptowane (poziom 2) oraz nowe / do analizy z priorytetem ≥ `OSF_SEO_STRATEGY_DISCOVERY_MIN_PRIORITY` (poziom 5); bez odrzuconych i wykluczonych | 2 / 5 | priorytet odkrycia |
  | Luki fraz (`gap`) | `listed = 1`, `active = 1`, typ brak / słaba / nieznana, nieodrzucone, priorytet ≥ `OSF_SEO_STRATEGY_GAP_MIN_PRIORITY` | 3 | priorytet luki |
  | Luki treści (`content_gap`) | frazy (aktywne, nieodrzucone) grup „Potencjalna luka treści” / „do wzmocnienia” z pewnością ≥ średnia, nieodrzuconych | 4 | priorytet grupy |
  | GSC (`gsc`) | ≥ `OSF_SEO_STRATEGY_GSC_MIN_IMPRESSIONS` wyświetleń w oknie `OSF_SEO_STRATEGY_WINDOW_DAYS` i średnia pozycja (GSC) ≤ `OSF_SEO_STRATEGY_GSC_MAX_POSITION` | 6 | wyświetlenia |

- Jedna fraza z kilku źródeł = **jeden kandydat** z maską źródeł (`sources`) i dowodami każdego źródła.
- **Filtry** (poza frazami ręcznymi): marka projektu i marki aktywnych konkurentów (`BrandMatcher`, warianty z Luk SEO), wykluczenia projektu
  (wspólne z Nowymi frazami i Lukami SEO). Frazy odfiltrowane i nadmiarowe nie są zapisywane jako aktywne (powód w `inactive_reason`, liczby w stanie).
- **Limit**: `OSF_SEO_STRATEGY_MAX_KEYWORDS` (domyślnie 5000) aktywnych kandydatów na projekt; kolejność: poziom źródła, waga, klucz frazy
  (stabilnie). Nadmiar jest liczony i pokazywany, bez cichego pomijania.

### 15.3 Fakty i dowody per fraza (faza A, D53)

`strategy_keywords` przechowuje **fakty potrzebne do filtrów i późniejszej klasyfikacji** (wartości na chwilę przeliczenia) oraz **dowody**
(`evidence`, wersjonowany JSON z odwołaniami do rekordów modułów):

- GSC (okno 90 dni do ostatniej zaimportowanej daty; warianty frazy po kluczu rynkowym): wyświetlenia, kliknięcia, średnia pozycja (GSC) =
  `SUM(position_sum) / SUM(impressions)`, liczba stron z wyświetleniami, strona z największym udziałem (adres w słowniku `serp_urls`) i jej
  udział; w dowodach do 5 stron. **NULL = brak danych GSC projektu**, 0 = dane są, fraza bez wyświetleń (to nie jest dowód braku widoczności).
  Kompletność okna (pokrycie `query` i `query_page`) jest zapisywana w stanie przeliczenia.
- Pozycje: identyfikator monitorowanej frazy, ostatni pomiar (data, znaleziona, Pozycja SERP, URL), zmiana, przejścia pasm TOP3/10/20, sygnał spadku.
- Luki fraz: typ luki, widoczność ze źródłem (D46), najlepszy konkurent (Labs), strona docelowa ze źródłem, grupa i luka treści.
- Nowe frazy: status, priorytet odkrycia, „Widoczność GSC” (semantyka D35 — nigdy dowód braku widoczności).
- Szanse SEO: powiązane szanse z rodzajem powiązania (15.5).
- Metryki rynkowe (wolumen, KD, CPC, intencja, historia) **nie są kopiowane** — odczyt z `market_keywords`.

Zapis jest przyrostowy: wiersz zmienia się tylko, gdy zmienił się odcisk faktów (`facts_hash`); kandydaci, którzy wypadli, dostają
`active = 0` z aktualnym powodem (`no_source`, `overflow`, `brand_own`, `brand_competitor`, `excluded`, `market_changed`) oraz bez źródeł
i poziomu — identyfikator i wpis ręczny zostają, a fakty i dowody to stan z ostatniego przeliczenia, w którym fraza była kandydatem.
Frazy GSC i członkowie szans SEO bez wiersza rynkowego dostają go przy zapisie (bez danych i bez wzbogacania — jak dodanie do monitorowania).

### 15.4 Model danych (schemat 10, M0010)

- **`osf_strategy_settings`** — PK `project_id`: `revision` (licznik mutacji ręcznych Strategii), `data_key` (BINARY), `refreshed_at`,
  `refresh_ms`, `stats` (JSON: liczby źródeł, odfiltrowane, nadmiar, okno GSC), rynek ostatniego przeliczenia, `updated_by`, `updated_at`.
- **`osf_strategy_keywords`** — kandydat (fraza rynkowa projektu): `public_id` (ULID, **utf8mb4_bin** — kolumna binarna wymagana przez `$wpdb`
  przy wyszukiwaniu z polskimi znakami), `project_id`, `market_keyword_id`, `active`, `inactive_reason`, `sources` (maska), `tier`, wpis ręczny
  (`manual`, `manual_added_by`, `manual_added_at`), fakty GSC (`gsc_impressions`, `gsc_clicks`, `gsc_position`, `gsc_pages`, `gsc_top_url_id`,
  `gsc_top_share`), fakty SERP (`tracked_keyword_id`, `serp_checked_at`, `serp_found`, `serp_rank`, `serp_url_id`), odwołania (`gap_keyword_id`,
  `gap_cluster_id`, `discovery_candidate_id`), `opportunities` (liczba powiązanych szans), `evidence`, `facts_hash`, `first_seen_at`,
  `refreshed_at`, `created_at`, `updated_at`. UNIQUE (`public_id`), UNIQUE `project_market_keyword`, indeks `project_active` (`project_id`, `active`, `tier`).
- Tabele Strategii nie należą do `GscDataStore::DATA_TABLES` — reset property ich nie usuwa (fakty GSC przeliczą się z nowych danych).
- Kolejne fazy dodają **kolejne migracje** (M0011+: tematy, zdarzenia, profile pomiarów SERP, wartości ENUM `analysis` / `strategy`) — migracja,
  która raz została wykonana (także w środowisku deweloperskim), jest niezmienna.

### 15.5 Powiązanie szans SEO z frazami (D54)

Szanse są w większości **per podstrona** (odcisk typ × strona), a kolumna `opportunities.keyword` jest wypełniona tylko dla szans bez danych
query × page — dlatego nie jest podstawą powiązania. `Opportunities\OpportunityKeywordIndex` wiąże szansę z frazą na podstawie danych query × page:

- **członek** (`member`) — fraza należy do grupy szansy (frazy z dowodów i pełna lista z tekstu wyszukiwania wykrycia — przypisanie frazy do
  strony docelowej przez detektor), dla kanibalizacji — frazy pary adresów,
- **ta sama podstrona** (`page`) — fraza ma wyświetlenia w GSC (query × page, okno) na podstronie szansy (adres bez `#fragmentu`, `UrlKey`),
- **fraza** (`keyword`) — szansa na poziomie frazy (bez danych query × page).

Strategia używa członków jako źródła (15.2) i wszystkich powiązań jako dowodów; szczegóły luki w Lukach SEO pokazują szanse powiązane tymi
samymi regułami (poprawka: wcześniej wyłącznie `opportunities.keyword IN (warianty)` — prawie zawsze pusto przy danych query × page).
Lista członków z tekstu wyszukiwania jest ograniczona do 10 000 znaków (bardzo duże grupy mogą być ucięte — powiązanie `page` je uzupełnia).

### 15.6 Aktualność (klucz danych, D53)

Przeliczenie wykonuje się tylko po zmianie **klucza danych** albo z `--force`. Klucz obejmuje: wersję reguł, rynek i dane projektu (nazwa,
domena), dzień (intencja i metryki dostawcy zmieniają się bez importu GSC), stan GSC (ostatnia data, `last_synced_at`, property danych,
pokrycie `query` / `query_page`), konfigurację i **odciski źródeł** — w tym mutacje ręczne we wszystkich modułach: statusy i wykluczenia Nowych
fraz, statusy luk i grup, statusy i stany szans, dodanie i usunięcie monitorowanych fraz oraz nowe pomiary, konkurenci i ich warianty marki,
marka projektu i wykluczenia, `strategy_settings.revision` (wpisy ręczne Strategii). Odcisk źródła to tanie agregaty (liczba, suma kontrolna
`CRC32` statusów, klucz danych modułu) — aktualność nie zależy wyłącznie od czasu ostatniego importu.

### 15.7 Konfiguracja

| Stała | Domyślnie | Zakres | Znaczenie |
|---|---:|---|---|
| `OSF_SEO_STRATEGY_MAX_KEYWORDS` | 5000 | 100–50 000 | maks. aktywnych kandydatów w projekcie |
| `OSF_SEO_STRATEGY_SERP_MAX_PER_RUN` | 100 | 1–1000 | maks. fraz w jednej płatnej analizie SERP (od fazy B) |
| `OSF_SEO_STRATEGY_WINDOW_DAYS` | 90 | 28–480 | okno GSC faktów i źródła GSC |
| `OSF_SEO_STRATEGY_GSC_MIN_IMPRESSIONS` | 50 | 1–100 000 | próg źródła GSC (wyświetlenia w oknie) |
| `OSF_SEO_STRATEGY_GSC_MAX_POSITION` | 50 | 1–100 | próg źródła GSC (średnia pozycja GSC) |
| `OSF_SEO_STRATEGY_DISCOVERY_MIN_PRIORITY` | 50 | 0–100 | Nowe frazy (nowe / do analizy) od tego priorytetu |
| `OSF_SEO_STRATEGY_GAP_MIN_PRIORITY` | 40 | 0–100 | Luki fraz od tego priorytetu |

### 15.8 CLI (faza A, bez żadnego żądania do API)

```bash
wp osf-seo strategy:status --project=<id> [--format=json]       # limity, rynek, ostatnie przeliczenie, aktualność klucza danych, okno GSC
wp osf-seo strategy:preview --project=<id> [--limit=20] [--format=json]   # podgląd kandydatów BEZ zapisu: źródła, filtry, limit, próbka (bez fraz GSC bez klucza rynkowego)
wp osf-seo strategy:refresh --project=<id> [--force] [--format=json]      # materializacja kandydatów, faktów i dowodów (tylko po zmianie klucza)
wp osf-seo strategy:candidates --project=<id> [--source=…] [--status=active|inactive|all] [--search=…] [--sort=…] [--page=<n>] [--per-page=<n>] [--format=json]
wp osf-seo strategy:keyword --project=<id> --keyword=<ULID|fraza> [--format=json]   # fakty i dowody kandydata
wp osf-seo strategy:add|remove --project=<id> --keywords="a, b"   # wpisy ręczne (osf_seo_manage_strategy)
```

### 15.9 Uprawnienia (D62)

- Nowa capability **`osf_seo_manage_strategy`** (wersja 0.16.0; administrator i `osf_seo_admin`): przeliczenie, wpisy ręczne, a od kolejnych faz
  status, notatki, strona docelowa, przypięcia, ustawienia. **Płatna analiza SERP** (faza B) wymaga dodatkowo `osf_seo_manage_serp_tracking`.
- Odczyt: dostęp do projektu (`ProjectGuard` → `ProjectContext`); kandydat z URL-a / CLI wyłącznie po (`project_id`, `public_id`) — obce ID → 404.
- **Klient** (od fazy D, panel): aktywny backlog tylko do odczytu, **bez notatek wewnętrznych i bez odrzuconych tematów**, bez kosztów.

### 15.10 Zaakceptowany plan kolejnych faz

- **B — SERP Intelligence** (D55, D56): interpretacja zapisanych pomiarów STEP 14 (bez nowych tabel wyników; jedna pochodna, niezmienna tabela
  profilu pomiaru): kształty wyników z pewnością (strona główna, dedykowana podstrona, artykuł, listing, produkt, wideo, nieznany — nigdy „strona
  usługowa” jako fakt), kompozycja i koncentracja TOP10/TOP20, obecność i URL projektu, sygnał intencji z SERP (bez nadpisywania intencji dostawcy),
  świeżość (≤ 30 dni pełna, 31–90 dni bez pozycji projektu i z niższą pewnością, > 90 dni nie do klasyfikacji). Jednorazowa analiza = monitorowana
  fraza ze statusem **`analysis`** (poza harmonogramem, listą „Pozycje” i miękkim limitem; pomiar jest dowodem widoczności także dla Luk SEO),
  w kontekście pomiaru projektu, przez `SerpSubmitter` (rezerwacja kosztu, `uncertain`, okno 6 h). Kwalifikacja: domyślnie lider tematu, plus
  frazy potrzebne do potwierdzenia overlapu SERP (wybierane jawnie) — bez automatycznego pomiaru wszystkich członków grupy; maks.
  `OSF_SEO_STRATEGY_SERP_MAX_PER_RUN` fraz na uruchomienie; zawsze podgląd (liczba fraz, ile z istniejącego pomiaru, ile nowych zleceń,
  szacowany maksymalny koszt) i potwierdzenie.
- **C — Rdzeń** (D57–D61): strona docelowa (ręczna → SERP ważony pozycją → GSC ≥ 60% → Labs → slug; stany: potwierdzona, prawdopodobna,
  konflikt, brak znanej strony, nieznana; interfejs `ProjectPageIndex` — dziś strony znane z GSC, w przyszłości crawler/sitemap), sygnały
  konfliktu URL (kanibalizacja jako dowód, nie moduł), działania uporządkowanymi regułami (consolidate → recover → optimize → create jako
  „Kandydat na nową stronę” z twardymi bramkami → monitor → investigate), priorytet 0–100 (ograniczone składniki w skali logarytmicznej ×
  mnożnik pewności), pewność punktowa; tematy: **automatyczne scalanie wyłącznie przy zgodnej stronie docelowej, silnym overlapie SERP
  (≥ 4 wspólne URL-e TOP10 przy świeżych pomiarach) albo decyzji ręcznej** — `tokenKey`, `core_key`, grupy luk i wspólne domeny to sygnały
  pomocnicze („możliwa grupa”); porównanie wyłącznie z liderem (bez łańcuchów), konflikt różnych stron projektu blokuje scalenie; stabilne ID
  tematów (głosowanie ≥ 50%); statusy jak w Szansach SEO; przeliczenie nie zmienia statusu (flagi „zmiana po decyzji”, ponowne otwarcie ręczne);
  zdarzenia tylko istotnych zmian.
- **D — Panel**: moduł „Strategia” (przegląd, backlog, temat z sekcją „Dlaczego to jest w strategii”, SERP Intelligence, ustawienia).
- **E — Tło i wydajność**: krok po kolejce GSC (po kroku SERP) z **wspólnym limitem czasu ticka** dla wszystkich kroków (bez nowego budżetu
  czasu), przeliczenie przyrostowe w razie potrzeby, benchmark `test:performance:strategy`.
- **F — Review, regresja, dokumentacja, PR.**
- **STEP 17 (AI)** dostanie deterministyczny pakiet kontekstu tematu (wersjonowany JSON, `evidence_hash`, odwołania do konkretnych pomiarów,
  treści zewnętrzne oznaczone jako niezaufane) — w STEP 16 bez wywołań LLM.

### 15.11 Poza zakresem STEP 16 (punkty rozszerzeń)

Automatyczny harmonogram płatnych analiz SERP (pola w ustawieniach i kolejka przez `SerpSubmitter` pozwalają go dodać), zapis pytań
„Ludzie pytają też” i powiązanych wyszukiwań (przychodzą w już opłaconej odpowiedzi — parser je dziś pomija), dodatkowe płatne endpointy
(np. intencja Labs), crawler i pobieranie zawartości stron (`ProjectPageIndex`), AI. Każde z nich wymaga osobnej decyzji.

## 16. Bezpieczeństwo

- **Autoryzacja projektów**: `ProjectGuard` → `ProjectContext` albo 404; repozytoria i usługi
  analityczne przyjmują wyłącznie `ProjectContext`.
- **wp-admin dla użytkowników panelu zablokowany** (przekierowanie, bez paska admina) — STEP 4;
  blokada enumeracji użytkowników przez REST dla anonimowych i wyłączenie XML-RPC — krok 15 (hardening).
- **OAuth** (STEP 5): jednorazowy `state` (TTL 10 min) związany z użytkownikiem i projektem, PKCE S256,
  dokładny redirect URI, callback tylko dla zalogowanych z ponowną autoryzacją projektu, kontrola scope
  i id_token (aud/iss/exp); kodów i tokenów nie logujemy ani nie zapisujemy jawnie.
- **Tokeny** (STEP 5): refresh token szyfrowany XChaCha20-Poly1305 (AEAD, libsodium), klucz w
  `wp-config.php` (osobny od soli WordPressa, identyfikator klucza w kopercie); access token tylko
  w pamięci procesu; tokeny nigdy nie trafiają do Blade, JS, REST ani logów.
- **SQL**: `$wpdb->prepare`, generator placeholderów `IN`, biała lista sortowania, walidacja dat i limitów.
- **XSS**: frazy i URL-e z GSC to dane zewnętrzne — zawsze escapowane (także w dowodach i wyjaśnieniach szans SEO).
- **Szanse SEO** (STEP 11): szansa z URL-a szukana wyłącznie po (`project_id` z `ProjectContext`, `public_id`) — identyfikator
  innego projektu, wewnętrzne ID i nieprawidłowy ULID dają 404; zmiany i ręczne przeliczenie wymagają
  `osf_seo_manage_opportunities` (trasa `ResolveProject` + kontrola w `OpportunityService`), nonce i zgodnego Origin.
- **CSRF**: nonce WordPressa w każdym formularzu panelu + kontrola Origin/Referer (STEP 4), nonce WP
  w REST, `state` w OAuth.
- **Logowanie**: `wp_signon` (działają wtyczki bezpieczeństwa podpięte pod `authenticate`), limit
  nieudanych prób `LoginThrottle` (5 na login+IP, 20 na IP w 15 min; klucze HMAC, bez jawnych loginów/IP),
  redirect po logowaniu tylko na lokalną ścieżkę (ochrona przed open redirect).
- **Nagłówki panelu** (STEP 4, `PanelHeaders`): `X-Robots-Tag: noindex, nofollow`,
  `Cache-Control: private, no-store, max-age=0`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`,
  `X-Content-Type-Options: nosniff`; brak zewnętrznych skryptów. Przy page cache na serwerze panel
  trzeba z niego wykluczyć (nagłówek `no-store` zwykle wystarcza).
- **Logi**: logger pluginu redaguje tokeny, sekrety, hasła, nagłówki `Authorization` i klucze prywatne.
- **Dane rynkowe** (STEP 12): dane logowania DataForSEO wyłącznie ze stałych w `wp-config.php`/env, czytane w chwili żądania (Basic Auth
  budowany w pamięci; nie trafiają do pól obiektów, bazy, logów, wyjątków, HTML, JSON ani JS); żądania wyłącznie do
  `https://api.dataforseo.com/v3/` (biała lista ścieżek); `Redactor` maskuje nagłówki Basic; płatna synchronizacja tylko z
  `osf_seo_manage_market_data` (trasa `ResolveProject` + kontrola w `MarketSyncService`), nonce, zgodny Origin i potwierdzona liczba fraz.
- **Nowe frazy** (STEP 13): przebieg i kandydat z URL-a szukane wyłącznie po (`project_id` z `ProjectContext`, `public_id`) — obce ID → 404;
  płatne uruchomienie, pobranie mimo cache, decyzje i wykluczenia wymagają `osf_seo_manage_keyword_discovery` (trasa + kontrola w
  `DiscoveryService`), nonce, zgodnego Origin i potwierdzonego planu (`expected_requests`, `expected_cost`); klient — tylko odczyt, bez kosztów.
  Frazy od dostawcy escapowane jak dane GSC (sekcja 12.14).
- **Pozycje SERP** (STEP 14): fraza, pomiar, przebieg i konkurent z URL-a szukane wyłącznie po (`project_id` z `ProjectContext`, `public_id`)
  — obce ID → 404; ustawienia, pomiary, frazy i konkurenci wymagają `osf_seo_manage_serp_tracking` (trasa + kontrola w usługach), nonce,
  zgodnego Origin i potwierdzonego planu (`expected_tasks`, `expected_cost`); klient — tylko odczyt, bez kosztów. Tytuły, opisy i URL-e z SERP
  escapowane; linki `http(s)` z `rel="noopener noreferrer nofollow"` (sekcja 13.13).
- **Luki SEO** (STEP 15): luka, grupa, przebieg i konkurent z URL-a wyłącznie po (`project_id` z `ProjectContext`, `public_id`) — obce ID → 404;
  import, ustawienia, harmonogram, marka i statusy wymagają `osf_seo_manage_keyword_gap` (trasa + kontrola w `GapService`), nonce, zgodnego
  Origin i potwierdzonego planu (`expected_requests`, `expected_cost`); klient — tylko odczyt, bez kosztów (sekcja 14.18).
- **Strategia** (STEP 16): kandydat wyłącznie po (`project_id` z `ProjectContext`, `public_id`) — obce ID → 404; przeliczenie i wpisy ręczne
  wymagają `osf_seo_manage_strategy` (kontrola w `StrategyService`), płatna analiza SERP (faza B) dodatkowo `osf_seo_manage_serp_tracking`;
  frazy i URL-e z dowodów to dane zewnętrzne (escapowanie jak w pozostałych modułach); sekcja 15.9.
- **Repozytorium publiczne**: sekcja 17; skan sekretów przed commitem; `.gitignore` blokuje pliki z sekretami.

## 17. Konfiguracja i sekrety

Stałe w `wp-config.php` (poza repozytorium) mają pierwszeństwo przed zmiennymi środowiskowymi o tej
samej nazwie. W repozytorium wyłącznie placeholdery.

| Stała | Przeznaczenie | Od |
|---|---|---|
| `OSF_SEO_LOG_LEVEL` | poziom logowania: `debug`, `info`, `warning`, `error` (domyślnie `warning`; `debug` przy `WP_DEBUG`) | STEP 1 |
| `OSF_SEO_GOOGLE_CLIENT_ID` | OAuth Client ID (typ „Web application”) | STEP 5 |
| `OSF_SEO_GOOGLE_CLIENT_SECRET` | OAuth Client Secret | STEP 5 |
| `OSF_SEO_ENCRYPTION_KEY` | klucz szyfrowania refresh tokenów: 32 losowe bajty w base64 (opcjonalny prefiks `base64:`) | STEP 5 |
| `OSF_SEO_GSC_HISTORY_MONTHS` | (opcjonalnie) ile miesięcy historii pobierać, 1–16, domyślnie 16 | STEP 9 |
| `OSF_SEO_SYNC_REFRESH_DAYS` | (opcjonalnie) okno kroczące codziennego odświeżania, 3–30 dni, domyślnie 7 | STEP 9 |
| `OSF_SEO_SYNC_TIME_BUDGET` | (opcjonalnie) budżet czasu jednego uruchomienia kolejki z WP-Cron, 5–300 s, domyślnie 20 | STEP 9 |
| `OSF_SEO_MOVERS_MIN_IMPRESSIONS` | (opcjonalnie) próg wyświetleń wzrostów/spadków dashboardu, domyślnie 10 | STEP 10 |
| `OSF_SEO_OPP_<PRÓG>` | (opcjonalnie) progi szans SEO, np. `OSF_SEO_OPP_LOW_CTR_MIN_IMPRESSIONS` — lista w sekcji 10.9 | STEP 11 |
| `OSF_SEO_DATAFORSEO_LOGIN` | login API DataForSEO (sekret, tylko wp-config/env) | STEP 12 |
| `OSF_SEO_DATAFORSEO_PASSWORD` | hasło API DataForSEO (sekret, tylko wp-config/env; to nie hasło do konta) | STEP 12 |
| `OSF_SEO_DATAFORSEO_MAX_TASKS_PER_RUN` | (opcjonalnie) twardy limit płatnych zadań na przebieg, 1–50, domyślnie 4 | STEP 12 |
| `OSF_SEO_DATAFORSEO_DAILY_COST_LIMIT` | (opcjonalnie) lokalny limit kosztów na dobę UTC (USD), domyślnie 1.00; 0 blokuje płatne wywołania | STEP 12 |
| `OSF_SEO_DATAFORSEO_MONTHLY_COST_LIMIT` | (opcjonalnie) lokalny limit kosztów na miesiąc UTC (USD), domyślnie 10.00 | STEP 12 |
| `OSF_SEO_DATAFORSEO_VOLUME_TTL_DAYS`, `OSF_SEO_DATAFORSEO_DIFFICULTY_TTL_DAYS` | (opcjonalnie) ważność wolumenu i trudności, 7–365 dni, domyślnie 30 | STEP 12 |
| `OSF_SEO_DATAFORSEO_MIN_IMPRESSIONS`, `OSF_SEO_DATAFORSEO_WINDOW_DAYS` | (opcjonalnie) wybór fraz: min. wyświetleń (50) w oknie dni danych (90) | STEP 12 |
| `OSF_SEO_DATAFORSEO_SYNC_LIMIT` | (opcjonalnie) maks. fraz w jednej synchronizacji projektu, domyślnie 1000 | STEP 12 |
| `OSF_SEO_DATAFORSEO_AUTO_REFRESH` | (opcjonalnie) `0` wyłącza automatyczne odświeżanie (odbiór wyników zadań działa dalej) | STEP 12 |
| `OSF_SEO_DATAFORSEO_PRICE_VOLUME_TASK`, `…_PRICE_DIFFICULTY_REQUEST`, `…_PRICE_DIFFICULTY_ITEM` | (opcjonalnie) ceny do szacunku przed wywołaniem (domyślnie 0.06 / 0.012 / 0.00012 USD) | STEP 12 |
| `OSF_SEO_DATAFORSEO_PRICE_DISCOVERY_REQUEST`, `…_PRICE_DISCOVERY_ITEM` | (opcjonalnie) ceny wyszukiwania fraz (Labs Related Keywords / Keyword Suggestions) do szacunku (domyślnie 0.012 / 0.00012 USD) | STEP 13 |
| `OSF_SEO_DISCOVERY_TTL_DAYS` | (opcjonalnie) cache seeda w projekcie, 1–365 dni, domyślnie 30 | STEP 13 |
| `OSF_SEO_DISCOVERY_MAX_SEEDS` | (opcjonalnie) maks. seedów na wyszukiwanie, 1–50, domyślnie 20 | STEP 13 |
| `OSF_SEO_DISCOVERY_MAX_CANDIDATES` | (opcjonalnie) górna granica limitu kandydatów na wyszukiwanie, 10–5000, domyślnie 1000 | STEP 13 |
| `OSF_SEO_DISCOVERY_MIN_VOLUME` | (opcjonalnie) domyślny minimalny wolumen, domyślnie 10 | STEP 13 |
| `OSF_SEO_DISCOVERY_WINDOW_DAYS`, `…_MIN_IMPRESSIONS`, `…_VISIBLE_POSITION`, `…_VISIBLE_SHARE` | (opcjonalnie) widoczność GSC kandydatów: okno 28–480 dni (90), min. wyświetleń (10), średnia pozycja „już widoczna” (10), min. udział wyświetleń w wolumenie (0.1) | STEP 13 |
| `OSF_SEO_DATAFORSEO_PRICE_SERP_PAGE`, `…_PRICE_SERP_NEXT_PAGE` | (opcjonalnie) ceny Google Organic SERP (Standard) do szacunku: pierwsza strona wyników 0.0006 USD, każda kolejna 0.00045 USD (TOP100 = 0.00465) | STEP 14 |
| `OSF_SEO_SERP_MAX_KEYWORDS` | (opcjonalnie) zalecany (miękki) limit monitorowanych fraz w projekcie, 1–1 000 000, domyślnie 500 — komunikat zamiast obcinania | STEP 14 |
| `OSF_SEO_SERP_MIN_RECHECK_HOURS` | (opcjonalnie) okno, w którym zlecona fraza nie jest zlecana ponownie (cron + pomiar ręczny), 1–168 h, domyślnie 6 | STEP 14 |
| `OSF_SEO_SERP_MAX_POSTS_PER_RUN`, `OSF_SEO_SERP_COLLECT_PER_RUN` | (opcjonalnie) maks. zleceń (po 100 zadań, 1–500) i odbiorów (1–10 000) w jednym przebiegu tła, domyślnie 20 / 200 | STEP 14 |
| `OSF_SEO_SERP_EXPIRE_HOURS` | (opcjonalnie) po ilu godzinach nieodebrane zadanie wygasa, 24–720, domyślnie 72 | STEP 14 |
| `OSF_SEO_DATAFORSEO_PRICE_GAP_REQUEST`, `…_PRICE_GAP_ITEM` | (opcjonalnie) ceny Labs Ranked Keywords do szacunku (domyślnie 0.012 USD za żądanie, 0.00012 USD za frazę) | STEP 15 |
| `OSF_SEO_GAP_TTL_DAYS` | (opcjonalnie) świeżość wspólnego zbioru domeny, 7–180 dni, domyślnie 30 | STEP 15 |
| `OSF_SEO_GAP_MAX_REQUESTS_PER_TICK` | (opcjonalnie) maks. stron Ranked Keywords w jednym kroku tła, 1–100, domyślnie 10 | STEP 15 |
| `OSF_SEO_GAP_WINDOW_DAYS`, `…_MIN_IMPRESSIONS`, `…_VISIBLE_POSITION`, `…_VISIBLE_SHARE` | (opcjonalnie) widoczność GSC w lukach: okno 28–480 dni (90), min. wyświetleń (10), pozycja „widoczna” (10), min. udział wyświetleń w wolumenie (0.1) | STEP 15 |
| `OSF_SEO_GAP_SERP_FRESH_DAYS` | (opcjonalnie) jak długo pomiar SERP jest dowodem widoczności, 1–180 dni, domyślnie 30 | STEP 15 |
| `OSF_SEO_GAP_MAX_CLUSTER_KEYWORDS` | (opcjonalnie) maks. fraz grupowanych w projekcie (wg priorytetu), 100–100 000, domyślnie 20 000 | STEP 15 |
| `OSF_SEO_STRATEGY_MAX_KEYWORDS` | (opcjonalnie) maks. aktywnych kandydatów Strategii w projekcie, 100–50 000, domyślnie 5000 | STEP 16 |
| `OSF_SEO_STRATEGY_SERP_MAX_PER_RUN` | (opcjonalnie) maks. fraz w jednej płatnej analizie SERP Strategii, 1–1000, domyślnie 100 (od fazy B) | STEP 16 |
| `OSF_SEO_STRATEGY_WINDOW_DAYS`, `…_GSC_MIN_IMPRESSIONS`, `…_GSC_MAX_POSITION` | (opcjonalnie) okno GSC faktów i źródła GSC (28–480 dni, 90), próg wyświetleń źródła GSC (50) i średniej pozycji GSC (50) | STEP 16 |
| `OSF_SEO_STRATEGY_DISCOVERY_MIN_PRIORITY`, `…_GAP_MIN_PRIORITY` | (opcjonalnie) minimalny priorytet nowych / do analizy Nowych fraz (50) i Luk fraz (40) jako źródła Strategii | STEP 16 |

```php
// wp-config.php — przykład z placeholderami
define('OSF_SEO_GOOGLE_CLIENT_ID', 'your-client-id');
define('OSF_SEO_GOOGLE_CLIENT_SECRET', 'your-client-secret');
define('OSF_SEO_ENCRYPTION_KEY', 'base64:...'); // wp osf-seo google:generate-key
define('OSF_SEO_DATAFORSEO_LOGIN', 'your-dataforseo-api-login');
define('OSF_SEO_DATAFORSEO_PASSWORD', 'your-dataforseo-api-password');
```

- Klucz szyfrowania: osobny dla każdego środowiska, stały, z bezpieczną kopią poza serwerem. Zmiana klucza
  = ponowne połączenie kont Google (stare szyfrogramy dają `key_mismatch`); rotacja z drugim kluczem — później.
- Brak stałych = integracja wyłączona (panel pokazuje nazwy brakujących stałych, `wp osf-seo status`: INFO);
  błędny format klucza = FAIL w `wp osf-seo status`.

## 18. Deployment (do ustalenia)

Stan: CI (`.github/workflows/ci.yml`) uruchamia wyłącznie testy i build — w repozytorium nie ma skryptu deployu.
Reorganizacja jest w `main`. Wcześniejsza integracja Git Hostingera skopiowała cały root repozytorium do `wp-content`
i nadpisała/usunęła inne pliki WordPressa — **całego repozytorium nigdy nie wdrażamy do `wp-content`**.
Wdrożenie musi być zawężone: `plugins/osf-seo/` → `wp-content/plugins/osf-seo/` oraz `themes/seo/` → `wp-content/themes/seo/`
(bez `tests/`, `node_modules/`, plików dev). Przed każdym wdrożeniem: potwierdzić, że auto-deploy Git w hPanelu jest wyłączony
albo zawężony, i zrobić kopię bazy.

Do sprawdzenia w hPanelu Hostingera:

1. Git: repozytorium, branch, katalog instalacji, auto-deploy.
2. Czy na serwerze istnieje `wp-content/themes/seo/vendor` i jak tam trafia.
3. Dostęp SSH (host, port), wersje PHP i MariaDB, dostępność WP-CLI i crona.

Warianty docelowe:

- **A (rekomendowany)**: GitHub Actions → SFTP/rsync osobno dla `themes/seo` i `plugins/osf-seo`.
  CI wykonuje `composer install --no-dev` i `yarn build`; do serwera nie trafiają `tests/`, `node_modules/`,
  pliki dev. Wtedy `public/build` i `vendor/` mogą wyjść z gita. Dane dostępowe tylko w GitHub Secrets.
- **B (bez SSH)**: CI buduje i publikuje gałęzie deployowe (`deploy/theme`, `deploy/plugin`);
  dwa połączenia Git w hPanelu, każde z własnym katalogiem.
- **C (niezalecany)**: integracja Git Hostingera wprost na `wp-content` — wystawiłaby na serwer cały root repo.

## 19. Roadmapa i stan prac

**MVP 1** (tylko GSC, koszt zewnętrznych usług: 0 zł; od STEP 12 — DataForSEO, D3):

| # | Krok | Stan |
|---|---|---|
| 0 | Reorganizacja repo, bezpieczeństwo sekretów, dokumentacja | ✅ |
| 1 | Fundament pluginu: bootstrap, autoloader, kontener, role i capabilities, logger, PHPUnit, `wp osf-seo status` | ✅ STEP 1 |
| 2 | Instalator schematu i migracje tabel MVP 1 (`db:migrate`, `db:status`) | ✅ STEP 2 |
| 3 | Domena projektów: repozytorium, `ProjectGuard`, `ProjectContext`, przypisania użytkowników, CLI | ✅ STEP 3 |
| 4 | Powłoka panelu w Sage: routing, layout, osobne wejście Vite, logowanie, nagłówki, lista projektów | ✅ STEP 4 |
| 5 | Projekty w UI: tworzenie, edycja, archiwizacja, przypisywanie klientów | ✅ STEP 4 (bez przypisywania w UI — na razie `wp osf-seo project:assign`) |
| 6 | Google OAuth: PKCE/state, szyfrowanie tokenów, callback, połączenia | ✅ STEP 5 (Google mockowany; prawdziwy OAuth — po konfiguracji) |
| 7 | Wybór property GSC | ✅ STEP 6 (lista, sugestia, reset przy zmianie property, CLI) |
| 8 | Klient GSC API: paginacja, błędy, backoff, `wp osf-seo gsc:probe` | ✅ STEP 7 (Google mockowany w testach; prawdziwe API — probe na stagingu) |
| 9 | Importery `site_daily`, `query_daily`, normalizacja, zamiana zakresu w transakcji | ✅ STEP 8 (+ `query_page_daily`; staging, atomowa zamiana zakresu) |
| 10 | Orkiestracja synchronizacji, postęp, „Synchronizuj teraz”/„Ponów” | ✅ STEP 9 (własna kolejka na `sync_runs` + WP-Cron — D19) |
| 11 | `query_page_daily` + `visibility_daily`; pomiar skali | ✅ STEP 8/10: `query_page_daily` i pomiar (`composer test:performance`); `visibility_daily` (wykres TOP N w czasie) — do zrobienia |
| 12 | Analityka (porównania, tabela fraz, TOP N, serie) + generator danych testowych | ✅ STEP 10 (`KeywordReport`, `OverviewReport`, benchmark) |
| 13 | Widok Keywords + szczegół frazy | ✅ STEP 10 lista fraz (filtry, sortowanie, paginacja, strona docelowa); szczegół frazy — do zrobienia |
| 14 | Dashboard projektu + wykresy (Chart.js, REST) | ✅ STEP 10 (KPI, TOP N, wzrosty/spadki, wykres dzienny; bez REST — dane renderowane serwerowo) |
| 15 | Hardening i operacje (rate limit, nagłówki, status crona, testy dostępu) | — |
| 16 | Szanse SEO: wykrywanie (niski CTR, blisko TOP, słaba pozycja, spadki, możliwa kanibalizacja), priorytet i pewność, grupowanie po podstronach, praca nad szansą, automatyczne przeliczanie | ✅ STEP 11 (sekcja 10; obserwacja po wdrożeniu — wersja podstawowa) |
| 17 | DataForSEO: fundament (dostawca za interfejsem, klient, limity kosztów, rejestr zadań) i wzbogacenie fraz danymi rynkowymi (wolumen, historia, CPC, konkurencja Ads, trudność SEO) | ✅ STEP 12 (sekcja 11) |
| 18 | Nowe frazy: seedy (ręczne, GSC, szanse), wyszukiwanie DataForSEO Labs w tle z planem i limitami kosztów, deduplikacja, widoczność GSC, priorytet odkrycia, praca nad frazą, wykluczenia | ✅ STEP 13 (sekcja 12) |
| 19 | Pozycje SERP i konkurenci: monitorowane frazy, pomiary Google Organic (Standard, TOP100) z planem, rezerwacją kosztu i harmonogramem, pełne TOP N w historii, zmiany, konkurenci monitorowani i organiczni | ✅ STEP 14 (sekcja 13) |
| 20 | Luki SEO: wspólne zbiory fraz domen konkurentów (Labs Ranked Keywords) z planem, limitami i importem w tle, punkt odniesienia projektu, widoczność SERP → GSC → Labs, typ i priorytet luki, filtry marki, grupy fraz, luka treści (heurystyka), strony konkurencji, historia zbiorów | ✅ STEP 15 (sekcja 14) |
| 21 | Strategia i SERP Intelligence: kandydaci z modułów z dowodami, analiza zapisanych SERP-ów, strona docelowa, działania, priorytet i pewność, tematy, backlog z workflow | ⏳ STEP 16 (sekcja 15): faza A (fundament) zrobiona; fazy B–F według planu |

**MVP 2**: ~~Opportunity Score~~ (STEP 11), Pages/landing pages, zaawansowane filtry, automatyczna synchronizacja, raporty.
**MVP 3**: własny crawler, audyt techniczny, połączenie crawler + GSC.
**MVP 4**: panel klienta, raporty, rekomendacje AI.
**Kolejne etapy** (kolejność orientacyjna): ~~odkrywanie nowych fraz~~ (STEP 13), ~~monitoring konkurencji i ranking SERP~~ (STEP 14),
~~luka fraz/treści~~ (STEP 15), strategia i backlog SEO (STEP 16, w toku), AI (STEP 17), retencja/rollupy historii SERP po pomiarze wzrostu.

## 20. Porządki w motywie (C1–C5)

Każdy etap to osobny commit z testem (build, `php -l`, smoke test WordPress). Kolejność:

| Etap | Zakres |
|---|---|
| C1 | martwe pliki: `package-lock.json`, `pnpm-lock.yaml`, `pnpm-workspace.yaml`, `PRZENOSINY.md`, `.yarn/install-state.gz`, nieładowany `app/helpers.php`, osierocone `category-posts.{scss,js}`, `resources/views/blocks/posts.php` |
| C2 | layout marketingowy → minimalna powłoka: GTM, Leaflet, Google Fonts, schema/OG/canonical, filtr `robots_txt`, `sections/*`, `partials/*`, `Walkers/*`, widoki treści, composery `Archive/Post/Comments`, marketingowe części `setup.php` |
| C3 | backend ACF/Woo/CPT: `app/Blocks`, `Options`, `Fields`, `Support`, `config/acf.php`, `views/blocks`, `post-types.php`, `patterns/`, szablony Woo, `ExampleBlock`, pakiety `generoi/sage-woocommerce`, `log1x/acf-composer`, `spatie/pdf-to-image` |
| C4 | frontend: `variables.scss`, style i JS bloków, marketingowe obrazy i font, pakiety gsap, swiper, baguettebox, jquery, react, wtyczki block-editora w Vite |
| C5 | nazewnictwo: `package.json`, `composer.json`, `style.css`, text domain |

## 21. Ryzyka i otwarte kwestie

- **Deployment** nowej struktury nieustalony (sekcja 18) — wdrażać wyłącznie katalog pluginu i motywu (nigdy całe repo do `wp-content`).
- **DataForSEO (STEP 12)**: płatne API — lokalne limity są bezpiecznikiem, nie rozliczeniem (rozliczenie w panelu DataForSEO; tam też warto
  ustawić limit kosztów konta). Ceny i limity zweryfikowane pośrednio (sekcja 11.2) — przed pierwszym użyciem potwierdzić w panelu DataForSEO.
  Kody lokalizacji potwierdzić `wp osf-seo dataforseo:locations --country=PL` (bezpłatne).
- **Nowe frazy (STEP 13)**: ceny Labs zweryfikowane pośrednio (sekcja 12.2); priorytet odkrycia i progi widoczności to przybliżenie do kalibracji
  na prawdziwych projektach (stałe `OSF_SEO_DISCOVERY_*`). Koszt wyszukiwania zależy od liczby zwróconych elementów — pierwszy przebieg na stagingu
  z 1–2 seedami i małym limitem (sekcja 12.16).
- **Pozycje SERP (STEP 14)**: cena Google Organic zweryfikowana pośrednio (13.2) — pierwszy pomiar na stagingu z 1 frazą i porównanie kosztu
  szacowanego ze zgłoszonym (13.15). Koszt rośnie liniowo z liczbą fraz i częstotliwością; historia rośnie bez retencji (~100 wierszy
  i kilka KB na pomiar, 13.14) — decyzja o rollupach/archiwizacji po kilku miesiącach danych.
- **Luki SEO (STEP 15)**: cena Ranked Keywords zweryfikowana pośrednio (14.2) — pierwszy import na stagingu dla 1 konkurenta w presecie
  szybkim i porównanie kosztu (14.20). Maks. 10 000 fraz na domenę (14.21). Pełne przeliczenie dużego projektu ~12 s w tle (14.19) —
  przy setkach projektów potrzebne przeliczenie przyrostowe. Heurystyki (widoczność sporadyczna, luka treści, priorytet) do kalibracji.
- **Strategia (STEP 16)**: progi źródeł i limit kandydatów (5000) do kalibracji na prawdziwych projektach (`OSF_SEO_STRATEGY_*`); przeliczenie
  dużego projektu agreguje GSC z 90 dni (w tle od fazy E — do tego czasu `wp osf-seo strategy:refresh`); lista członków szansy z tekstu
  wyszukiwania może być ucięta przy bardzo dużych grupach (uzupełnia ją powiązanie przez podstronę).
- **OAuth Testing**: tokeny ważne 7 dni — publikacja aplikacji przed produkcją.
- **Skala `query_page_daily`** — decyzja po pomiarze (sekcja 6.4).
- **Wydajność raportów przy bardzo dużych property** — czasy rosną liniowo z liczbą wierszy fraz w okresie
  (sekcja 8.3); przy dziesiątkach tysięcy fraz dziennie potrzebne agregaty okresowe lub rollupy.
- **Prawdziwe API Google** — klient, import i synchronizacja testowane na atrapie; zachowanie realnego API
  (limity, starsze daty niż 16 miesięcy, opóźnienie danych `final`) do potwierdzenia `gsc:probe` i pierwszą synchronizacją na stagingu.
- **WP-Cron na stagingu** — bez crona systemowego kolejka działa tylko przy ruchu na stronie; heartbeat w `wp osf-seo status`.
- **Hosting**: dostępność SSH/WP-CLI/crona i wersja PHP na Hostingerze do weryfikacji.
- **Limity GSC API** i opóźnienie danych — obsłużone ponowieniami i odczytem ostatniej daty z danych; do potwierdzenia na stagingu.
- **Sesje Laravela** wymagają zapisywalnego `storage/` motywu na serwerze.
- **Szanse SEO (STEP 11)**: progi i referencyjny CTR są przybliżeniem — do kalibracji na prawdziwych projektach (stałe `OSF_SEO_OPP_*`);
  analiza dużych property zajmuje ~1–2 s i ~50 MB pamięci na okres (w tle, po imporcie); bez crona systemowego przeliczanie
  następuje przy ruchu na stronie (jak kolejka). Obserwacja po wdrożeniu nie jest atrybucją przyczynową.
