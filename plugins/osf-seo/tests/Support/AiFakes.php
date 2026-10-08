<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use OsfSeo\Ai\Provider\FakeProvider;
use OsfSeo\PageIntelligence\Extract\HtmlExtractor;

/**
 * Syntetyczne dane testów AI (STEP 17): źródło kontekstu tematu w kształcie pakietu STEP 16, odpowiedzi Responses API i klucz API
 * składany w runtime (skanery sekretów nie zgłaszają fałszywych alarmów). Bez prawdziwych kluczy, kont i danych klientów.
 */
final class AiFakes
{
	public const LEADER = '01M4BRGWN100CHWX4QWFNJ5YJM';

	public const MEMBER = '01M4BRGWN13MY8X6AJ73HS9455';

	public const TOPIC = '01M4BRGWNF5KSZXSG6GKNHN25K';

	/** Syntetyczny klucz w kształcie klucza OpenAI (nie jest prawdziwym kluczem). */
	public static function apiKey(): string
	{
		return 's' . 'k-' . 'proj-' . str_repeat('Te5tKey0', 6);
	}

	/**
	 * Źródło `TopicContextAssembler::assemble` (temat z dwiema frazami, pomiarem SERP, luką Labs, Szansą SEO i Nowymi frazami).
	 *
	 * @param array<string, mixed> $context nadpisania pakietu STEP 16 (scalane płytko)
	 * @param array<string, mixed> $source nadpisania źródła (scalane płytko)
	 * @return array<string, mixed>
	 */
	public static function source(array $context = [], array $source = []): array
	{
		$page = 'https://example.pl/pozycjonowanie/';

		return array_merge([
			'context' => array_merge([
				'context_version' => 2,
				'kind' => 'strategy_topic',
				'rules_version' => 2,
				'topic' => ['id' => self::TOPIC, 'label' => 'pozycjonowanie stron', 'active' => true, 'keywords' => 2, 'demand' => 9500],
				'keywords' => [
					[
						'id' => self::LEADER,
						'keyword' => 'pozycjonowanie stron',
						'role' => 'leader',
						'basis' => 'leader',
						'pinned' => false,
						'sources' => ['serp', 'gsc'],
						'market' => ['volume' => 6600, 'difficulty' => 48, 'intent' => 'commercial', 'cpc' => 9.8],
						'gsc' => ['impressions' => 3870, 'clicks' => 45, 'average_position_gsc' => 13.99, 'pages' => 1],
						'serp' => ['snapshot' => '01M4BRFGPB2HXBMKZQ06KV0919', 'checked_at' => '2026-01-10 18:02:30', 'freshness' => 'fresh', 'rank' => 7, 'url' => $page],
						'target' => ['state' => 'confirmed', 'url' => $page],
					],
					[
						'id' => self::MEMBER,
						'keyword' => 'pozycjonowanie stron internetowych',
						'role' => 'member',
						'basis' => 'same_target',
						'pinned' => false,
						'sources' => ['gsc'],
						'market' => ['volume' => 2900, 'difficulty' => 44, 'intent' => 'commercial', 'cpc' => 8.4],
						'gsc' => ['impressions' => 1935, 'clicks' => 20, 'average_position_gsc' => 15.73, 'pages' => 1],
						'serp' => null,
						'target' => ['state' => 'confirmed', 'url' => $page],
					],
				],
				'grouping' => ['leader' => self::LEADER, 'basis' => [], 'suggestions' => []],
				'decision' => [
					'action' => 'optimize',
					'action_label' => 'Optymalizacja strony',
					'reason' => 'serp_position',
					'reason_label' => 'strona projektu na pozycji 4–50 w świeżym pomiarze SERP',
					'basis' => ['serp_position', 'near_top'],
					'checks' => [['rule' => 'consolidate', 'passed' => false, 'why' => 'no_strong_url_conflict'], ['rule' => 'optimize', 'passed' => true, 'why' => 'serp_position']],
				],
				'confidence' => ['points' => 84, 'level' => 'high', 'positive' => [['code' => 'target_confirmed', 'points' => 15]], 'negative' => [], 'neutral' => [], 'caps' => []],
				'priority' => ['value' => 66, 'band' => 'high', 'raw' => 70.3, 'components' => ['demand' => ['value' => 30, 'max' => 30, 'input' => 9500], 'potential' => ['value' => 20, 'max' => 20]]],
				'target' => [
					'state' => 'confirmed', 'url' => $page, 'home' => false, 'families' => ['gsc', 'serp'], 'manual' => false, 'manual_none' => false,
					'reasons' => ['independent_families'],
					'votes' => [['url' => $page, 'family' => 'serp', 'strength' => 'strong', 'basis' => 'serp_top20', 'detail' => ['rank' => 7]]],
					'alternatives' => [], 'no_visibility' => [], 'hints' => [], 'derived' => [['url' => $page, 'source' => 'opportunity']],
					'state_label' => 'Potwierdzona',
				],
				'conflicts' => [],
				'gsc' => ['impressions' => 5805, 'clicks' => 65, 'position' => 14.6, 'complete' => true],
				'facts' => [],
				'serp' => ['band' => 'top10', 'keyword' => self::LEADER, 'rank' => 7],
				'gap' => [[
					'keyword' => self::LEADER,
					'id' => '01M4BRGGZWRMFZREG9WFJPWVB5',
					'gap_type' => 'competitive',
					'visibility' => 'visible',
					'visibility_source' => 'serp',
					'project_rank_labs' => 12,
					'competitors' => 2,
					'competitors_top10' => 2,
					'best_competitor' => ['name' => 'Agencja A', 'domain' => 'konkurent-a.pl', 'rank_labs' => 3],
					'priority' => 36,
				]],
				'content_gap' => [['id' => '01M4BRGH07HMFCMAA9DJ8V05G3', 'label' => 'pozycjonowanie stron', 'content_gap' => 'covered', 'confidence' => 'medium', 'reason' => 'covered']],
				'opportunities' => [['id' => '01M4BRG8CMBMXVVP9TH70KEWA0', 'type' => 'near_top', 'status' => 'new', 'priority' => 57, 'confidence' => 2, 'page' => $page, 'link' => 'member']],
				'discovery' => [['keyword' => self::LEADER, 'id' => '01M4BRG9ANSXKVT1R4PWBBDVFS', 'status' => 'new', 'priority' => 70, 'visibility_gsc' => 'low', 'target_url' => $page]],
				'untrusted' => [],
				'workflow' => ['status' => 'in_progress', 'status_changed_at' => '2026-01-14 09:00:00', 'completed_on' => null, 'decision_changed' => false, 'note' => 'Notatka wewnętrzna klienta'],
				'evidence_hash' => str_repeat('ab', 32),
			], $context),
			'serp' => [
				'keyword' => ['id' => self::LEADER, 'keyword' => 'pozycjonowanie stron'],
				'detail' => self::serpDetail(),
				'overlap' => [['id' => self::MEMBER, 'keyword' => 'pozycjonowanie stron internetowych', 'level' => null, 'shared_urls' => null, 'shared_domains' => null, 'reasons' => ['no_measurement']]],
				'compared' => 1,
				'measured' => 1,
			],
			'freshness' => [
				'gsc' => ['connected' => true, 'newest_date' => '2026-01-13', 'last_success_at' => '2026-01-14 03:00:00'],
				'serp' => ['last_checked_at' => '2026-01-10 18:02:30'],
				'labs' => ['last_import_at' => '2026-01-05 10:00:00', 'recalculated_at' => '2026-01-05 10:05:00'],
			],
			'project' => ['domain' => 'example.pl', 'market' => 'Polska (pl)', 'gsc_window' => ['2025-12-17', '2026-01-13']],
			'market_as_of' => [
				self::LEADER => ['volume' => '2026-01-02 10:00:00', 'difficulty' => '2026-01-03 10:00:00'],
				self::MEMBER => ['volume' => '2026-01-04 10:00:00', 'difficulty' => null],
			],
			'page_index_complete' => false,
			'pages' => null,
			'now' => new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')),
			'topic_id' => 7,
		], $source);
	}

