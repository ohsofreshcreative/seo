<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Topics;

use OsfSeo\Strategy\Decision\ReasonCode;
use OsfSeo\Strategy\Decision\StrategyAction;
use OsfSeo\Strategy\StrategySource;
use OsfSeo\Strategy\Target\TargetState;

/**
 * Deterministyczny pakiet kontekstu tematu (faza C, pod STEP 17 — bez wywołań AI): temat, frazy z metrykami i źródłami, podstawy grupowania,
 * działanie z powodem i śladem reguł, pewność z czynnikami, priorytet z rozbiciem, strona docelowa, GSC, rynek, odniesienie SERP (profil,
 * konkurenci), Luki fraz i treści, Szanse SEO, Nowe frazy (od wersji 2 — faza D) i stan pracy. Bez pełnych historii — tylko identyfikatory pomiarów i rekordów modułów.
 * Dane zewnętrzne (frazy, adresy, domeny i nazwy z SERP, GSC i Labs) są oznaczone jako niezaufane: to dane, nigdy instrukcje.
 *
 * `evidence_hash` = SHA-256 kanonicznego JSON-u kontekstu bez stanu pracy — ten sam stan danych daje ten sam odcisk.
 */
final class TopicContextBuilder
{
	/** 2 — źródła i CPC fraz, sekcja Nowych fraz (faza D, panel). */
	public const VERSION = 2;

	/** Ścieżki pól z danymi zewnętrznymi (do oznaczenia w promptach — STEP 17). */
	private const UNTRUSTED = [
		'topic.label',
		'keywords[].keyword',
		'keywords[].serp.url',
		'keywords[].target.url',
		'target.url',
		'target.votes[].url',
		'target.alternatives[].url',
		'target.hints[].url',
		'target.derived[].url',
		'serp.url',
		'conflicts[].urls[]',
		'conflicts[].keyword',
		'gap[].best_competitor',
		'content_gap[].label',
		'opportunities[].page',
		'discovery[].target_url',
		'grouping.suggestions[].label',
	];

