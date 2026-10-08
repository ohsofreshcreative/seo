<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Contract;

use OsfSeo\Ai\Analysis\AnalysisType;
use OsfSeo\Ai\Analysis\Readiness;
use OsfSeo\Ai\Context\AiContext;
use OsfSeo\Ai\Context\TopicContextAssembler;

/**
 * Walidacja odpowiedzi analiz rekomendacji (kontrakt v2) po stronie PHP — deklaracja modelu nie jest dowodem poprawności (D107).
 * Odpowiedź z jakimkolwiek błędem jest odrzucana w całości (status `invalid`, wynik niezapisany jako wynik).
 *
 * Struktura: dokładny zestaw pól, typy, wyliczenia, limity długości i liczby elementów, unikalne identyfikatory, typ analizy zgodny
 * z żądaniem, sekcje wymagane i zabronione dla typu.
 *
 * Dowody: odwołania wyłącznie z kontekstu uruchomienia; `fact` i `inference` z odwołaniem; `fact` nie może opierać się wyłącznie na
 * heurystykach aplikacji; brak treści nigdy nie jest faktem; adresy linków tylko z kontekstu.
 *
 * Jakość (reguły produktu): bez prognoz pozycji i ruchu, obietnic, „Google wymaga”, celów liczby słów, diagnostyki parsera jako problemu
 * SEO, przeskoków nagłówków jako czynnika rankingowego, etykiet interfejsu jako tematów, kopiowania fragmentów treści konkurencji,
 * przekierowań / canonical / noindex / usuwania stron bez kontroli ręcznej, keyword stuffingu w propozycjach, duplikatów rekomendacji,
 * ukrywania ograniczeń danych; ograniczenia zgodności z działaniem Strategii (np. `maintenance_focus`, `verify_first`).
 */
final class RecommendationValidator extends JsonContractValidator
{
	public const MAX_BYTES = 200000;

	/** Prognozy liczbowe (procenty, mnożniki, „+N”). */
	private const NUMERIC_FORECAST = '/(\d+(?:[.,]\d+)?\s*%|\d+(?:[.,]\d+)?\s*(?:x|×)(?![a-z])|\b\d+(?:[.,]\d+)?[\s-]*(?:razy|krotn)|\+\s*\d)/iu';

	/** Prognozy pozycji („awansuje do TOP3”, „osiągnie pozycję 5”, „trafi na pierwszą stronę”). */
	private const RANKING_FORECAST = '/(?:osiągn\w*|awans\w*|wzro\w*|wzrośnie|popraw\w*|trafi\w*|wejdzie|wejść|podniesie|zapewni\w*|reach\w*|climb\w*|rank\w*)[^.]{0,40}?(?:top\s?\d+|pozycj\w*\s*(?:nr\s*)?\d+|#\s?\d+|pierwsz\w+\s+(?:stron\w*|miejsc\w*|pozycj\w*)|first page|position\s*\d+)/iu';

	private const PROMISE = '/(gwarant|guarantee|na pewno (?:wzro|popraw|zwięk)|z pewnością (?:wzro|popraw|zwięk))/iu';

	private const GOOGLE_REQUIREMENT = '/google\s+(?:wymaga|requires|nagradza|rewards|preferuje|prefers|karze|penali[sz]es)/iu';

	/** Cele liczby słów (rekomendacje oparte wyłącznie na długości tekstu). */
	private const WORD_COUNT = '/(\d[\d\s]*\+?\s*(?:słów|słowa|wyrazów|words)|liczb\w+\s+słów|word\s*count|więcej\s+słów|objętoś\w+\s+(?:tekstu|treści)|dłuższ\w+\s+(?:niż|od)\s+konkuren)/iu';

	/** Diagnostyka parsera DOM jako problem SEO. */
	private const PARSER = '/(parse[_\s-]?errors?|błęd\w*\s+parsowania|parser\w*|błęd\w*\s+(?:DOM|HTML)|walidacj\w*\s+(?:W3C|HTML)|niepoprawn\w*\s+(?:kod|składni\w*)\s+HTML)/iu';

