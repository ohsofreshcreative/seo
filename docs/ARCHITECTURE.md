# OSF SEO — architektura

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
11. [Bezpieczeństwo](#11-bezpieczeństwo)
12. [Konfiguracja i sekrety](#12-konfiguracja-i-sekrety)
13. [Deployment (do ustalenia)](#13-deployment-do-ustalenia)
14. [Roadmapa i stan prac](#14-roadmapa-i-stan-prac)
15. [Porządki w motywie (C1–C5)](#15-porządki-w-motywie-c1c5)
16. [Ryzyka i otwarte kwestie](#16-ryzyka-i-otwarte-kwestie)

---

## 1. Cel i zakres

Panel SEO dla stron agencji i jej klientów. Po dodaniu projektu i połączeniu go z Google Search
Console system sam pobiera frazy, na które strona pojawia się w Google, zapisuje historię we własnej
bazie i pokazuje: ranking fraz (średnia pozycja GSC), porównania okresów, wzrosty i spadki,
szanse SEO, landing pages i wykresy.

Poza zakresem MVP: płatne API (SERP, Semrush, Ahrefs, Senuto…), scrapowanie Google, AI.
Przyszłe integracje płatne wyłącznie za interfejsem (`SerpProvider`), bez implementacji.

## 2. Decyzje

| # | Decyzja | Uzasadnienie / uwagi |
|---|---|---|
| D1 | Jedno repozytorium: motyw + plugin. Root repo = `wp-content/` (`themes/seo`, `plugins/osf-seo`) | Jeden produkt, wspólne wdrażanie; LocalWP bez symlinków; ścieżki 1:1 z serwerem |
| D2 | Plugin `osf-seo` w czystym PHP (bez Laravela/Acorn, bez Guzzle); motyw = UI (routing Acorn, Blade) | Logika niezależna od motywu, brak konfliktów zależności z `vendor/` motywu |
| D3 | Jedyne źródło danych MVP: Google Search Console API | Koszt zewnętrznych usług 0 zł |
| D4 | OAuth scope: `https://www.googleapis.com/auth/webmasters.readonly` (+ `openid email` do identyfikacji konta) | Tylko odczyt, bez modyfikacji Search Console |
| D5 | Hosting: Hostinger. Staging: `https://seo.ohsofresh.top`. Bez Redis/persistent object cache w MVP | Cache przez transients/bazę; nic nie zależy od Redis |
| D6 | Lokalnie system działa bez systemowego crona (WP-Cron); na produkcji podpinamy cron systemowy | Sekcja 9.1 |
| D7 | Aplikacja OAuth w trybie External/Testing akceptowana do czasu publikacji | Refresh tokeny w trybie Testing wygasają po 7 dniach — publikacja przed produkcją |
| D8 | Motyw czyszczony etapami do czystego Sage 11 tylko dla OSF SEO (C1–C5), build/test po każdym etapie | Sekcja 15 |
| D9 | Panel: standardowe utilities Tailwind, proste konwencje (AGENTS.md, sekcja 8) | Dawne ograniczenia projektu marketingowego nie obowiązują |
| D10 | Wykresy: Chart.js. React niepotrzebny (do usunięcia w cleanupie) | — |
| D11 | MVP 1 bez rozbicia fraz na device/country | Nie mnożymy danych bez potrzeby; priorytet: query, page, date, clicks, impressions, CTR, średnia pozycja |
| D12 | TOP 3/10/20/50/100: karty liczone dla wybranego okresu, wykres na oknie kroczącym 7 dni; UI jasno informuje, że to średnia pozycja GSC | Dzienne pozycje fraz z 1 wyświetleniem są szumem |
| D13 | Backfill ok. 16 miesięcy, od najnowszych danych; UI nie jest blokowane; postęp widoczny | Połącz GSC → najnowsze dane → dashboard działa → starsze dane w tle |
| D14 | `query_page_daily` eksperymentalnie; pomiar na kilku projektach przed decyzją o retencji/rollupie | Sekcja 6.4 |
| D15 | Opportunity Score dopiero w MVP 2 | Sekcja 10 (tylko specyfikacja) |
| D16 | `public_id` (ULID) w URL + `ProjectGuard` | ULID tylko utrudnia enumerację; zabezpieczeniem jest autoryzacja |
| D17 | Repozytorium publiczne: sekrety wyłącznie w `wp-config.php` / zmiennych środowiskowych | Sekcja 12 |
| D18 | Zmiana property GSC przy istniejących danych = jawny reset (usunięcie danych projektu i ponowny import); bez izolacji danych per property | Prostszy model (klucze faktów bez property), zero ryzyka mieszania danych. Sekcja 7.1 |
| D19 | Kolejka synchronizacji: własna, na `osf_sync_runs` + WP-Cron / cron systemowy (zamiast Action Scheduler) | Bez zewnętrznej biblioteki w publicznym repo i dodatkowych tabel; jeden runner (GET_LOCK), budżet czasu. Sekcja 9.1 |
| D20 | Szanse SEO w modelu hybrydowym: dowody wyliczane z danych GSC (wykrycia okresu zastępowane przy analizie), stan pracy trwały; szansa = projekt × stabilny odcisk (property, typ, podstrona/fraza/para adresów) | Lista zadań przetrwa przeliczenia i ponowny import; bez kopiowania faktów GSC. Sekcja 10 |
| D21 | Analiza szans jako osobny krok po kolejce synchronizacji (WP-Cron i `sync:run`), nie część importera; klucz danych pomija analizę bez zmian | Import nie zależy od analizy; idempotentnie, bez generalizowania `SyncRunner`. Sekcja 10.8 |
| D22 | Reset property archiwizuje szanse (stan `archived`, historia i stan pracy zostają) i usuwa ich dane pochodne; szanse nie należą do `GscDataStore::DATA_TABLES` | Stare rekomendacje nie udają aktualnych, a historia pracy nie znika bez decyzji. Sekcja 10.8 |

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

Zaimplementowane w STEP 11:

```
GET  /projects/{project}/opportunities                 (lista: ?days=7|28|90, type, status, priority, confidence, q, state, view=list|pages, page)
GET  /projects/{project}/opportunities/{opportunity}   (szczegóły; {opportunity} = public_id szansy, ULID; ?days)
POST /projects/{project}/opportunities/{opportunity}   (osf_seo_manage_opportunities; status, note, completed_on)
POST /projects/{project}/opportunities/analyze         (osf_seo_manage_opportunities; „Przelicz szanse”, limit 1 / 60 s)
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
│   ├── Cli/             # wp osf-seo status, db:*, project:*, google:*, gsc:*, sync:run, opportunities:*
│   ├── Database/        # Connection ($wpdb + wyjątki, transakcje, GET_LOCK), BulkInsert, Migrator, Migrations/, Schema (spec), SchemaInspector
│   ├── Projects/        # Project, ProjectRepository, ProjectService, DomainNormalizer, statusy i role
│   ├── Http/            # HttpTransport (WP HTTP API), HttpResponse — cały ruch do Google
│   ├── Google/          # OAuthFlow, OAuthClient, OAuthStateStore, Pkce, TokenVault, ConnectionRepository, AccessTokenProvider, GoogleApi (ApiRequester)
│   ├── Gsc/             # GscClient (sites.list, searchAnalytics.query), PropertyService, GscProbe, GscImporter, Dictionary, GscDataStore
│   ├── Sync/            # WindowPlanner, SyncPlanner, SyncRunner (kolejka sync_runs), SyncScheduler (WP-Cron), SyncService
│   ├── Analytics/       # Metrics, Period, KeywordReport, OverviewReport, Visibility, ReportCache
│   ├── Opportunities/   # Szanse SEO (STEP 11): OpportunityDetector, CtrModel, OpportunityScorer, ConfidenceModel, Fingerprint, OpportunityExplainer, OpportunityConfig, OpportunityDataSource, OpportunityAnalyzer, OpportunityRepository, OpportunityService, OpportunityScheduler
│   ├── Rest/            # (później) endpointy dla panelu — w MVP 1 dane renderowane serwerowo + JSON stanu synchronizacji
│   ├── Serp/            # (przyszłość) wyłącznie interfejs SerpProvider
│   └── Crawler/         # (MVP 3)
└── tests/               # PHPUnit: Unit (bez WordPressa), Integration (prawdziwy WP + MySQL/MariaDB)
```

### 4.3 Struktura panelu w motywie (STEP 4)

```
themes/seo/
├── functions.php                   # ->withRouting(using: …) + PanelMiddleware::GLOBAL
├── routes/web.php                  # trasy panelu (capabilities jako literały)
├── app/Http/Controllers/Panel/     # Auth, Dashboard, Project (przegląd = dashboard GSC), Keywords, Opportunities (szanse SEO), ProjectSection, SearchConsole (OAuth, property, synchronizacja), Settings
├── app/Http/Middleware/Panel/      # PanelHeaders, UnslashInput, RequirePlugin, Authenticate, VerifyNonce, ResolveProject
├── app/Panel/                      # PanelUrl (adresy, bezpieczny redirect), PanelResponse (404/403/503), Flash, Format (liczby i daty PL)
├── app/View/Composers/Panel/       # Layout: użytkownik, projekty do przełącznika, bieżący projekt, flash
├── resources/css/panel.css         # osobne wejście Vite: Tailwind 4 (source(none)) + forms + tokeny brand-*
├── resources/js/panel.js           # Alpine (bez jQuery, GSAP, Reacta, CDN); panel/chart.js — Chart.js ładowany dynamicznie tylko na dashboardzie
├── resources/views/panel/          # layouts/{base,guest,app}, auth/login, dashboard, projects/*, opportunities/*, settings, error
└── resources/views/components/panel/  # button, card, page-header, field, badge, flash, empty-state, nav-link, nonce, delta, stat, score, confidence, opportunity-status
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
z `osf_pages.id`. SERP (przyszłość): osobna tabela snapshotów, wyłącznie za interfejsem `SerpProvider`.

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
> i kolejnymi krokami do ręcznego sprawdzenia. Bez DataForSEO, SERP API i AI (decyzja D3 bez zmian).

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

## 11. Bezpieczeństwo

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
- **Repozytorium publiczne**: sekcja 12; skan sekretów przed commitem; `.gitignore` blokuje pliki z sekretami.

## 12. Konfiguracja i sekrety

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

```php
// wp-config.php — przykład z placeholderami
define('OSF_SEO_GOOGLE_CLIENT_ID', 'your-client-id');
define('OSF_SEO_GOOGLE_CLIENT_SECRET', 'your-client-secret');
define('OSF_SEO_ENCRYPTION_KEY', 'base64:...'); // wp osf-seo google:generate-key
```

- Klucz szyfrowania: osobny dla każdego środowiska, stały, z bezpieczną kopią poza serwerem. Zmiana klucza
  = ponowne połączenie kont Google (stare szyfrogramy dają `key_mismatch`); rotacja z drugim kluczem — później.
- Brak stałych = integracja wyłączona (panel pokazuje nazwy brakujących stałych, `wp osf-seo status`: INFO);
  błędny format klucza = FAIL w `wp osf-seo status`.

## 13. Deployment (do ustalenia)

Stan: w repozytorium nie ma konfiguracji CI ani skryptu deployu. Dotychczasowy mechanizm (prawdopodobnie
integracja Git Hostingera wdrażająca root repo do `wp-content/themes/seo`) **nie pasuje do nowej struktury**
— wdrożenie całego roota repo do katalogu motywu zepsułoby motyw. Dlatego reorganizacja nie trafia do
`main`, dopóki deploy nie zostanie skonfigurowany na nowo.

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

## 14. Roadmapa i stan prac

**MVP 1** (koszt zewnętrznych usług: 0 zł):

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

**MVP 2**: ~~Opportunity Score~~ (STEP 11), Pages/landing pages, zaawansowane filtry, automatyczna synchronizacja, raporty.
**MVP 3**: własny crawler, audyt techniczny, połączenie crawler + GSC.
**MVP 4**: panel klienta, raporty, rekomendacje AI.
**Przyszłość**: opcjonalny dokładny rank tracking przez SERP API (poza darmowym MVP).

## 15. Porządki w motywie (C1–C5)

Każdy etap to osobny commit z testem (build, `php -l`, smoke test WordPress). Kolejność:

| Etap | Zakres |
|---|---|
| C1 | martwe pliki: `package-lock.json`, `pnpm-lock.yaml`, `pnpm-workspace.yaml`, `PRZENOSINY.md`, `.yarn/install-state.gz`, nieładowany `app/helpers.php`, osierocone `category-posts.{scss,js}`, `resources/views/blocks/posts.php` |
| C2 | layout marketingowy → minimalna powłoka: GTM, Leaflet, Google Fonts, schema/OG/canonical, filtr `robots_txt`, `sections/*`, `partials/*`, `Walkers/*`, widoki treści, composery `Archive/Post/Comments`, marketingowe części `setup.php` |
| C3 | backend ACF/Woo/CPT: `app/Blocks`, `Options`, `Fields`, `Support`, `config/acf.php`, `views/blocks`, `post-types.php`, `patterns/`, szablony Woo, `ExampleBlock`, pakiety `generoi/sage-woocommerce`, `log1x/acf-composer`, `spatie/pdf-to-image` |
| C4 | frontend: `variables.scss`, style i JS bloków, marketingowe obrazy i font, pakiety gsap, swiper, baguettebox, jquery, react, wtyczki block-editora w Vite |
| C5 | nazewnictwo: `package.json`, `composer.json`, `style.css`, text domain |

## 16. Ryzyka i otwarte kwestie

- **Deployment** nowej struktury nieustalony (sekcja 13) — do tego czasu praca na branchu roboczym.
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
