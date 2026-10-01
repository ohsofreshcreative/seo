# Wibble

Wewnętrzne narzędzie SEO agencji OhSoFresh (dawniej **OSF SEO**): projekty klientów połączone z Google Search Console,
automatyczne wykrywanie fraz, porównania okresów, wzrosty i spadki, szanse SEO, dashboardy, dane rynkowe fraz
oraz wyszukiwanie nowych fraz, na które strona jeszcze nie ma widoczności.

Źródła danych: **Google Search Console API** (źródło prawdy o skuteczności strony) i **DataForSEO** — zatwierdzony płatny
dostawca danych rynkowych (wolumen, historia wolumenu, CPC, konkurencja Ads, trudność SEO, intencja) i nowych fraz
(DataForSEO Labs), ze wspólnymi lokalnymi limitami kosztów.

> **Nazwy techniczne:** plugin `osf-seo`, stałe `OSF_SEO_*`, tabele `osf_*`, namespace `OsfSeo\` i komendy `wp osf-seo …`
> celowo pozostają bez zmian — techniczny rebrand będzie osobnym etapem.

> **Status:** MVP 1 w toku — plugin i panel: projekty i uprawnienia, Google OAuth, wybór property GSC,
> import danych Search Console (sumy witryny, frazy, frazy × strony), kolejka synchronizacji z backfillem
> ok. 16 miesięcy i codziennym odświeżaniem, lista fraz z porównaniem okresów oraz dashboard projektu
> (KPI, TOP 3/10/20/50/100 wg średniej pozycji GSC, wzrosty i spadki), Szanse SEO (niski CTR, frazy blisko TOP,
> słaba pozycja, spadki, możliwa kanibalizacja — z priorytetem, pewnością i pracą nad szansą) oraz dane rynkowe fraz
> z DataForSEO (STEP 12: wolumen, trudność SEO, CPC, konkurencja Ads; limity kosztów) oraz moduł „Nowe frazy”
> (STEP 13: seedy z GSC, szans lub wpisane ręcznie → DataForSEO Labs w tle z planem i kosztem przed uruchomieniem →
> deduplikacja, widoczność w GSC, priorytet odkrycia, decyzje i wykluczenia). Plan i postęp:
> [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).
>
> **Repozytorium jest publiczne.** Nie commituj żadnych sekretów (sekcja „Konfiguracja”).

## Struktura

Root repozytorium odpowiada katalogowi `wp-content/` instalacji WordPress — bez symlinków.

```
<root repo> = wp-content/
├── plugins/osf-seo/     # plugin: cała logika aplikacji (projekty, GSC, synchronizacja, analityka)
├── themes/seo/          # motyw Sage 11: UI panelu (Blade, Tailwind, Alpine)
├── docs/                # architektura, decyzje, roadmapa
├── AGENTS.md            # instrukcje dla agentów AI (CLAUDE.md go importuje)
└── README.md
```

`.gitignore` działa jak whitelista: poza powyższymi ścieżkami wszystko w `wp-content/`
(uploads, inne pluginy, cache) jest ignorowane.

## Wymagania

- PHP 8.2+, WordPress 6.6+, MySQL/MariaDB
- Composer 2
- Node.js 20+ i yarn (motyw)

## Uruchomienie lokalne (LocalWP)

### Nowa strona w Local

1. Utwórz stronę w Local (np. `seo.local`) i uruchom ją.
2. Zamień jej katalog `wp-content` w kopię roboczą repozytorium:

   ```bash
   cd "<ścieżka strony>/app/public/wp-content"
   git init
   git remote add origin https://github.com/ohsofreshcreative/seo.git
   git fetch origin
   git switch -c <branch> --track origin/<branch>
   ```

   `<branch>` to zwykle `main` (struktura z `plugins/` i `themes/` w roocie) albo branch roboczy.
   Pliki WordPressa spoza whitelisty (uploads, domyślne motywy, `index.php`) zostają nietknięte.
3. Zainstaluj zależności motywu i zbuduj assety:

   ```bash
   cd themes/seo
   composer install
   yarn install
   yarn build
   ```

4. W panelu WordPress aktywuj motyw `seo` i plugin `OSF SEO`.
5. Włącz „ładne” odnośniki (Ustawienia → Bezpośrednie odnośniki → np. „Nazwa wpisu”) — panel działa
   pod ścieżkami `/login`, `/projects`… obsługiwanymi przez router Acorn.
6. Panel: `https://<twoja-strona>/login` (konto z rolą Administrator, `OSF SEO — Administrator`
   albo `OSF SEO — Klient`). Klientów przypisuje się do projektów przez
   `wp osf-seo project:assign <public_id> <login>`.

