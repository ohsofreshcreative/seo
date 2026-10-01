# AGENTS.md — OSF SEO

Wspólne instrukcje dla Codex, GitHub Copilot i Claude Code. Edytuj wyłącznie ten plik —
`CLAUDE.md` tylko go importuje (`@AGENTS.md`), bez symlinku.

Komunikacja z użytkownikiem: **po polsku** (wyjaśnienia, pytania, podsumowania, raporty).

⸻

## 1. Czym jest projekt

OSF SEO to wewnętrzna aplikacja SEO agencji OhSoFresh (docelowo także panel klienta).
Działa jako osobna instalacja WordPress (staging: `https://seo.ohsofresh.top`) i składa się z:

- **pluginu `osf-seo`** (`plugins/osf-seo`) — cała logika biznesowa: projekty, Google OAuth,
  import Google Search Console, synchronizacja, analityka, uprawnienia, WP-CLI, REST,
- **motywu Sage 11 `seo`** (`themes/seo`) — wyłącznie UI panelu (routing Acorn, kontrolery,
  Blade, Tailwind, Alpine, Chart.js).

Źródło danych MVP: wyłącznie **Google Search Console API** (koszt zewnętrznych usług: 0 zł).
Nie dodawaj płatnych API (SERP API, Semrush, Ahrefs, Senuto, SeoStation…) ani scrapowania
wyników Google. Przyszłe integracje płatne tylko za interfejsem (np. `SerpProvider`), bez implementacji.

Architektura, decyzje i roadmapa: **`docs/ARCHITECTURE.md`** — przeczytaj przed większą zmianą
i aktualizuj przy zmianie decyzji.

⸻

## 2. Repozytorium jest PUBLICZNE — sekrety

- Żaden sekret nie może trafić do kodu, testów, fixture'ów, dokumentacji, commitów ani logów:
  Google Client ID/Secret, klucz szyfrujący, tokeny OAuth (access/refresh), hasła, dane dostępowe
  Hostingera, klucze SSH, dane dostępowe do bazy.
- Sekrety konfigurujemy wyłącznie przez stałe w `wp-config.php` lub zmienne środowiskowe
  (np. `OSF_SEO_GOOGLE_CLIENT_ID`, `OSF_SEO_GOOGLE_CLIENT_SECRET`, `OSF_SEO_ENCRYPTION_KEY`).
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
**To kod przeznaczony do usunięcia** etapami C1–C5 (`docs/ARCHITECTURE.md`, sekcja 15).

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

⸻

## 6. Reguły domenowe Google Search Console (łatwo je pomylić)

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
   `button`, `card`, `page-header`, `field`, `badge`, `flash`, `empty-state`, `nav-link`, `nonce`),
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
wp osf-seo sync:run             # kolejka synchronizacji (cron systemowy)
composer test:performance       # benchmark raportów + EXPLAIN na syntetycznych danych (OSOBNA baza testowa)
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
**jeszcze nieustalony** (`docs/ARCHITECTURE.md`, sekcja 13). Nie zmieniaj jego konfiguracji,
nie łącz się z serwerem i nie używaj żadnych credentials znalezionych w repo.