	/** Przeskoki poziomów nagłówków przedstawiane jako czynnik rankingowy. */
	private const HEADING_RANKING = '/(przeskok\w*|pomini\w+\s+poziom\w*|pomija\w*\s+poziom\w*|skipp\w+|kolejnoś\w+\s+nagłówk\w*)[^.]{0,80}(ranking\w*|pozycj\w*|czynnik\w*|algorytm\w*|google)/iu';

	/** Działania nieodwracalne albo ryzykowne (wymagają audytu i kontroli ręcznej). */
	private const DESTRUCTIVE = '/(przekier\w*|redirect\w*|\b30[12]\b|canonical|kanoniczn\w*|noindex|usu[nń]\w*\s+(?:t[eęą]\s+|tej\s+)?(?:pod)?stron\w*|scal\w*\s+(?:pod)?stron\w*|delete\s+(?:the\s+)?page)/iu';

	/** Twierdzenia o braku strony w serwisie (niepełny indeks stron nie pozwala ich formułować). */
	private const ABSENCE_CLAIM = '/((?:serwis|witryn\w*|stron\w*\s+projektu)\s+nie\s+(?:ma|posiada|zawiera)\s+(?:żadnej\s+)?(?:takiej\s+)?(?:pod)?stron\w*|(?:pod)?stron\w*\s+(?:o\s+tym\s+temacie\s+)?nie\s+istniej\w*|site\s+has\s+no\s+page)/iu';

	/** Długość fragmentu (słowa) traktowanego jako skopiowany z treści konkurencji. */
	private const SHINGLE = 8;

	private ?string $type = null;

	private ?AiContext $context = null;

	/** @var array<string, true> */
	private array $urls = [];

	/** @var array<string, true> */
	private array $shingles = [];

