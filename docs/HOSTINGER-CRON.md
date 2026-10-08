# Cron na Hostingerze — zadania w tle Whack-a-mole

Prosta instrukcja uruchomienia zadań w tle aplikacji (plugin `osf-seo`) na hostingu Hostinger. Szczegóły techniczne:
`docs/ARCHITECTURE.md`, sekcje 9.1 (kolejka synchronizacji), 15.15 (przeliczenie Strategii w tle), 25.4–25.5 (analizy AI i pobieranie
stron z panelu) i 26.6 (odzyskiwanie kolejek). Nie zmieniaj istniejącego harmonogramu crona na serwerze bez sprawdzenia jego konfiguracji
(punkt 4) — ten sam jeden cron obsługuje wszystkie kroki.

> W instrukcji są wyłącznie **przykłady z placeholderami** (`<użytkownik>`, `<domena>`). Nie wpisuj do komendy crona haseł,
> kluczy ani loginów — sekrety są wyłącznie w `wp-config.php`. Konfiguracja crona na stagingu nie była weryfikowana przez agenta
> (brak dostępu do serwera z założenia) — sprawdź ją według punktu 4.

## 1. Co robi cron

Jedno wywołanie co minutę wykonuje kolejno:

1. kolejkę synchronizacji Google Search Console (import danych),
2. kroki po kolejce: przeliczenie Szans SEO, dane rynkowe, Nowe frazy, Luki SEO, Pozycje SERP,
3. **analizy AI zlecone w panelu** — wyłącznie zatwierdzone przez administratora (plan z kosztem maksymalnym); najwyżej 2 na przebieg,
   każda to jedno wywołanie bez ponowień; płatne tylko przy włączonym wyłączniku (`docs/AI-SETUP.md`). Cron sam niczego nie zleca.
4. **pobieranie stron zlecone w panelu** (Page Intelligence) — wyłącznie wybrane adresy, z odstępami między żądaniami do tej samej witryny.
5. **przeliczenie Strategii** — zlecenia z panelu („Zleć przeliczenie”) i automatyczne przeliczenie po zmianie danych modułów.
6. porządki historii analiz AI (odzyskanie przerwanych uruchomień, retencja) i retencja treści stron — **bez wywołań AI i bez pobierania**.

Przeliczenie Strategii jest lokalne: bez kosztów i bez żądań do API. Kroki płatne (DataForSEO) wykonują wyłącznie to, co zostało jawnie zlecone
albo włączone w panelu (harmonogramy są domyślnie wyłączone), zawsze w ramach limitów kosztów (1 USD dziennie, 10 USD miesięcznie) — cron
niczego nie włącza sam.

Bez crona zadania czekają: panel Strategii pokazuje „Oczekuje na przeliczenie”, zlecenia analiz AI i pobrań stron — ostrzeżenie „przetwarzanie
w tle nie działa” (po 15 minutach), a Ustawienia → „Przetwarzanie w tle” — czas ostatniego przebiegu.

## 2. Ustawienie w hPanel

1. Zaloguj się do hPanel, wybierz właściwą stronę i przejdź do **Zaawansowane** → **Cron Jobs** (Zadania Cron); nazwy pozycji menu
   mogą się nieco różnić zależnie od wersji hPanel.
2. Wybierz zadanie **własne** (Custom) i wpisz komendę (wariant A — zalecany, WP-CLI):

   ```
   wp --path=/home/<użytkownik>/domains/<domena>/public_html osf-seo sync:run --time-limit=50 > /dev/null 2>&1
   ```

   Jeśli cron nie znajduje `wp` albo `php`, podaj pełne ścieżki — sprawdzisz je przez SSH komendami `command -v wp` i `command -v php`
   (np. `<ścieżka do php> <ścieżka do wp> --path=… osf-seo sync:run --time-limit=50`). Katalog z `--path` to katalog z plikiem `wp-config.php`.

   **Dla analiz AI wariant A jest wymagany w praktyce:** WP-CLI działa bez limitu czasu serwera WWW, a płatne wywołanie modelu może trwać do
   `OSF_SEO_AI_TIMEOUT` (zalecane 240 s). W wariancie B żądanie przechodzi przez serwer WWW (LiteSpeed / PHP-FPM) — przerwanie procesu w trakcie
   wywołania kończy analizę jako „wynik niepewny” (pełna rezerwacja kosztu, bez wyniku i bez ponowienia).

   Wariant B — bez WP-CLI (wywołanie WP-Cron z zewnątrz; wystarcza dla GSC i Strategii, nie zalecany przy płatnych analizach AI):

   ```
   wget -q -O - "https://<domena>/wp-cron.php?doing_wp_cron" > /dev/null 2>&1
   ```

3. Częstotliwość: **co minutę** (`* * * * *`). Jeśli plan hostingu nie pozwala na co minutę — co 5 minut (`*/5 * * * *`); wtedy zlecone
   przeliczenie wykona się z opóźnieniem do 5 minut.
4. Zapisz zadanie.