	/** Syntetyczny HTML strony usługi (treść w `<main>`, nawigacja i stopka do odrzucenia). */
	public static function pageHtml(string $title = 'Pozycjonowanie stron — Example', string $h1 = 'Pozycjonowanie stron internetowych', int $sections = 3): string
	{
		$body = '';

		for ($i = 1; $i <= $sections; $i++) {
			$body .= '<h2>Etap ' . $i . ' współpracy</h2><p>' . str_repeat('Opis etapu ' . $i . ' pozycjonowania stron z analizą fraz i treści. ', 8) . '</p>';
		}

		return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>' . $title . '</title>'
			. '<meta name="description" content="Pozycjonowanie stron dla firm — audyt, treści i linki."><link rel="canonical" href="https://example.pl/pozycjonowanie/">'
			. '</head><body><nav><a href="/">Start</a><a href="/kontakt/">Kontakt</a></nav><main><h1>' . $h1 . '</h1>'
			. '<p>' . str_repeat('Pozycjonowanie stron to długofalowa praca nad widocznością witryny w wynikach wyszukiwania. ', 4) . '</p>'
			. $body . '<p><a href="/audyt/">Audyt SEO</a> <a href="https://zewnetrzny.example/poradnik">Poradnik</a></p></main>'
			. '<footer>Stopka © Example</footer></body></html>';
	}

