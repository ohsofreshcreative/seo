# Kontrolowany test OpenAI na stagingu (Whack-a-mole)

Procedura pierwszych **płatnych** analiz AI (plugin `osf-seo`, STEP 17 faza E). Szczegóły techniczne: `docs/ARCHITECTURE.md`, sekcje 22–26;
konfiguracja dostawcy: `docs/AI-SETUP.md`; cron: `docs/HOSTINGER-CRON.md`.

> **Każde płatne wywołanie wymaga osobnej, jawnej zgody właściciela.** Samo wdrożenie fazy E niczego nie wywołuje: wyłącznik jest domyślnie
> wyłączony, limity wynoszą 0 USD, a analiza wykonuje się wyłącznie po zatwierdzeniu konkretnego planu (koszt maksymalny + odcisk planu).
> W dokumencie są wyłącznie **placeholdery** (`your-openai-api-key`, `your-model-id`, `<cena>`, `<ID projektu>`). Klucza nie wpisuj do
> repozytorium, czatu, zgłoszeń ani panelu — wyłącznie do `wp-config.php` na serwerze.

## 1. Zasady testu

- Jeden projekt (OhSoFresh) i jeden typ analizy naraz — wymuszane przez `OSF_SEO_AI_ALLOWED_PROJECTS` i `OSF_SEO_AI_ALLOWED_TYPES`.
- Jedno zatwierdzenie = jedno wywołanie: aplikacja nie ponawia żądań; ten sam plan wysłany ponownie (np. po wyniku niepewnym) wymaga
  świadomego „Wyślij ponownie” (nowy koszt).
- Równoległe wywołania są blokowane (blokada zlecenia projekt × temat × typ).
- Niski budżet: limit jednej analizy, dzienny, miesięczny i projektu — ustawione tak, by pomyłka kosztowała grosze.
- Natychmiastowy powrót do dostawcy testowego: `OSF_SEO_AI_ENABLED` → `0` (rozdział 8).
- Wyniki testu nie są rekomendacjami dla klienta, dopóki nie przejdą oceny eksperta (rozdział 6) — ocena nie zmienia wyniku ani Strategii.

## 2. Konfiguracja na Hostingerze (`wp-config.php`)

Przed edycją zrób kopię `wp-config.php`. Wpisz nad linią „That's all, stop editing!”:

```php
// Analizy AI — kontrolowany test (placeholdery! wartości tylko na serwerze).
define('OSF_SEO_OPENAI_API_KEY', 'your-openai-api-key');
define('OSF_SEO_AI_PROVIDER', 'openai');
define('OSF_SEO_AI_MODEL', 'your-model-id');            // model z Responses API i Structured Outputs (patrz 2.1)

// Cennik wybranego modelu (USD za 1 mln tokenów) — przepisz z oficjalnego cennika w dniu testu. Bez cen = odmowa.
define('OSF_SEO_AI_PRICE_INPUT_PER_MTOK', '<cena>');
define('OSF_SEO_AI_PRICE_CACHED_INPUT_PER_MTOK', '<cena>');   // opcjonalnie; bez niej — cena zwykłego wejścia
define('OSF_SEO_AI_PRICE_OUTPUT_PER_MTOK', '<cena>');
// define('OSF_SEO_AI_PRICE_CACHE_WRITE_PER_MTOK', '<cena>'); // tylko gdy cennik modelu nalicza zapis do cache inaczej niż wejście

// Odpowiedź: limit tokenów (obejmuje rozumowanie), wysiłek rozumowania, czas oczekiwania.
define('OSF_SEO_AI_MAX_OUTPUT_TOKENS', '8000');
define('OSF_SEO_AI_REASONING_EFFORT', 'low');            // none|minimal|low|medium|high|xhigh|max — tylko wartość obsługiwana przez model
define('OSF_SEO_AI_TIMEOUT', '240');

// Budżet testu (oddzielny od DataForSEO) — niskie limity.
define('OSF_SEO_AI_MAX_RUN_COST', '0.20');
define('OSF_SEO_AI_DAILY_LIMIT', '0.60');
define('OSF_SEO_AI_MONTHLY_LIMIT', '2.00');
define('OSF_SEO_AI_PROJECT_MONTHLY_LIMIT', '2.00');

// Tryb kontrolowanego testu: tylko ten projekt i ten typ (zmieniaj typ między scenariuszami).
define('OSF_SEO_AI_ALLOWED_PROJECTS', '<ID projektu OhSoFresh>');
define('OSF_SEO_AI_ALLOWED_TYPES', 'page-optimization');

// Wyłącznik — ustaw na '1' dopiero po sprawdzeniu z rozdziału 3.
define('OSF_SEO_AI_ENABLED', '0');
```