### Migracja z dotychczasowej kopii roboczej (repo w `themes/seo`)

Do tej pory root repozytorium był katalogiem motywu. Po reorganizacji:

```bash
cd "<ścieżka strony>/app/public/wp-content"

# 1. Odłóż starą kopię roboczą (ma własne .git, vendor/, node_modules/, ewentualnie .env i deploy/)
mv themes/seo ../seo-theme-old

# 2. Zamień wp-content w kopię roboczą repozytorium
git init
git remote add origin https://github.com/ohsofreshcreative/seo.git
git fetch origin
git switch -c <branch> --track origin/<branch>

# 3. Zależności motywu (albo przenieś vendor/ i node_modules/ ze starej kopii)
cd themes/seo && composer install && yarn install

# 4. Przenieś lokalne, nieśledzone pliki ze starej kopii, jeśli istnieją (np. .env, deploy/)
```

Gdy wszystko działa, usuń `../seo-theme-old`.

## Komendy

```bash
# motyw
cd themes/seo
yarn dev          # Vite dev server z HMR
yarn build        # build produkcyjny → public/build (śledzony w gicie)

# plugin (runtime nie wymaga Composera; Composer tylko dla testów)
cd plugins/osf-seo
composer install
composer test     # PHPUnit (testy jednostkowe)
composer lint     # php -l

# testy integracyjne: prawdziwy WordPress + OSOBNA, pusta baza testowa (nigdy baza strony!)
OSF_SEO_TEST_DB_NAME=osf_seo_test OSF_SEO_TEST_DB_USER=root OSF_SEO_TEST_DB_PASSWORD=... \
OSF_SEO_TEST_DB_HOST="localhost:/ścieżka/do/mysqld.sock" composer test:integration

# wydajność raportów na syntetycznych danych (ta sama OSOBNA baza testowa)
composer test:performance

# stan pluginu w WordPressie
wp osf-seo status
wp osf-seo db:status
wp osf-seo db:migrate

# Google Search Console (bez wypisywania tokenów)
wp osf-seo gsc:properties --project=<public_id>
wp osf-seo gsc:select-property --project=<public_id> --property=<site_url> [--reset-data]
wp osf-seo gsc:probe --project=<public_id> [--dimensions=query] [--limit=10]
wp osf-seo gsc:sync --project=<public_id> [--run] [--force]
wp osf-seo gsc:backfill --project=<public_id> [--run]
wp osf-seo gsc:status --project=<public_id> [--format=json]
wp osf-seo sync:run          # kolejka synchronizacji — dla crona systemowego (co minutę); potem szanse SEO, dane rynkowe i nowe frazy w tle

# Szanse SEO (analiza zapisanych danych GSC, bez wywołań Google)
wp osf-seo opportunities:analyze --project=<public_id> [--days=7|28|90] [--force]
wp osf-seo opportunities:list --project=<public_id> [--days=28] [--type=low_ctr] [--status=open] [--format=json]

# Dane rynkowe DataForSEO (płatne API — zawsze najpierw --dry-run; szczegóły: docs/ARCHITECTURE.md, sekcja 11)
wp osf-seo dataforseo:status [--project=<public_id>]            # konfiguracja bez sekretów, rynek, metryki, koszty, limity
wp osf-seo dataforseo:sync --project=<public_id> --dry-run --limit=10   # plan i szacowany koszt, bez żadnego żądania
wp osf-seo dataforseo:sync --project=<public_id> --limit=10     # płatna synchronizacja (w ramach limitów)
wp osf-seo dataforseo:keyword --project=<public_id> --keyword="fraza"   # zapisane metryki frazy (bez API)
wp osf-seo dataforseo:run --collect-only                        # odbiór wyników zadań wolumenu (bezpłatny)
wp osf-seo dataforseo:locations --country=PL                    # bezpłatna weryfikacja kodów rynku

# Nowe frazy (DataForSEO Labs — płatne; zawsze najpierw plan; szczegóły: docs/ARCHITECTURE.md, sekcja 12)
wp osf-seo discovery:suggest --project=<public_id>              # podpowiedzi seedów z GSC i szans SEO (bez API)
wp osf-seo discovery:plan --project=<public_id> --seeds="fraza 1, fraza 2" --depth=1 --limit=20   # plan i maks. koszt, zero żądań
wp osf-seo discovery:run --project=<public_id> --seeds="fraza 1, fraza 2" --depth=1 --limit=20    # płatne: plan → potwierdzenie → wykonanie
wp osf-seo discovery:status --project=<public_id> [--run=<id>]  # postęp, ostatnie przebiegi, koszty
wp osf-seo discovery:list --project=<public_id> [--status=open] [--visibility=gap] [--format=json]
wp osf-seo discovery:refresh --project=<public_id>              # przeliczenie widoczności GSC i priorytetu (bez API)
```