	/**
	 * Dowód strony w kształcie `PageIntelligenceService::topicEvidence` (snapshot z prawdziwego ekstraktora).
	 *
	 * @param array<string, mixed> $snapshot nadpisania pól snapshotu
	 * @return array<string, mixed>
	 */
	public static function pageEvidence(string $url = 'https://example.pl/pozycjonowanie/', ?string $html = null, string $cache = 'fresh', array $snapshot = [], string $id = '01M4BRH0000000000000000001', string $fetchedAt = '2026-01-14 10:00:00'): array
	{
		$extraction = (new HtmlExtractor())->extract($html ?? self::pageHtml(), $url, 'utf-8', []);
		$host = (string) parse_url($url, PHP_URL_HOST);

		return [
			'url' => $url,
			'target' => ['id' => '01M4BRH1000000000000000001', 'url' => $url, 'host' => $host, 'kind' => 'project', 'source' => 'topic', 'status' => 'ok', 'last_attempt_at' => $fetchedAt, 'last_success_at' => $fetchedAt, 'last_error' => null, 'last_http_status' => 200, 'final_url' => $url],
			'snapshot' => array_merge([
				'id' => $id,
				'fetched_at' => $fetchedAt,
				'last_seen_at' => $fetchedAt,
				'http_status' => 200,
				'content_type' => 'text/html',
				'final_url' => $url,
				'bytes' => strlen($html ?? self::pageHtml()),
				'fetch_ms' => 120,
				'content_hash' => $extraction->contentHash(),
				'extractor_version' => HtmlExtractor::VERSION,
				'title' => $extraction->title(),
				'word_count' => $extraction->content['word_count'],
				'content_quality' => $extraction->quality['level'],
				'indexability' => $extraction->technical['indexability'],
				'canonical_status' => $extraction->technical['canonical_status'],
				'data' => $extraction->toArray(),
			], $snapshot),
			'cache' => $cache,
		];
	}