Nie ustawiaj `OSF_SEO_AI_TEMPERATURE` (modele rozumujące mogą odrzucić ten parametr — odmowa bez kosztu, ale test się nie odbędzie).

### 2.1 Model

- Adapter używa **Responses API** (`POST /v1/responses`) z odpowiedzią strukturalną `text.format = json_schema` (`strict: true`),
  `store: false` i `service_tier: default`. Wybierz model, który według aktualnej dokumentacji OpenAI obsługuje Responses API i Structured
  Outputs. Aplikacja nie zna żadnego modelu ani cennika — identyfikator i ceny są wyłącznie w konfiguracji.
- `OSF_SEO_AI_MAX_OUTPUT_TOKENS` obejmuje także tokeny rozumowania. Odpowiedź (kontrakt v2) jest duża — przy 3000 tokenów (domyślne)
  model rozumujący często kończy na limicie (`incomplete_max_output_tokens`, koszt naliczony, bez wyniku). Na test: 8000 i wysiłek `low`.
- `service_tier: default` = przetwarzanie standardowe; ceny z konfiguracji muszą dotyczyć tego poziomu. Gdy odpowiedź zgłosi inny poziom,
  aplikacja zapisze ostrzeżenie w logu.

### 2.2 Koszt maksymalny

Plan pokazuje koszt maksymalny przed zatwierdzeniem: `tokeny wejścia (szacunek) × cena wejścia + limit tokenów odpowiedzi × cena wyjścia`.
Szacunek wejścia jest celowo zawyżony (2,5 bajta na token + 300). Dla typowego tematu (kontekst ok. 24 KB, instrukcje ok. 7 KB, schemat
ok. 6 KB) to ok. 15–16 tys. tokenów wejścia; maksymalnie (kontekst 32 KB) ok. 18,5 tys. Przy limicie odpowiedzi 8000:
`koszt maks. ≈ 16 000 × <cena wejścia> / 1 000 000 + 8 000 × <cena wyjścia> / 1 000 000`. Ustaw `OSF_SEO_AI_MAX_RUN_COST` nieco powyżej
tej wartości — wyższy plan zostanie zablokowany (`run_cost_limit`).

## 3. Sprawdzenie przed pierwszą płatną analizą (zero żądań)

1. Wdrożenie zawężone (`plugins/osf-seo/` i `themes/seo/`), migracje: `wp osf-seo db:migrate` (schemat 19).
2. Cron systemowy działa (`docs/HOSTINGER-CRON.md`): panel → Ustawienia → „Przetwarzanie w tle: ostatni przebieg …” sprzed 1–2 minut;
   `wp osf-seo ai:queue` bez ostrzeżenia.
3. `wp osf-seo ai:status` — wyłącznik `0`, dostawca `openai`, model, „klucz ustawiony” (bez wartości), ceny, limity, `reasoning_effort`,
   `live_test` (projekt i typ).
4. Gotowość i plan dla wybranego tematu (rozdział 4) — **bez wywołania**:
   ```
   wp osf-seo ai:readiness --project=<ID> --topic="<temat>" --type=page-optimization
   wp osf-seo ai:plan --project=<ID> --topic="<temat>" --type=page-optimization --provider=openai
   ```
   Przy wyłączniku `0` plan pokaże blokadę `ai_disabled` — to oczekiwane. Sprawdź koszt maksymalny i brak innych blokad.
5. Dostawca testowy na tym samym temacie (bez kosztu) — w panelu: temat → „Analiza AI” → Przygotuj → „Zleć analizę (bez kosztów)”;
   raport bez kodów technicznych, wydruk i kopiowanie działają.
6. Zgoda właściciela na scenariusz i koszt maksymalny (rozdział 4) → `OSF_SEO_AI_ENABLED` = `1`.

## 4. Scenariusze (każdy = osobna zgoda, jedno wywołanie)

