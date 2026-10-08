# Cron na Hostingerze — zadania w tle Whack-a-mole

Prosta instrukcja uruchomienia zadań w tle aplikacji (plugin `osf-seo`) na hostingu Hostinger. Szczegóły techniczne:
`docs/ARCHITECTURE.md`, sekcje 9.1 (kolejka synchronizacji) i 15.15 (przeliczenie Strategii w tle).

> W instrukcji są wyłącznie **przykłady z placeholderami** (`<użytkownik>`, `<domena>`). Nie wpisuj do komendy crona haseł,
> kluczy ani loginów — sekrety są wyłącznie w `wp-config.php`. Konfiguracja crona na stagingu nie była weryfikowana przez agenta
> (brak dostępu do serwera z założenia) — sprawdź ją według punktu 4.

## 1. Co robi cron

Jedno wywołanie co minutę wykonuje kolejno:

1. kolejkę synchronizacji Google Search Console (import danych),
2. kroki po kolejce: przeliczenie Szans SEO, dane rynkowe, Nowe frazy, Luki SEO, Pozycje SERP,
3. **przeliczenie Strategii** — zlecenia z panelu („Zleć przeliczenie”) i automatyczne przeliczenie po zmianie danych modułów.

Przeliczenie Strategii jest lokalne: bez kosztów i bez żądań do API. Kroki płatne (DataForSEO) wykonują wyłącznie to, co zostało jawnie zlecone
albo włączone w panelu (harmonogramy są domyślnie wyłączone), zawsze w ramach limitów kosztów (1 USD dziennie, 10 USD miesięcznie) — cron
niczego nie włącza sam.

Bez crona zadania czekają: panel Strategii pokazuje „Oczekuje na przeliczenie”, a administrator widzi ostrzeżenie, że zadania w tle nie działają.

## 2. Ustawienie w hPanel

1. Zaloguj się do hPanel, wybierz właściwą stronę i przejdź do **Zaawansowane** → **Cron Jobs** (Zadania Cron); nazwy pozycji menu
   mogą się nieco różnić zależnie od wersji hPanel.
2. Wybierz zadanie **własne** (Custom) i wpisz komendę (wariant A — zalecany, WP-CLI):

   ```
   wp --path=/home/<użytkownik>/domains/<domena>/public_html osf-seo sync:run --time-limit=50 > /dev/null 2>&1
   ```

   Jeśli cron nie znajduje `wp` albo `php`, podaj pełne ścieżki — sprawdzisz je przez SSH komendami `command -v wp` i `command -v php`
   (np. `<ścieżka do php> <ścieżka do wp> --path=… osf-seo sync:run --time-limit=50`). Katalog z `--path` to katalog z plikiem `wp-config.php`.

   Wariant B — bez WP-CLI (wywołanie WP-Cron z zewnątrz):

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

## 5. Gdy coś nie działa

| Objaw | Co sprawdzić |
|---|---|
| „Ostatni krok w tle: jeszcze nie działał” albo ostrzeżenie w panelu | Czy zadanie cron jest zapisane i aktywne, czy ścieżka `--path` wskazuje katalog z `wp-config.php`, czy `wp`/`php` mają pełne ścieżki. |
| Zadanie Strategii z błędem „proces w tle został przerwany” | Limit czasu albo pamięci PHP na serwerze — zmniejsz `--time-limit` (np. 40) i sprawdź `memory_limit` (przeliczenie projektu z 5 000 fraz potrzebuje ok. 60 MB ponad WordPress). Zadanie ponawia się samo (do 3 prób). |
| „Błąd przeliczenia” po 3 próbach | Szczegóły w logu pluginu (bez danych dostępowych). Po usunięciu przyczyny kliknij „Zleć przeliczenie” albo uruchom `wp … osf-seo strategy:refresh --project=<id>`. |
| Zadania czekają mimo działającego crona | `wp … osf-seo strategy:queue --run` wykonuje krok w tle raz, ręcznie, i pokazuje wynik. |

## 6. Bezpieczeństwo

- Komenda crona nie zawiera żadnych sekretów; dane dostępowe (Google, DataForSEO, klucz szyfrujący) tylko w `wp-config.php`.
- Wdrożenie pozostaje zawężone: `plugins/osf-seo/` → `wp-content/plugins/osf-seo/`, `themes/seo/` → `wp-content/themes/seo/` — nigdy całe
  repozytorium do `wp-content` (patrz `AGENTS.md`, sekcja 13).