Synchronizacja działa w tle przez WP-Cron (lokalnie wystarczy ruch na stronie); na serwerze zalecany cron
systemowy z `wp osf-seo sync:run` — szczegóły w `docs/ARCHITECTURE.md`, sekcja 9.

WP-CLI w Local wymaga socketu MySQL strony — gotowy szablon komendy jest w `AGENTS.md`.

## Konfiguracja

Sekrety i konfigurację środowiska ustawiamy **wyłącznie** w `wp-config.php` (poza repozytorium)
lub w zmiennych środowiskowych. Przykłady zawierają tylko placeholdery:

```php
// wp-config.php — integracja z Google Search Console (placeholdery!)
define('OSF_SEO_GOOGLE_CLIENT_ID', 'your-client-id');
define('OSF_SEO_GOOGLE_CLIENT_SECRET', 'your-client-secret');
define('OSF_SEO_ENCRYPTION_KEY', 'base64:...'); // wygeneruj: wp osf-seo google:generate-key

// wp-config.php — DataForSEO (placeholdery! login i hasło z zakładki „API Access” panelu DataForSEO)
define('OSF_SEO_DATAFORSEO_LOGIN', 'your-dataforseo-api-login');
define('OSF_SEO_DATAFORSEO_PASSWORD', 'your-dataforseo-api-password');
// Opcjonalne bezpieczniki kosztów (domyślnie: 4 zadania na przebieg, 1 USD / dobę, 10 USD / miesiąc)
define('OSF_SEO_DATAFORSEO_MAX_TASKS_PER_RUN', 4);
define('OSF_SEO_DATAFORSEO_DAILY_COST_LIMIT', 1.00);
define('OSF_SEO_DATAFORSEO_MONTHLY_COST_LIMIT', 10.00);
```

- `OSF_SEO_ENCRYPTION_KEY` szyfruje refresh tokeny Google w bazie (32 losowe bajty w base64). Klucz
  musi być stały dla środowiska i mieć bezpieczną kopię — jego zmiana wymaga ponownego połączenia
  kont Google. Każde środowisko (Local, staging, produkcja) ma własny klucz.
- Authorized redirect URI w Google Cloud = `home_url('/oauth/google/callback')`, np.
  `https://seo.ohsofresh.top/oauth/google/callback`. Google wymaga `https` (poza `localhost`), więc
  lokalnie potrzebna jest strona Local z włączonym SSL i jej własny URI dopisany w Google Cloud.
- Stan konfiguracji (bez wartości sekretów): `wp osf-seo google:status` albo panel → Ustawienia.
- DataForSEO: dane logowania wyłącznie w `wp-config.php`/env (nigdy w repo, bazie, logach ani JS). Synchronizacja danych
  rynkowych nie startuje sama po wdrożeniu — pierwszą uruchamia się jawnie (panel → projekt → Dane rynkowe albo
  `wp osf-seo dataforseo:sync`), potem metryki starsze niż 30 dni odświeżają się w tle w ramach limitów.
- Nowe frazy: wyszukiwanie uruchamia się wyłącznie jawnie (panel → projekt → Nowe frazy → „Sprawdź koszt” → „Uruchom wyszukiwanie”
  albo `wp osf-seo discovery:run`) i liczy się do tych samych limitów kosztów co dane rynkowe. Opcjonalnie: `OSF_SEO_DISCOVERY_TTL_DAYS`
  (cache seeda, 30 dni), `OSF_SEO_DISCOVERY_MAX_SEEDS` (20), `OSF_SEO_DISCOVERY_MAX_CANDIDATES` (1000), `OSF_SEO_DISCOVERY_MIN_VOLUME` (10).

Pełna lista stałych: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md), sekcja 14.

## Bezpieczeństwo

- Repozytorium jest publiczne: żadnych sekretów w kodzie, testach, fixture'ach, dokumentacji
  i historii Git (także danych logowania DataForSEO — testy i CI używają wyłącznie atrapy HTTP).
- Wdrożenie wyłącznie zawężone: `plugins/osf-seo/` → `wp-content/plugins/osf-seo/`, `themes/seo/` → `wp-content/themes/seo/`
  — nigdy całe repozytorium do `wp-content`.
- Zgłoszenia problemów bezpieczeństwa kieruj bezpośrednio do zespołu OhSoFresh, nie przez publiczne issues.

## Licencja

Motyw bazuje na [Sage](https://roots.io/sage/) (licencja MIT — `themes/seo/LICENSE.md`).
