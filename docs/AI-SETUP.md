# Analizy AI — konfiguracja dostawcy (Whack-a-mole)

Prosta instrukcja włączenia analiz AI tematów Strategii (plugin `osf-seo`, STEP 17). Szczegóły techniczne: `docs/ARCHITECTURE.md`, sekcja 22.

> W instrukcji są wyłącznie **placeholdery** (`your-openai-api-key`, `your-model-id`, `<cena>`). Prawdziwego klucza nie wpisuj do repozytorium,
> dokumentacji, zgłoszeń, czatu ani panelu — wyłącznie do `wp-config.php` na serwerze (albo zmiennych środowiskowych).

## 1. Jak działa domyślnie

Po wdrożeniu **nic nie generuje kosztów**:

- rzeczywiste wywołania AI są wyłączone (`OSF_SEO_AI_ENABLED` nieustawione),
- nie ma domyślnego modelu ani cen — bez cen aplikacja nie potrafi oszacować kosztu i odmawia,
- limity budżetu AI wynoszą 0 USD — każde płatne wywołanie jest blokowane,
- analizy nie uruchamiają się same (ani w panelu, ani w tle); budżet AI jest oddzielny od limitów DataForSEO (1 USD / 10 USD — bez zmian).

Działa wyłącznie dostawca testowy `fake` (koszt 0, bez internetu) — do sprawdzenia całego przepływu:

```
wp osf-seo ai:run --project=<id-projektu> --topic="<fraza tematu>"
```

## 2. Włączenie OpenAI (świadoma decyzja administratora)

Do dostawcy trafiają dane tematu projektu: frazy, metryki GSC, adresy stron, domeny i tytuły wyników SERP. Nie trafiają: notatki wewnętrzne,
dane użytkowników, dane innych projektów, klucze i tokeny. Żądania są wysyłane z `store: false`.