	/**
	 * @param list<array<string, mixed>> $members frazy tematu (`TopicRepository::members`)
	 * @param array<string, array<string, mixed>> $evidence id kandydata → dowody (`strategy_keywords.evidence`)
	 * @param bool $withNote notatka wewnętrzna tylko dla uprawnionych (D62)
	 * @return array<string, mixed>
	 */
	public function build(TopicRow $topic, array $members, array $evidence, bool $withNote = true): array
	{
		$analysis = $topic->analysis ?? [];
		$basis = [];

		foreach ((array) ($analysis['members'] ?? []) as $member) {
			if (is_array($member) && isset($member['id'])) {
				$basis[(string) $member['id']] = $member;
			}
		}

		$keywords = [];
		$gaps = [];
		$contentGaps = [];
		$opportunities = [];
		$discovery = [];

		foreach ($members as $member) {
			$id = (string) $member['id'];
			$proof = $evidence[$id] ?? [];
			$intel = is_array($proof['serp']['intel'] ?? null) ? $proof['serp']['intel'] : null;
			$keywords[] = [
				'id' => $id,
				'keyword' => (string) $member['keyword'],
				'role' => ($analysis['leader'] ?? null) === $id ? 'leader' : 'member',
				'basis' => $basis[$id]['basis'] ?? null,
				'pinned' => (bool) $member['pinned'],
				'sources' => array_map(static fn (StrategySource $source): string => $source->value, StrategySource::fromBits((int) ($member['sources'] ?? 0))),
				'market' => ['volume' => $member['volume'], 'difficulty' => $member['difficulty'], 'intent' => $member['intent'], 'cpc' => $member['cpc'] ?? null],
				'gsc' => is_array($proof['gsc'] ?? null) ? [
					'impressions' => $proof['gsc']['impressions'] ?? null,
					'clicks' => $proof['gsc']['clicks'] ?? null,
					'average_position_gsc' => $proof['gsc']['position'] ?? null,
					'pages' => $proof['gsc']['pages_total'] ?? null,
				] : null,
				'serp' => $intel === null ? null : [
					'snapshot' => $intel['snapshot'] ?? null,
					'checked_at' => $intel['checked_at'] ?? null,
					'freshness' => $intel['freshness'] ?? null,
					'rank' => $intel['project']['rank'] ?? null,
					'url' => $intel['project']['url'] ?? null,
				],
				'target' => $basis[$id]['target'] ?? ['state' => $member['target_state'], 'url' => $member['target_url']],
			];

			if (is_array($proof['gap'] ?? null)) {
				$gap = $proof['gap'];
				$gaps[$id] = [
					'keyword' => $id,
					'id' => $gap['id'] ?? null,
					'gap_type' => $gap['gap_type'] ?? null,
					'visibility' => $gap['visibility'] ?? null,
					'visibility_source' => $gap['visibility_source'] ?? null,
					'project_rank_labs' => $gap['project_labs_rank'] ?? null,
					'competitors' => $gap['competitors'] ?? null,
					'competitors_top10' => $gap['competitors_top10'] ?? null,
					'best_competitor' => is_array($gap['best_competitor'] ?? null) ? [
						'name' => $gap['best_competitor']['name'] ?? null,
						'domain' => $gap['best_competitor']['domain'] ?? null,
						'rank_labs' => $gap['best_competitor']['rank_labs'] ?? null,
					] : null,
					'priority' => $gap['priority'] ?? null,
				];
			}

			if (is_array($proof['content_gap'] ?? null) && isset($proof['content_gap']['id'])) {
				$cluster = $proof['content_gap'];
				$contentGaps[(string) $cluster['id']] = [
					'id' => (string) $cluster['id'],
					'label' => $cluster['label'] ?? null,
					'content_gap' => $cluster['content_gap'] ?? null,
					'confidence' => $cluster['confidence'] ?? null,
					'reason' => $cluster['reason'] ?? null,
				];
			}

			if (is_array($proof['discovery'] ?? null) && isset($proof['discovery']['id'])) {
				$found = $proof['discovery'];
				$discovery[$id] = [
					'keyword' => $id,
					'id' => (string) $found['id'],
					'status' => $found['status'] ?? null,
					'priority' => $found['priority'] ?? null,
					'visibility_gsc' => $found['visibility_gsc'] ?? null,
					'target_url' => $found['target_url'] ?? null,
				];
			}

			foreach ((array) ($proof['opportunity']['direct'] ?? []) as $item) {
				if (is_array($item) && isset($item['id'])) {
					$opportunities[(string) $item['id']] = [
						'id' => (string) $item['id'],
						'type' => $item['type'] ?? null,
						'status' => $item['status'] ?? null,
						'priority' => $item['priority'] ?? null,
						'confidence' => $item['confidence'] ?? null,
						'page' => $item['page'] ?? null,
						'link' => $item['link'] ?? null,
					];
				}
			}
		}

		ksort($gaps);
		ksort($contentGaps);
		ksort($opportunities);
		ksort($discovery);
		$decision = (array) ($analysis['decision'] ?? []);
		$action = (string) ($topic->action ?? '');
		$body = [
			'context_version' => self::VERSION,
			'kind' => 'strategy_topic',
			'rules_version' => $analysis['v'] ?? null,
			'topic' => [
				'id' => $topic->publicId,
				'label' => $topic->label,
				'active' => $topic->active,
				'keywords' => $topic->keywordsCount,
				'demand' => $topic->demand,
			],
			'keywords' => $keywords,
			'grouping' => [
				'leader' => $analysis['leader'] ?? null,
				'basis' => array_map(static fn (array $keyword): array => ['keyword' => $keyword['id'], 'basis' => $keyword['basis']], $keywords),
				'suggestions' => array_values((array) ($analysis['suggestions'] ?? [])),
			],
			'decision' => [
				'action' => $action,
				'action_label' => StrategyAction::tryFrom($action)?->label(),
				'reason' => $topic->actionReason,
				'reason_label' => $topic->actionReason === null ? null : ReasonCode::label($topic->actionReason),
				'basis' => $decision['basis'] ?? [],
				'checks' => $decision['checks'] ?? [],
			],
			'confidence' => $analysis['confidence'] ?? null,
			'priority' => $analysis['priority'] ?? null,
			'target' => ($analysis['target'] ?? []) + ['state_label' => TargetState::tryFrom((string) $topic->targetState)?->label()],
			'conflicts' => array_values((array) ($analysis['conflicts'] ?? [])),
			'gsc' => $analysis['facts']['gsc'] ?? null,
			'facts' => $analysis['facts'] ?? null,
			'serp' => $analysis['serp'] ?? null,
			'gap' => array_values($gaps),
			'content_gap' => array_values($contentGaps),
			'opportunities' => array_values($opportunities),
			'discovery' => array_values($discovery),
			'untrusted' => self::UNTRUSTED,
		];
		$hash = hash('sha256', (string) json_encode(self::canonical($body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

		return $body + [
			'workflow' => [
				'status' => $topic->status,
				'status_changed_at' => $topic->statusChangedAt,
				'completed_on' => $topic->completedOn,
				'decision_changed' => $topic->decisionChanged,
				'note' => $withNote ? $topic->note : null,
			],
			'evidence_hash' => $hash,
		];
	}

	/**
	 * Kanoniczna postać (klucze tablic asocjacyjnych posortowane rekurencyjnie, listy w kolejności).
	 */
	public static function canonical(mixed $value): mixed
	{
		if (! is_array($value)) {
			return $value;
		}

		if (! array_is_list($value)) {
			ksort($value, SORT_STRING);
		}

		return array_map(self::canonical(...), $value);
	}
}