| # | Typ (`OSF_SEO_AI_ALLOWED_TYPES`) | Temat | Dane do zebrania wcześniej (bez kosztu AI) | Przypadki z katalogu |
|---|---|---|---|---|
| 1 | `page-optimization` | „strony internetowe warszawa” (działanie optimize, znana strona) | świeża kopia strony docelowej (Pobierz stronę), pomiar SERP ≤ 30 dni, 2–3 kopie stron konkurencji | A, F, K |
| 2 | `new-page-brief` | temat z działaniem „Kandydat na nową stronę” | pomiar SERP, kopie 2–3 stron z TOP10, inne tematy projektu ze stronami | D, L |
| 3 | `content-gap` | ten sam temat co w scenariuszu 1 | kopia strony projektu + 2–3 kopie konkurencji z TOP10 tej samej intencji | E, H, I, J |

Przebieg każdego scenariusza:

1. Panel → temat → „Analiza AI” → wybierz typ → **Przygotuj**. Ekran pokazuje gotowość, braki danych, podgląd danych wejściowych,
   koszt maksymalny i budżet. Uzupełnij braki (pobranie stron — bezpłatne, bez AI) i przygotuj ponownie.
2. Zapisz w notatkach testu: identyfikator tematu, odcisk planu, koszt maksymalny, gotowość i ograniczenia.
3. Po zgodzie: zaznacz akceptację kosztu → **Zleć płatną analizę**. Przetwarzanie w tle wykona jedno wywołanie (zwykle do kilku minut).
4. Po zakończeniu: raport (wynik), `wp osf-seo ai:show --project=<ID> --run=<ID>` (tokeny, koszt z zużycia, walidacja),
   `wp osf-seo ai:budget --project=<ID>`. Porównaj koszt z panelem rozliczeń OpenAI (następnego dnia).
5. Ocena eksperta (rozdział 6) — przed jakimkolwiek użyciem wyniku.
6. Zmień `OSF_SEO_AI_ALLOWED_TYPES` na typ kolejnego scenariusza (albo wyłącz AI do następnej zgody).

Zachowaj: identyfikator uruchomienia, raport (eksport tekstowy), wynik `ai:show --payload` (wejście i surowa odpowiedź — tylko na serwerze,
nie w repozytorium), ocenę `ai:eval` i notatki z kalibracji.

## 5. Kryteria jakości

Rubryka (`wp osf-seo ai:eval-criteria`) — 10 kryteriów w skali 1–5 albo „nie dotyczy”, bez łącznego wyniku:

| Kryterium | Pytanie kontrolne |
|---|---|
| `specificity` | Czy rekomendacje dotyczą tej strony, tych fraz i tych danych? |
| `gsc_consistency` | Czy odczyt GSC jest poprawny (średnia pozycja ≠ pozycja w Google, CTR = kliknięcia / wyświetlenia)? |
| `page_consistency` | Czy twierdzenia o stronie zgadzają się z kopią i nie wykraczają poza nią? |
| `serp_interpretation` | Czy SERP i konkurencja są interpretowane właściwie (data pomiaru, typy wyników)? |
| `intent_accuracy` | Czy intencja i typ strony są trafne? |
| `business_usefulness` | Czy da się na tej podstawie podjąć decyzję? |
| `feasibility` | Czy rekomendacje da się wdrożyć bez nieuzasadnionych zmian? |
| `evidence_correctness` | Czy dowody naprawdę wspierają twierdzenia? |
| `uncertainty_honesty` | Czy braki danych są ujawnione, a pewność odpowiada dowodom? |
| `no_hallucinations` | Czy wszystkie fakty pochodzą z danych analizy? |

Przypadki A–L (dane wejściowe, zakres, pułapki, kryteria sukcesu, wnioski zakazane): `wp osf-seo ai:eval-cases`.

## 6. Ocena wyniku

```
wp osf-seo ai:eval --user=<login administratora> --project=<ID> --run=<ID> --case=A \
  --scores="specificity=4,gsc_consistency=5,page_consistency=4,serp_interpretation=na,intent_accuracy=4,business_usefulness=3,feasibility=4,evidence_correctness=4,uncertainty_honesty=5,no_hallucinations=5" \
  --issues="generic_advice@R3:ogólna porada o linkowaniu" --verdict=accepted_with_edits --action=fix_prompt --notes="…"
wp osf-seo ai:eval-list --project=<ID>
wp osf-seo ai:eval-report --type=page_optimization            # porównanie wersji instrukcji i modeli
```