	/**
	 * Strona projektu i strony konkurencji z SERP (kształt `topicEvidence`).
	 *
	 * @return array{project: ?array<string, mixed>, competitors: list<array<string, mixed>>, competitors_total: int}
	 */
	public static function pages(?array $project = null, int $competitors = 2, string $competitorFetchedAt = '2026-01-11 09:00:00'): array
	{
		$items = [];

		for ($rank = 1; $rank <= $competitors; $rank++) {
			$url = 'https://wynik-' . $rank . '.example/seo/';
			$items[] = self::pageEvidence($url, self::pageHtml('Wynik ' . $rank . ' — oferta', 'Oferta SEO ' . $rank, 2), 'fresh', [], sprintf('01M4BRH20000000000000000%02d', $rank), $competitorFetchedAt)
				+ ['serp' => ['keyword_id' => self::LEADER, 'rank_group' => $rank, 'checked_at' => '2026-01-10 18:02:30']];
		}

		return ['project' => $project ?? self::pageEvidence(), 'competitors' => $items, 'competitors_total' => $competitors];
	}

	/**
	 * Szczegóły SERP Intelligence frazy odniesienia (kształt `SerpIntelligence::detail`).
	 *
	 * @param list<array<string, mixed>>|null $results
	 * @return array<string, mixed>
	 */
	public static function serpDetail(string $freshness = 'fresh', ?array $results = null): array
	{
		$page = 'https://example.pl/pozycjonowanie/';
		$results ??= array_map(static fn (int $rank): array => [
			'rank' => $rank,
			'host' => $rank === 7 ? 'example.pl' : 'wynik-' . $rank . '.example',
			'url' => $rank === 7 ? $page : 'https://wynik-' . $rank . '.example/seo/',
			'title' => $rank === 7 ? 'Pozycjonowanie stron — Example' : 'Wynik ' . $rank,
			'project' => $rank === 7,
			'competitor' => $rank === 3 ? 'Agencja A' : null,
			'shape' => 'service',
			'confidence' => 'medium',
			'reason' => 'path',
		], range(1, 20));

		return [
			'snapshot' => '01M4BRFGPB2HXBMKZQ06KV0919',
			'checked_at' => '2026-01-10 18:02:30',
			'freshness' => $freshness,
			'tracking' => 'active',
			'context' => ['device' => 'desktop', 'depth' => 100],
			'profile' => ['shape' => 'service', 'shape_share' => 0.6, 'shape_confidence' => 'medium', 'intent_signal' => 'commercial', 'intent_confidence' => 'medium', 'top10' => ['organic' => 10, 'domains' => 10, 'top_domain_results' => 1, 'home' => 1, 'shapes' => ['service' => 6]], 'top20' => ['organic' => 20, 'domains' => 20, 'shapes' => ['service' => 12]], 'features' => ['people_also_ask']],
			'project' => ['found' => true, 'rank' => 7, 'url' => $page, 'featured' => false],
			'spell' => null,
			'competitors' => ['top10' => 1, 'top20' => 1, 'best' => ['id' => '01M4BRFGPB2HXBMKZQ06KV0920', 'name' => 'Agencja A', 'rank' => 3]],
			'results' => $results,
		];
	}

	/**
	 * Odpowiedź Responses API (status `completed`) z tekstem i zużyciem.
	 *
	 * @return array<string, mixed>
	 */
	public static function openAiResponse(string $text, int $input = 5000, int $output = 800, int $cached = 0, string $status = 'completed'): array
	{
		return [
			'id' => 'resp_' . str_repeat('a1', 12),
			'object' => 'response',
			'created_at' => 1768478400,
			'status' => $status,
			'model' => 'test-model-1',
			'output' => [
				['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
				['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]],
			],
			'usage' => [
				'input_tokens' => $input,
				'input_tokens_details' => ['cached_tokens' => $cached],
				'output_tokens' => $output,
				'output_tokens_details' => ['reasoning_tokens' => 100],
				'total_tokens' => $input + $output,
			],
			'error' => null,
			'incomplete_details' => null,
		];
	}

	/**
	 * Poprawna odpowiedź kontraktu dla podanych odwołań.
	 *
	 * @param list<string> $refs
	 */
	public static function validAnalysis(array $refs): string
	{
		return (string) json_encode(FakeProvider::sample(['refs' => $refs, 'data_gaps' => ['page_content_not_fetched'], 'action' => 'optimize']), JSON_UNESCAPED_UNICODE);
	}
}