1. W panelu OpenAI utwórz klucz API projektu (najlepiej osobny projekt z limitem wydatków ustawionym także po stronie OpenAI).
2. Wybierz model i sprawdź jego **aktualny cennik** w panelu dostawcy (ceny za 1 mln tokenów wejścia, wejścia z cache i wyjścia).
3. Dopisz do `wp-config.php` (nad linią „That's all, stop editing!”):

```php
// Analizy AI (STEP 17) — placeholdery! Klucz wyłącznie tutaj, nigdy w repozytorium.
define('OSF_SEO_OPENAI_API_KEY', 'your-openai-api-key');
define('OSF_SEO_AI_PROVIDER', 'openai');
define('OSF_SEO_AI_MODEL', 'your-model-id');

// Ceny wybranego modelu w USD za 1 mln tokenów — przepisz z cennika dostawcy.
define('OSF_SEO_AI_PRICE_INPUT_PER_MTOK', '<cena>');
define('OSF_SEO_AI_PRICE_CACHED_INPUT_PER_MTOK', '<cena>'); // opcjonalnie; bez niej — cena zwykłego wejścia
define('OSF_SEO_AI_PRICE_OUTPUT_PER_MTOK', '<cena>');

// Budżet AI w USD (oddzielny od DataForSEO). Zacznij od niskich wartości.
define('OSF_SEO_AI_MAX_RUN_COST', '0.05');          // maks. koszt jednej analizy (szacunek maksymalny)
define('OSF_SEO_AI_DAILY_LIMIT', '0.50');
define('OSF_SEO_AI_MONTHLY_LIMIT', '5.00');
define('OSF_SEO_AI_PROJECT_MONTHLY_LIMIT', '2.00');

// Wyłącznik — włącz dopiero po sprawdzeniu konfiguracji (krok 4).
define('OSF_SEO_AI_ENABLED', '1');
```

Opcjonalnie: `OSF_SEO_AI_MAX_OUTPUT_TOKENS` (domyślnie 3000; obejmuje tokeny rozumowania — dla analiz rekomendacji zalecane 8000),
`OSF_SEO_AI_TIMEOUT` (90 s; zalecane 240), `OSF_SEO_AI_REASONING_EFFORT` (`none|minimal|low|medium|high|xhigh|max` — wysyłany tylko, gdy
ustawiony; wartość nieobsługiwana przez model = odmowa dostawcy bez kosztu, wartość spoza listy blokuje płatne wywołanie),
`OSF_SEO_AI_PRICE_CACHE_WRITE_PER_MTOK` (tylko gdy cennik modelu nalicza zapis do cache inaczej niż wejście), `OSF_SEO_AI_TEMPERATURE`
(wysyłana tylko, gdy ustawiona — modele rozumujące zwykle jej nie obsługują; nie ustawiaj), `OSF_SEO_AI_RETENTION_DAYS` (historia,
domyślnie 180 dni).

Tryb kontrolowanego testu (faza E): `OSF_SEO_AI_ALLOWED_PROJECTS` (identyfikatory publiczne projektów, rozdzielone przecinkami) i
`OSF_SEO_AI_ALLOWED_TYPES` (`page-optimization`, `new-page-brief`, `content-gap`, `topic-analysis`) — płatne analizy tylko w tym zakresie,
sprawdzane przy planie i ponownie przy wykonaniu z kolejki. Procedura pierwszych płatnych testów: **`docs/AI-LIVE-TESTING.md`**.

Adapter wysyła `service_tier: default` (ceny z konfiguracji = przetwarzanie standardowe) i `store: false`; z odpowiedzi czyta tylko
wiadomości końcowe (`final_answer`), zużycie (także tokeny z cache i rozumowania) oraz model i poziom przetwarzania (do logu).

## 3. Uprawnienia

Analizy AI wymagają uprawnienia `osf_seo_manage_ai` — mają je wyłącznie administratorzy (rola WordPressa `administrator` i rola aplikacji
„OSF SEO — Administrator”). Klienci nie widzą analiz AI ani kosztów.

## 4. Sprawdzenie przed pierwszym płatnym wywołaniem

```
wp osf-seo ai:status                                                        # wyłącznik, dostawca, model, czy klucz jest ustawiony (bez wartości), ceny, limity
wp osf-seo ai:validate-context --project=<id> --topic="<fraza>"            # kontekst: determinizm, rozmiar, braki danych
wp osf-seo ai:plan --project=<id> --topic="<fraza>" --provider=openai      # plan: tokeny, maksymalny koszt, budżet, powody blokady (zero żądań)
```

`ai:plan` musi pokazać „Runnable (requires confirmation)”. Najczęstsze powody blokady:

| Kod | Co zrobić |
|---|---|
| `ai_disabled` | ustaw `OSF_SEO_AI_ENABLED` na `1` |
| `provider_not_configured` | `OSF_SEO_AI_PROVIDER` = `openai` |
| `missing_api_key` | ustaw `OSF_SEO_OPENAI_API_KEY` w `wp-config.php` |
| `model_not_configured` | ustaw `OSF_SEO_AI_MODEL` (litery, cyfry, `.`, `-`, `_`, `:`) |
| `missing_prices` | ustaw ceny wejścia i wyjścia |
| `run_cost_limit`, `budget_daily`, `budget_monthly`, `budget_project` | podnieś odpowiedni limit albo poczekaj na nowy dzień / miesiąc (UTC) |
| `context_too_large` | kontekst tematu przekracza 32 KB mimo redukcji — zgłoś zespołowi |
| `project_not_allowed`, `type_not_allowed` | tryb kontrolowanego testu — dopisz projekt / typ do `OSF_SEO_AI_ALLOWED_*` |
| `invalid_reasoning_effort` | popraw `OSF_SEO_AI_REASONING_EFFORT` (jedna z wartości z rozdziału 2) |

## 5. Pierwsze uruchomienie

```
wp osf-seo ai:run --project=<id> --topic="<fraza>" --provider=openai       # pyta o potwierdzenie maksymalnego kosztu (albo --yes)
wp osf-seo ai:show --project=<id> --run=<id-uruchomienia>                  # wynik z odwołaniami do dowodów i raport walidacji
wp osf-seo ai:budget --project=<id>                                        # wydatki AI dziś i w miesiącu
```

Porównaj koszt z historii (`charged`) z panelem rozliczeń dostawcy. Wynik niepewny (`uncertain` — np. timeout) liczy się do limitów pełną
rezerwacją i **nie jest ponawiany** automatycznie.

Analizy rekomendacji (faza C — optymalizacja strony, brief kandydata, luki treści; `docs/ARCHITECTURE.md`, sekcja 24) zatwierdzają **dokładny plan**:

```
wp osf-seo ai:readiness --project=<id> --topic="<fraza>" --type=page-optimization          # gotowość z zapisanych danych (zero żądań)
wp osf-seo ai:plan --project=<id> --topic="<fraza>" --type=page-optimization --provider=openai   # koszt maks., blokady i odcisk planu
wp osf-seo ai:generate --project=<id> --topic="<fraza>" --type=page-optimization --provider=openai   # pyta o potwierdzenie tego planu
wp osf-seo ai:generate … --provider=openai --yes --plan=<odcisk planu z ai:plan>           # bez pytania — tylko z odciskiem zatwierdzonego planu
```

Zmiana kontekstu (np. nowy snapshot strony), modelu, cen albo limitu tokenów po podglądzie → odmowa `plan_changed` (trzeba ponownie
obejrzeć plan). Gotowość `insufficient` / `blocked` → odmowa bez żadnego wywołania.

Jedno zatwierdzenie = jedno wywołanie: plan już wysłany i rozliczony bez gotowego wyniku (wynik niepewny, niepoprawny, przerwany na limicie)
nie zostanie wysłany ponownie przez ponowne przesłanie formularza ani `--plan` (`plan_already_used`) — tylko świadomie (`--repeat` albo
„Wyślij ponownie świadomie” w panelu, nowy koszt). Odmowa dostawcy bez kosztu (np. 429) nie zużywa zatwierdzenia.

W panelu (faza D) analizę zleca się na ekranie przygotowania (temat → „Analiza AI” → Przygotuj); wykonuje ją przetwarzanie w tle (cron —
`docs/HOSTINGER-CRON.md`). Ocena jakości wyników przez eksperta: `wp osf-seo ai:eval …` (`docs/AI-LIVE-TESTING.md`, rozdział 6).

## 6. Wyłączenie

Usuń albo ustaw na `0` stałą `OSF_SEO_AI_ENABLED` — płatne wywołania są natychmiast blokowane (historia zostaje), a płatne analizy
czekające w kolejce panelu kończą się bez wywołania (`ai_disabled`, rezerwacja zwolniona). Dostawca testowy działa dalej. Klucz można usunąć
z `wp-config.php` w dowolnej chwili; po rotacji klucza w panelu dostawcy wystarczy podmienić wartość stałej.