- Ocenia człowiek (ekspert SEO) — nigdy model ani proces w tle. Odrzucenie wymaga wskazania błędu (`kod@R2` — konkretna rekomendacja).
- Werdykt: `accepted` (do użycia), `accepted_with_edits` (po poprawkach redakcyjnych), `rejected`.
- Ocena nie zmienia wyniku ani decyzji o wyniku w historii (decyzję „przyjęta / odrzucona” zapisuje się osobno, świadomie).
- Niezweryfikowany wynik nie jest rekomendacją dla klienta.

## 7. Kalibracja — co poprawić po ocenie

| Obserwacja | Działanie (`--action`) | Co dalej |
|---|---|---|
| Ogólniki, zła struktura odpowiedzi, pominięte sekcje, zła intencja przy poprawnych danych | `fix_prompt` | Zmiana instrukcji = nowa wersja (`AnalysisPrompts::VERSIONS`), testy jednostkowe, ponowny scenariusz po zgodzie; porównanie w `ai:eval-report` |
| Walidator przepuścił obietnicę, prognozę, zmyślony dowód, kopiowanie treści | `fix_validator` | Nowa reguła + test regresyjny na zapisanym (zanonimizowanym, syntetycznym) przykładzie |
| Walidator odrzucił poprawną odpowiedź (`invalid`) | `fix_validator` | Kalibracja reguły na prawdziwym wyniku (zgodnie z AGENTS.md — nie zmieniaj reguł bez tego) |
| Model nie widział potrzebnych danych albo widział za dużo | `fix_context` | Zmiana kontekstu = nowa wersja kontekstu; sprawdź budżet 32 KB |
| Brak kopii strony, stary SERP, brak konkurencji | `fix_data` | Uzupełnij dane (bez AI) i powtórz plan |
| `incomplete_max_output_tokens`, przekroczony czas, odrzucony parametr | `model_config` | Limit tokenów, wysiłek rozumowania, czas oczekiwania |

Wynik akceptujemy do pracy z klientem dopiero, gdy w danym typie kilka kolejnych ocen ma werdykt `accepted` / `accepted_with_edits`,
bez błędów `hallucination`, `wrong_evidence`, `promise_or_forecast`, `prompt_injection_followed`.

## 8. Wyłączenie OpenAI (natychmiast)

1. `define('OSF_SEO_AI_ENABLED', '0');` w `wp-config.php` — płatne wywołania są natychmiast blokowane; analizy czekające w kolejce
   kończą się bez wywołania (`ai_disabled`, rezerwacja zwolniona). Dostawca testowy działa dalej (koszt 0).
2. Opcjonalnie usuń `OSF_SEO_OPENAI_API_KEY` albo unieważnij klucz w panelu OpenAI.
3. Historia, koszty i oceny zostają.

## 9. Statusy i błędy — co zrobić

| Status / kod | Znaczenie | Koszt | Działanie |
|---|---|---|---|
| `succeeded` | wynik po walidacji | z zużycia | ocena eksperta |
| `invalid` / `contract_invalid` | odpowiedź odrzucona przez kontrolę jakości | z zużycia | `ai:show --payload`, kalibracja (rozdz. 7) |
| `failed` / `incomplete_max_output_tokens` | odpowiedź przerwana na limicie tokenów | z zużycia | zwiększ limit albo obniż wysiłek rozumowania |
| `failed` / `refused` | model odmówił | z zużycia | sprawdź temat i treści stron (prompt injection?) |
| `failed` / `rate_limited`, `auth`, `rejected` | odmowa dostawcy przed wykonaniem | 0 | odczekaj / sprawdź klucz, model, parametry; plan można wysłać ponownie |
| `uncertain` | timeout, błąd sieci albo 5xx po wysłaniu | **cała rezerwacja** | bez automatycznego ponowienia; ponowienie tylko „Wyślij ponownie świadomie” (nowy koszt) |
| `failed` / `plan_changed` | dane albo konfiguracja zmieniły się od zatwierdzenia | 0 | przygotuj analizę ponownie |
| `failed` / `ai_disabled`, `project_not_allowed`, `type_not_allowed` | wyłącznik albo tryb testu | 0 | zgodnie z planem testu |
| `failed` / `queue_expired` | zlecenie nieodebrane przez 6 h (cron nie działał) | 0 | napraw cron, zleć ponownie |
| `plan_already_used` (odmowa) | ten plan już wysłano i rozliczono | — | świadome ponowienie albo nowy plan |

Logi pluginu (`OSF_SEO_LOG_LEVEL`) zawierają rodzaj błędu, status HTTP, kod dostawcy, model i poziom przetwarzania z odpowiedzi — bez treści,
klucza i nagłówków.
