# OSF SEO

Wewnętrzne narzędzie SEO agencji OhSoFresh: projekty klientów połączone z Google Search Console,
automatyczne wykrywanie fraz, porównania okresów, wzrosty i spadki, szanse SEO oraz dashboardy.
Jedynym źródłem danych w MVP jest Google Search Console API — koszt zewnętrznych usług: 0 zł.

> **Status:** fundament (plugin: bootstrap, role i uprawnienia, logger, `wp osf-seo status`).
> Aplikacja nie ma jeszcze panelu ani integracji z Google — plan i postęp prac:
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

   `<branch>` to `main` po zmergowaniu reorganizacji repozytorium — do tego czasu `main` ma
   jeszcze starą strukturę (motyw w roocie), więc użyj brancha roboczego.
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

# stan pluginu w WordPressie
wp osf-seo status
wp osf-seo db:status
wp osf-seo db:migrate
```

WP-CLI w Local wymaga socketu MySQL strony — gotowy szablon komendy jest w `AGENTS.md`.

## Konfiguracja

Sekrety i konfigurację środowiska ustawiamy **wyłącznie** w `wp-config.php` (poza repozytorium)
lub w zmiennych środowiskowych. Przykłady zawierają tylko placeholdery:

```php
// wp-config.php — integracja z Google Search Console (placeholdery!)
define('OSF_SEO_GOOGLE_CLIENT_ID', 'your-client-id');
define('OSF_SEO_GOOGLE_CLIENT_SECRET', 'your-client-secret');
define('OSF_SEO_ENCRYPTION_KEY', 'base64:...'); // wygeneruj: wp osf-seo google:generate-key
```

- `OSF_SEO_ENCRYPTION_KEY` szyfruje refresh tokeny Google w bazie (32 losowe bajty w base64). Klucz
  musi być stały dla środowiska i mieć bezpieczną kopię — jego zmiana wymaga ponownego połączenia
  kont Google. Każde środowisko (Local, staging, produkcja) ma własny klucz.
- Authorized redirect URI w Google Cloud = `home_url('/oauth/google/callback')`, np.
  `https://seo.ohsofresh.top/oauth/google/callback`. Google wymaga `https` (poza `localhost`), więc
  lokalnie potrzebna jest strona Local z włączonym SSL i jej własny URI dopisany w Google Cloud.
- Stan konfiguracji (bez wartości sekretów): `wp osf-seo google:status` albo panel → Ustawienia.

Pełna lista stałych: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md), sekcja 12.

## Bezpieczeństwo

- Repozytorium jest publiczne: żadnych sekretów w kodzie, testach, fixture'ach, dokumentacji
  i historii Git.
- Zgłoszenia problemów bezpieczeństwa kieruj bezpośrednio do zespołu OhSoFresh, nie przez publiczne issues.

## Licencja

Motyw bazuje na [Sage](https://roots.io/sage/) (licencja MIT — `themes/seo/LICENSE.md`).