	public function validate(string $text, AiContext $context): ValidationResult
	{
		$this->errors = [];
		$this->context = $context;
		$this->known = array_fill_keys($context->refs(), true);
		$this->urls = array_fill_keys($context->knownUrls(), true);
		$this->type = $context->analysisType();
		$this->shingles = self::shingles($context->externalTexts(['competitor_excerpt', 'competitor_heading', 'competitor_title']));
		$data = $this->decode($text, self::MAX_BYTES);

		if ($data === null) {
			return new ValidationResult(null, $this->errors);
		}

		if (! $this->keys($data, RecommendationContract::FIELDS, '$')) {
			return new ValidationResult(null, $this->errors);
		}

		if ($data['contract_version'] !== RecommendationContract::VERSION) {
			$this->error('$.contract_version', 'contract_version');
		}

		if ($data['analysis_type'] !== $this->type || ! in_array($data['analysis_type'], AnalysisType::RECOMMENDATIONS, true)) {
			$this->error('$.analysis_type', 'analysis_type');
		}

		$result = [
			'contract_version' => RecommendationContract::VERSION,
			'analysis_type' => $this->type,
			'summary' => $this->string($data['summary'], '$.summary', 'summary', true),
			'search_intent' => $this->intent($data['search_intent']),
		];
		[$result['findings'], $findingIds] = $this->findings($data['findings']);
		$result['recommendations'] = $this->recommendations($data['recommendations'], $findingIds);
		$result['content_outline'] = $this->outline($data['content_outline']);
		$result['title_suggestions'] = $this->suggestions($data['title_suggestions'], '$.title_suggestions', RecommendationContract::TITLE_LENGTH);
		$result['meta_description_suggestions'] = $this->suggestions($data['meta_description_suggestions'], '$.meta_description_suggestions', RecommendationContract::META_LENGTH);
		$result['internal_links'] = $this->links($data['internal_links']);
		$result['content_topics'] = $this->topics($data['content_topics']);
		$result['user_questions'] = $this->texts($data['user_questions'], '$.user_questions', 'question');
		$result['cta_suggestions'] = $this->texts($data['cta_suggestions'], '$.cta_suggestions', 'cta', RecommendationContract::SECTION_ITEMS['cta_suggestions']);
		$result['client_data_needed'] = $this->texts($data['client_data_needed'], '$.client_data_needed', 'client_data');
		$result['cannibalization_risks'] = $this->risks($data['cannibalization_risks']);
		$result['missing_information'] = $this->missing($data['missing_information']);
		$result['warnings'] = $this->texts($data['warnings'], '$.warnings', 'warning');
		$result['manual_checks'] = $this->checks($data['manual_checks']);

		$this->typeRules($result);
		$this->qualityRules($result);

		return new ValidationResult($this->errors === [] ? $result : null, $this->errors);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function intent(mixed $value): ?array
	{
		if (! $this->keys($value, ['primary', 'explanation', 'evidence_refs', 'basis', 'confidence'], '$.search_intent')) {
			return null;
		}

		$refs = $this->refs($value['evidence_refs'], '$.search_intent.evidence_refs');
		$basis = $this->enum($value['basis'], RecommendationContract::BASIS, '$.search_intent.basis');
		$this->basis($basis, $refs, '$.search_intent');

		return [
			'primary' => $this->enum($value['primary'], RecommendationContract::INTENTS, '$.search_intent.primary'),
			'explanation' => $this->string($value['explanation'], '$.search_intent.explanation', 'intent_explanation', true),
			'evidence_refs' => $refs,
			'basis' => $basis,
			'confidence' => $this->enum($value['confidence'], RecommendationContract::CONFIDENCE, '$.search_intent.confidence'),
		];
	}

	/**
	 * @return array{0: list<array<string, mixed>>, 1: array<string, true>}
	 */
	private function findings(mixed $value): array
	{
		$result = [];
		$ids = [];

		foreach ($this->items($value, '$.findings') as $index => $item) {
			$path = '$.findings[' . $index . ']';

			if (! $this->keys($item, ['id', 'kind', 'title', 'explanation', 'evidence_refs', 'basis', 'confidence'], $path)) {
				continue;
			}

			$refs = $this->refs($item['evidence_refs'], $path . '.evidence_refs');
			$basis = $this->enum($item['basis'], RecommendationContract::BASIS, $path . '.basis');
			$this->basis($basis, $refs, $path);
			$result[] = [
				'id' => $this->id($item['id'], $path . '.id', $ids),
				'kind' => $this->enum($item['kind'], RecommendationContract::FINDING_KINDS, $path . '.kind'),
				'title' => $this->string($item['title'], $path . '.title', 'title', true),
				'explanation' => $this->string($item['explanation'], $path . '.explanation', 'explanation', true),
				'evidence_refs' => $refs,
				'basis' => $basis,
				'confidence' => $this->enum($item['confidence'], RecommendationContract::CONFIDENCE, $path . '.confidence'),
			];
		}

		return [$result, $ids];
	}

	/**
	 * @param array<string, true> $findingIds
	 * @return list<array<string, mixed>>
	 */
	private function recommendations(mixed $value, array $findingIds): array
	{
		$result = [];
		$ids = [];
		$keys = ['id', 'type', 'title', 'description', 'rationale', 'target', 'finding_ids', 'priority', 'urgency', 'expected_impact', 'impact_rationale', 'evidence_refs', 'basis', 'confidence', 'requires_manual_check', 'verification'];

		foreach ($this->items($value, '$.recommendations') as $index => $item) {
			$path = '$.recommendations[' . $index . ']';

			if (! $this->keys($item, $keys, $path)) {
				continue;
			}

			$refs = $this->refs($item['evidence_refs'], $path . '.evidence_refs');
			$basis = $this->enum($item['basis'], RecommendationContract::BASIS, $path . '.basis');
			$this->basis($basis, $refs, $path);

			if (! is_bool($item['requires_manual_check'])) {
				$this->error($path . '.requires_manual_check', 'wrong_type');
			}

			if (! is_int($item['priority']) || ! in_array($item['priority'], RecommendationContract::PRIORITIES, true)) {
				$this->error($path . '.priority', 'invalid_enum');
			}

			$result[] = [
				'id' => $this->id($item['id'], $path . '.id', $ids),
				'type' => $this->enum($item['type'], RecommendationContract::RECOMMENDATION_TYPES, $path . '.type'),
				'title' => $this->string($item['title'], $path . '.title', 'title', true),
				'description' => $this->string($item['description'], $path . '.description', 'description', true),
				'rationale' => $this->string($item['rationale'], $path . '.rationale', 'rationale', true),
				'target' => $this->string($item['target'], $path . '.target', 'target', false),
				'finding_ids' => $this->findingIds($item['finding_ids'], $findingIds, $path . '.finding_ids'),
				'priority' => is_int($item['priority']) ? $item['priority'] : null,
				'urgency' => $this->enum($item['urgency'], RecommendationContract::URGENCY, $path . '.urgency'),
				'expected_impact' => $this->enum($item['expected_impact'], RecommendationContract::IMPACT, $path . '.expected_impact'),
				'impact_rationale' => $this->string($item['impact_rationale'], $path . '.impact_rationale', 'impact_rationale', true),
				'evidence_refs' => $refs,
				'basis' => $basis,
				'confidence' => $this->enum($item['confidence'], RecommendationContract::CONFIDENCE, $path . '.confidence'),
				'requires_manual_check' => $item['requires_manual_check'] === true,
				'verification' => $this->string($item['verification'], $path . '.verification', 'verification', false),
			];
		}

		return $result;
	}

	/**
	 * @return array{h1: ?string, sections: list<array<string, mixed>>}|null
	 */
	private function outline(mixed $value): ?array
	{
		if (! $this->keys($value, ['h1', 'sections'], '$.content_outline')) {
			return null;
		}

		$sections = [];

		foreach ($this->items($value['sections'], '$.content_outline.sections', RecommendationContract::SECTION_ITEMS['outline_sections']) as $index => $item) {
			$path = '$.content_outline.sections[' . $index . ']';

			if (! $this->keys($item, ['level', 'heading', 'scope', 'evidence_refs', 'basis'], $path)) {
				continue;
			}

			if (! in_array($item['level'], [2, 3], true)) {
				$this->error($path . '.level', 'invalid_enum');
			}

			$refs = $this->refs($item['evidence_refs'], $path . '.evidence_refs');
			$basis = $this->enum($item['basis'], RecommendationContract::BASIS, $path . '.basis');
			$this->basis($basis, $refs, $path);
			$sections[] = [
				'level' => is_int($item['level']) ? $item['level'] : null,
				'heading' => $this->string($item['heading'], $path . '.heading', 'heading', true),
				'scope' => $this->string($item['scope'], $path . '.scope', 'scope', true),
				'evidence_refs' => $refs,
				'basis' => $basis,
			];
		}

		return ['h1' => $this->string($value['h1'], '$.content_outline.h1', 'heading', false), 'sections' => $sections];
	}

	/**
	 * @param array{0: int, 1: int} $length
	 * @return list<array<string, mixed>>
	 */
	private function suggestions(mixed $value, string $base, array $length): array
	{
		$result = [];
		$section = substr($base, 2);

		foreach ($this->items($value, $base, RecommendationContract::SECTION_ITEMS[$section]) as $index => $item) {
			$path = $base . '[' . $index . ']';

			if (! $this->keys($item, ['text', 'rationale', 'evidence_refs', 'basis'], $path)) {
				continue;
			}

			$text = $this->string($item['text'], $path . '.text', 'suggestion', true);
			$chars = $text === null ? 0 : mb_strlen($text, 'UTF-8');

			if ($text !== null && $text !== '' && $chars < $length[0]) {
				$this->error($path . '.text', 'too_short');
			} elseif ($chars > $length[1]) {
				$this->error($path . '.text', 'too_long');
			}

			if ($text !== null && self::stuffed($text)) {
				$this->error($path . '.text', 'keyword_stuffing');
			}

			$refs = $this->refs($item['evidence_refs'], $path . '.evidence_refs');
			$basis = $this->enum($item['basis'], RecommendationContract::BASIS, $path . '.basis');
			$this->basis($basis, $refs, $path);
			$result[] = [
				'text' => $text,
				'rationale' => $this->string($item['rationale'], $path . '.rationale', 'rationale', true),
				'evidence_refs' => $refs,
				'basis' => $basis,
			];
		}

		return $result;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function links(mixed $value): array
	{
		$result = [];

		foreach ($this->items($value, '$.internal_links') as $index => $item) {
			$path = '$.internal_links[' . $index . ']';

			if (! $this->keys($item, ['from_url', 'to_url', 'anchor_suggestion', 'rationale', 'evidence_refs', 'basis'], $path)) {
				continue;
			}

			$from = $this->string($item['from_url'], $path . '.from_url', 'url', false);
			$to = $this->string($item['to_url'], $path . '.to_url', 'url', false);

			// Pusty adres = analizowana strona (albo nowa strona z briefu); inne adresy wyłącznie z kontekstu.
			foreach (['from_url' => $from, 'to_url' => $to] as $field => $url) {
				if ($url !== null && $url !== '' && ! isset($this->urls[$url])) {
					$this->error($path . '.' . $field, 'unknown_url');
				}
			}

			if ($from === '' && $to === '') {
				$this->error($path, 'link_without_url');
			}

			$refs = $this->refs($item['evidence_refs'], $path . '.evidence_refs');
			$basis = $this->enum($item['basis'], RecommendationContract::BASIS, $path . '.basis');
			$this->basis($basis, $refs, $path);
			$result[] = [
				'from_url' => $from,
				'to_url' => $to,
				'anchor_suggestion' => $this->string($item['anchor_suggestion'], $path . '.anchor_suggestion', 'anchor', true),
				'rationale' => $this->string($item['rationale'], $path . '.rationale', 'rationale', true),
				'evidence_refs' => $refs,
				'basis' => $basis,
			];
		}

		return $result;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function topics(mixed $value): array
	{
		$result = [];

		foreach ($this->items($value, '$.content_topics', RecommendationContract::SECTION_ITEMS['content_topics']) as $index => $item) {
			$path = '$.content_topics[' . $index . ']';

			if (! $this->keys($item, ['label', 'status', 'evidence_refs', 'basis', 'confidence', 'note'], $path)) {
				continue;
			}

			$refs = $this->refs($item['evidence_refs'], $path . '.evidence_refs');
			$basis = $this->enum($item['basis'], RecommendationContract::BASIS, $path . '.basis');
			$this->basis($basis, $refs, $path);
			$result[] = [
				'label' => $this->string($item['label'], $path . '.label', 'topic', true),
				'status' => $this->enum($item['status'], RecommendationContract::TOPIC_STATUSES, $path . '.status'),
				'evidence_refs' => $refs,
				'basis' => $basis,
				'confidence' => $this->enum($item['confidence'], RecommendationContract::CONFIDENCE, $path . '.confidence'),
				'note' => $this->string($item['note'], $path . '.note', 'note', false),
			];
		}

		return $result;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function risks(mixed $value): array
	{
		$result = [];

		foreach ($this->items($value, '$.cannibalization_risks', RecommendationContract::SECTION_ITEMS['cannibalization_risks']) as $index => $item) {
			$path = '$.cannibalization_risks[' . $index . ']';

			if ($this->keys($item, ['description', 'evidence_refs', 'basis'], $path)) {
				$refs = $this->refs($item['evidence_refs'], $path . '.evidence_refs');
				$basis = $this->enum($item['basis'], RecommendationContract::BASIS, $path . '.basis');
				$this->basis($basis, $refs, $path);
				$result[] = ['description' => $this->string($item['description'], $path . '.description', 'risk', true), 'evidence_refs' => $refs, 'basis' => $basis];
			}
		}

		return $result;
	}

	/**
	 * @return list<array<string, ?string>>
	 */
	private function missing(mixed $value): array
	{
		$result = [];

		foreach ($this->items($value, '$.missing_information') as $index => $item) {
			$path = '$.missing_information[' . $index . ']';

			if ($this->keys($item, ['item', 'why_it_matters'], $path)) {
				$result[] = [
					'item' => $this->string($item['item'], $path . '.item', 'item', true),
					'why_it_matters' => $this->string($item['why_it_matters'], $path . '.why_it_matters', 'why_it_matters', true),
				];
			}
		}

		return $result;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function checks(mixed $value): array
	{
		$result = [];

		foreach ($this->items($value, '$.manual_checks') as $index => $item) {
			$path = '$.manual_checks[' . $index . ']';

			if ($this->keys($item, ['check', 'reason', 'evidence_refs'], $path)) {
				$result[] = [
					'check' => $this->string($item['check'], $path . '.check', 'check', true),
					'reason' => $this->string($item['reason'], $path . '.reason', 'reason', true),
					'evidence_refs' => $this->refs($item['evidence_refs'], $path . '.evidence_refs'),
				];
			}
		}

		return $result;
	}

	/**
	 * @return list<?string>
	 */
	private function texts(mixed $value, string $path, string $limit, ?int $max = null): array
	{
		$result = [];

		foreach ($this->items($value, $path, $max) as $index => $text) {
			$result[] = $this->string($text, $path . '[' . $index . ']', $limit, true);
		}

		return $result;
	}

	/**
	 * Podstawa twierdzenia: `fact` i `inference` z odwołaniem; `fact` musi wskazywać co najmniej jeden dowód mierzony albo dane dostawcy
	 * (nie wyłącznie heurystyki aplikacji — decyzja Strategii, strona docelowa, inne tematy).
	 *
	 * @param list<string> $refs
	 */
	private function basis(?string $basis, array $refs, string $path): void
	{
		if (($basis === 'fact' || $basis === 'inference') && $refs === []) {
			$this->error($path . '.evidence_refs', $basis . '_without_refs');
		}

		if ($basis === 'fact' && $refs !== [] && array_filter($refs, static fn (string $ref): bool => AiContext::refKind($ref) !== 'heuristic') === []) {
			$this->error($path . '.basis', 'fact_from_heuristic');
		}
	}

	/**
	 * Sekcje wymagane i zabronione dla typu analizy.
	 *
	 * @param array<string, mixed> $result
	 */
	private function typeRules(array $result): void
	{
		$forbidden = match ($this->type) {
			AnalysisType::PAGE_OPTIMIZATION => ['content_topics', 'user_questions', 'cta_suggestions', 'client_data_needed'],
			AnalysisType::NEW_PAGE_BRIEF => ['content_topics'],
			AnalysisType::CONTENT_GAP => ['user_questions', 'cta_suggestions', 'client_data_needed', 'title_suggestions', 'meta_description_suggestions'],
			default => [],
		};

		foreach ($forbidden as $section) {
			if (($result[$section] ?? []) !== []) {
				$this->error('$.' . $section, 'section_not_allowed');
			}
		}

		if ($this->type === AnalysisType::NEW_PAGE_BRIEF) {
			if (($result['content_outline']['h1'] ?? '') === '' || count($result['content_outline']['sections'] ?? []) < 2) {
				$this->error('$.content_outline', 'outline_required');
			}

			foreach (['title_suggestions', 'meta_description_suggestions'] as $section) {
				if ($result[$section] === []) {
					$this->error('$.' . $section, 'section_required');
				}
			}
		}

		if ($this->type === AnalysisType::CONTENT_GAP && $result['content_topics'] === []) {
			$this->error('$.content_topics', 'section_required');
		}

		if ($this->type === AnalysisType::PAGE_OPTIMIZATION && $result['recommendations'] === []) {
			$this->error('$.recommendations', 'section_required');
		}
	}

	/**
	 * Reguły jakości rekomendacji (D107).
	 *
	 * @param array<string, mixed> $result
	 */
	private function qualityRules(array $result): void
	{
		$constraints = $this->context?->constraints() ?? [];
		$quality = $this->context?->pageQuality();
		$description = $this->context?->descriptionStatus();
		$readiness = $this->context?->body['analysis']['readiness'] ?? null;
		$notes = array_column((array) ($this->context?->body['analysis']['notes'] ?? []), 'code');
		// Etykiety interfejsu i nagłówki kart (sekcje prawie bez treści: realizacje, kafle kategorii) — nie są tematami treści.
		$uiLabels = array_map(static fn (string $label): string => self::normalize($label), $this->context?->externalTexts(['ui_label']) ?? []);
		$cards = array_map(static fn (string $label): string => self::normalize($label), $this->context?->cardHeadings() ?? []);
		$titles = [];
		$faq = 0;

		foreach ($result['recommendations'] as $index => $recommendation) {
			$path = '$.recommendations[' . $index . ']';
			$texts = array_filter([$recommendation['title'], $recommendation['description'], $recommendation['rationale'], $recommendation['impact_rationale']], 'is_string');
			$joined = implode(' ', $texts);

			foreach ([self::NUMERIC_FORECAST => 'numeric_forecast', self::RANKING_FORECAST => 'ranking_forecast', self::WORD_COUNT => 'word_count_target', self::PARSER => 'parser_diagnostic_as_issue', self::HEADING_RANKING => 'heading_levels_overstated'] as $pattern => $code) {
				if (preg_match($pattern, $code === 'numeric_forecast' ? (string) $recommendation['impact_rationale'] . ' ' . (string) $recommendation['title'] : $joined) === 1) {
					$this->error($path, $code);
				}
			}

			if (preg_match(self::DESTRUCTIVE, $joined) === 1 && (! $recommendation['requires_manual_check'] || ! in_array($recommendation['type'], ['consolidation_check', 'technical_check'], true) || $recommendation['basis'] === 'fact')) {
				$this->error($path, 'destructive_without_check');
			}

			if ($recommendation['type'] === 'heading_structure' && ($recommendation['expected_impact'] === 'high' || $recommendation['urgency'] === 'now')) {
				$this->error($path, 'heading_levels_overstated');
			}

			if ($recommendation['type'] === 'meta_description' && in_array($description, ['not_detected', 'not_detected_unconfirmed'], true)) {
				if (! $recommendation['requires_manual_check']) {
					$this->error($path . '.requires_manual_check', 'manual_check_required');
				}

				if ($description === 'not_detected_unconfirmed' && $recommendation['basis'] === 'fact') {
					$this->error($path . '.basis', 'meta_absence_unconfirmed');
				}
			}

			if ($recommendation['type'] === 'faq' && (++$faq > 1 || $recommendation['evidence_refs'] === [])) {
				$this->error($path, 'faq_unjustified');
			}

			if (in_array('maintenance_focus', $constraints, true) && ($recommendation['expected_impact'] === 'high' || ($recommendation['urgency'] === 'now' && in_array($recommendation['type'], ['new_section', 'content_scope', 'page_role'], true)))) {
				$this->error($path, 'maintenance_overreach');
			}

			if (in_array('verify_first', $constraints, true) && $recommendation['confidence'] === 'high') {
				$this->error($path . '.confidence', 'overconfident');
			}

			$title = self::normalize((string) $recommendation['title']);

			if ($title !== '' && isset($titles[$title])) {
				$this->error($path, 'duplicate_recommendation');
			}

			$titles[$title] = true;
			$this->copied($joined, $path);
		}

		foreach ($result['content_topics'] as $index => $topic) {
			$path = '$.content_topics[' . $index . ']';
			$label = (string) $topic['label'];

			if ($topic['status'] === 'potentially_missing') {
				if ($topic['basis'] === 'fact') {
					$this->error($path . '.basis', 'absence_as_fact');
				}

				if ($quality !== 'good' && $topic['confidence'] !== 'low') {
					$this->error($path . '.confidence', 'absence_unconfirmed');
				}
			}

			if (TopicContextAssembler::uiLike($label) || in_array(self::normalize($label), $uiLabels, true) || ($topic['status'] !== 'present_on_project_page' && in_array(self::normalize($label), $cards, true))) {
				$this->error($path . '.label', 'ui_label_as_topic');
			}
		}

		foreach ($result['content_outline']['sections'] ?? [] as $index => $section) {
			$path = '$.content_outline.sections[' . $index . ']';

			if (preg_match(self::WORD_COUNT, (string) $section['scope']) === 1) {
				$this->error($path, 'word_count_target');
			}

			$this->copied((string) $section['heading'] . ' ' . (string) $section['scope'], $path);
		}

		foreach (['title_suggestions', 'meta_description_suggestions'] as $section) {
			foreach ($result[$section] as $index => $suggestion) {
				$this->copied((string) $suggestion['text'], '$.' . $section . '[' . $index . ']');
			}
		}

		$all = implode(' ', array_filter([
			$result['summary'],
			...array_map(static fn (array $finding): string => (string) $finding['title'] . ' ' . (string) $finding['explanation'], $result['findings']),
			...array_map(static fn (array $recommendation): string => (string) $recommendation['title'] . ' ' . (string) $recommendation['description'] . ' ' . (string) $recommendation['rationale'], $result['recommendations']),
		], 'is_string'));

		foreach ([self::PROMISE => 'promise', self::GOOGLE_REQUIREMENT => 'google_requirement_claim', self::ABSENCE_CLAIM => 'absence_claim', self::RANKING_FORECAST => 'ranking_forecast'] as $pattern => $code) {
			if (preg_match($pattern, $all) === 1) {
				$this->error('$', $code);
			}
		}

		if (preg_match(self::PARSER, implode(' ', array_map(static fn (array $finding): string => (string) $finding['title'] . ' ' . (string) $finding['explanation'], $result['findings']))) === 1) {
			$this->error('$.findings', 'parser_diagnostic_as_issue');
		}

		// Ograniczenia danych muszą być widoczne w wyniku (gotowość częściowa, niepełny indeks stron przy briefie).
		if (($readiness === Readiness::PARTIAL || ($this->type === AnalysisType::NEW_PAGE_BRIEF && in_array('page_index_incomplete', $notes, true))) && $result['warnings'] === [] && $result['missing_information'] === []) {
			$this->error('$.warnings', 'limitations_not_disclosed');
		}
	}

	/** Fragment treści konkurencji przepisany do rekomendacji (≥ SHINGLE kolejnych słów). */
	private function copied(string $text, string $path): void
	{
		if ($this->shingles === []) {
			return;
		}

		foreach (array_keys(self::shingles([$text])) as $shingle) {
			if (isset($this->shingles[$shingle])) {
				$this->error($path, 'copied_competitor_text');

				return;
			}
		}
	}

	/**
	 * @param list<string> $texts
	 * @return array<string, true>
	 */
	private static function shingles(array $texts): array
	{
		$shingles = [];

		foreach ($texts as $text) {
			$words = preg_split('/\s+/u', self::normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

			for ($i = 0; $i + self::SHINGLE <= count($words); $i++) {
				$shingles[implode(' ', array_slice($words, $i, self::SHINGLE))] = true;
			}
		}

		return $shingles;
	}

	/** Keyword stuffing w propozycji: to samo słowo (≥ 4 litery) co najmniej 3 razy. */
	private static function stuffed(string $text): bool
	{
		$counts = array_count_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [], static fn (string $word): bool => mb_strlen($word, 'UTF-8') >= 4));

		return $counts !== [] && max($counts) >= 3;
	}

	private static function normalize(string $text): string
	{
		return TopicContextAssembler::headingKey($text);
	}

	protected function limit(string $kind): int
	{
		return RecommendationContract::LIMITS[$kind];
	}

	protected function maxItems(): int
	{
		return RecommendationContract::MAX_ITEMS;
	}

	protected function maxRefs(): int
	{
		return RecommendationContract::MAX_REFS;
	}
}