## 3. `wp-config.php`

Przy cronie systemowym wyłącz uruchamianie WP-Cron przez ruch na stronie (cron systemowy przejmuje tę rolę):

```php
define( 'DISABLE_WP_CRON', true );
```

Wariant B (wget) działa także z tą stałą — wywołuje `wp-cron.php` bezpośrednio.

## 4. Sprawdzenie, że działa

- Panel → projekt → **Strategia** → **Ustawienia**: „Ostatni krok w tle” powinien pokazywać czas sprzed najwyżej 1–2 minut (przy cronie co minutę).
- Test: na stronie Strategii kliknij **„Zleć przeliczenie”** — pasek stanu zmienia się na „Oczekuje na przeliczenie”, a w ciągu około minuty
  (strona odświeża się sama) na „Zakończono”.
- Przez SSH (opcjonalnie):

  ```
  wp --path=/home/<użytkownik>/domains/<domena>/public_html osf-seo strategy:queue
  wp --path=/home/<użytkownik>/domains/<domena>/public_html osf-seo status
  ```

  `strategy:queue` pokazuje „Last background step” (ostatni krok Strategii w tle) i „last sync queue run” (ostatni przebieg kolejki GSC);
  `status` — stan pluginu i kolejki.

  ```
  wp --path=/home/<użytkownik>/domains/<domena>/public_html osf-seo ai:queue
  wp --path=/home/<użytkownik>/domains/<domena>/public_html osf-seo pages:jobs
  ```

  Oba pokazują liczby zleceń, najstarsze czekające zlecenie i „Last background step” — z ostrzeżeniem, gdy zlecenia czekają, a tło nie działa.

## 5. Gdy coś nie działa

| Objaw | Co sprawdzić |
|---|---|
| „Ostatni krok w tle: jeszcze nie działał” albo ostrzeżenie w panelu | Czy zadanie cron jest zapisane i aktywne, czy ścieżka `--path` wskazuje katalog z `wp-config.php`, czy `wp`/`php` mają pełne ścieżki. |
| Zadanie Strategii z błędem „proces w tle został przerwany” | Limit czasu albo pamięci PHP na serwerze — zmniejsz `--time-limit` (np. 40) i sprawdź `memory_limit` (przeliczenie projektu z 5 000 fraz potrzebuje ok. 60 MB ponad WordPress). Zadanie ponawia się samo (do 3 prób). |
| „Błąd przeliczenia” po 3 próbach | Szczegóły w logu pluginu (bez danych dostępowych). Po usunięciu przyczyny kliknij „Zleć przeliczenie” albo uruchom `wp … osf-seo strategy:refresh --project=<id>`. |
| Zadania czekają mimo działającego crona | `wp … osf-seo strategy:queue --run` wykonuje krok w tle raz, ręcznie, i pokazuje wynik. |

## 5a. Odzyskiwanie po przerwie crona albo awarii procesu

Kolejki nie zostają „niewidocznie” zablokowane — każde zlecenie kończy się jednym ze stanów poniżej (bez ręcznej edycji bazy):

| Sytuacja | Co się dzieje | Koszt |
|---|---|---|
| Cron nie działał krócej niż 6 h | po wznowieniu analizy AI wykonują się w kolejności zlecenia (plan przeliczany ponownie — inne dane = `plan_changed`, bez wywołania) | jak zatwierdzono |
| Analiza AI czekała ponad 6 h | `queue_expired` — bez wywołania, rezerwacja zwolniona; zleć ponownie | 0 |
| Zlecenie pobrania stron czekało ponad 24 h | `job_expired` — bez pobierania; zleć ponownie | — |
| Proces przerwany **przed** wysłaniem żądania do modelu | `not_sent` — bez kosztu | 0 |
| Proces przerwany **po** wysłaniu żądania (timeout, restart, limit czasu) | po timeoucie + 5 min `uncertain`; bez automatycznego ponowienia | pełna rezerwacja |
| Przebieg pobierania stron przerwany | po 5 min bez znaku życia zlecenie wraca do kolejki (pobrania są bezpłatne; świeża kopia = pamięć) | — |
| Błąd jednej analizy w kolejce (np. błąd bazy) | ta analiza `internal_error` bez wywołania, kolejne wykonują się normalnie | 0 |
| Projekt zarchiwizowany po zleceniu | `project_unavailable` — bez wywołania i bez pobierania | 0 |

Zlecenie czekające w kolejce administrator może anulować w panelu (analiza: bez kosztu; pobranie stron: pobrane pozycje zostają).

## 6. Bezpieczeństwo

- Komenda crona nie zawiera żadnych sekretów; dane dostępowe (Google, DataForSEO, klucz szyfrujący) tylko w `wp-config.php`.
- Wdrożenie pozostaje zawężone: `plugins/osf-seo/` → `wp-content/plugins/osf-seo/`, `themes/seo/` → `wp-content/themes/seo/` — nigdy całe
  repozytorium do `wp-content` (patrz `AGENTS.md`, sekcja 13).
