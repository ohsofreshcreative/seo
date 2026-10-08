<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Strategy\Core;

use OsfSeo\Strategy\Serp\OverlapSide;
use OsfSeo\Strategy\Serp\SerpFreshness;
use OsfSeo\Strategy\Target\TargetPageResolver;
use OsfSeo\Strategy\Topics\KeywordSignals;
use OsfSeo\Strategy\Topics\OverlapIndex;
use OsfSeo\Strategy\Topics\TopicClusterer;
use OsfSeo\Strategy\Topics\TopicGroup;
use OsfSeo\Strategy\Topics\TopicIdentity;
use OsfSeo\Tests\Support\StrategyFakes;
use PHPUnit\Framework\TestCase;

/**
 * Grupowanie tematów (faza C, D60, przypadki K–N) i stabilne ID tematów: dodanie i usunięcie frazy, podział, scalenie, zmiana lidera,
 * przypięcia — deterministycznie.
 */
final class TopicGroupingTest extends TestCase
{
	public function test_k_strong_serp_overlap_with_the_same_target_makes_one_topic(): void
	{
		$a = $this->keyword(1, 'pozycjonowanie stron', 900, '/pozycjonowanie/');
		$b = $this->keyword(2, 'pozycjonowanie stron www', 300, '/pozycjonowanie/');
		$groups = $this->cluster([$a, $b], [1 => [11, 12, 13, 14, 15, 16], 2 => [11, 12, 13, 14, 15, 17]]);

		self::assertSame([[1, 2]], self::members($groups['groups']));
		self::assertSame(TopicGroup::SAME_TARGET, $groups['groups'][0]->basis[2]);

		// Bez strony docelowej — sam silny overlap (≥ 4 wspólne adresy TOP10 przy świeżych pomiarach) też łączy.
		$c = $this->keyword(3, 'agencja seo', 900, null);
		$d = $this->keyword(4, 'firma seo', 300, null);
		$overlap = $this->cluster([$c, $d], [3 => [21, 22, 23, 24, 25], 4 => [21, 22, 23, 24, 26]]);
		self::assertSame([[3, 4]], self::members($overlap['groups']));
		self::assertSame(TopicGroup::SERP_OVERLAP, $overlap['groups'][0]->basis[4]);
	}

	public function test_l_strong_overlap_with_different_confirmed_targets_is_not_merged_but_is_conflict_evidence(): void
	{
		$a = $this->keyword(1, 'pozycjonowanie stron', 900, '/pozycjonowanie/', confirmed: true);
		$b = $this->keyword(2, 'pozycjonowanie sklepów', 300, '/pozycjonowanie-sklepow/', confirmed: true);
		$result = $this->cluster([$a, $b], [1 => [11, 12, 13, 14, 15, 16], 2 => [11, 12, 13, 14, 15, 17]]);

		self::assertSame([[1], [2]], self::members($result['groups']));
		self::assertCount(1, $result['blocked']);
		self::assertSame([1, 2, true], [$result['blocked'][0]['a'], $result['blocked'][0]['b'], $result['blocked'][0]['confirmed']]);
		self::assertContains('serp_strong', $result['groups'][0]->suggestions[1], 'Zablokowane scalenie zostaje jako „możliwa grupa”.');
	}

	public function test_m_n_core_key_or_token_key_alone_never_merges(): void
	{
		$a = $this->keyword(1, 'strony www warszawa', 900, null, core: 'abc');
		$b = $this->keyword(2, 'warszawa strony www', 300, null, core: 'abc');
		$c = $this->keyword(3, 'strony internetowe warszawa', 200, null, core: 'abc');
		$result = $this->cluster([$a, $b, $c], []);

		self::assertSame([[1], [2], [3]], self::members($result['groups']), 'Wspólny core_key i zbiór wyrazów — tylko sugestia.');
		self::assertSame(['core_key', 'token_key'], $result['groups'][0]->suggestions[1]);
		self::assertSame(['core_key'], $result['groups'][0]->suggestions[2]);

		// Wspólna strona główna jako strona docelowa też nie scala.
		$home = $this->cluster([$this->keyword(5, 'example', 900, '/', confirmed: true), $this->keyword(6, 'example opinie', 100, '/', confirmed: true)], []);
		self::assertSame([[5], [6]], self::members($home['groups']));
		self::assertSame(['same_home'], $home['groups'][0]->suggestions[1]);
	}

	public function test_grouping_compares_only_with_the_leader_without_chains(): void
	{
		// A~B silny overlap, B~C silny overlap, A~C tylko 2 wspólne adresy — C nie dołącza „łańcuchem” przez B.
		$a = $this->keyword(1, 'a', 900, null);
		$b = $this->keyword(2, 'b', 500, null);
		$c = $this->keyword(3, 'c', 100, null);
		$result = $this->cluster([$a, $b, $c], [1 => [11, 12, 13, 14, 15, 16], 2 => [11, 12, 13, 14, 17, 18], 3 => [13, 14, 17, 18, 19, 20]]);

		self::assertSame([[1, 2], [3]], self::members($result['groups']));
	}

	public function test_pins_take_precedence_over_automatic_grouping(): void
	{
		// Przypięcie do nowego tematu oddziela frazę od tematu ze wspólną stroną docelową.
		$a = $this->keyword(1, 'pozycjonowanie stron', 900, '/pozycjonowanie/');
		$b = $this->keyword(2, 'pozycjonowanie stron www', 300, '/pozycjonowanie/', pinned: 70);
		$c = $this->keyword(3, 'audyt seo', 100, '/audyt/', pinned: 70);
		$result = $this->cluster([$a, $b, $c], []);
		self::assertSame([[1]], self::members($result['groups']), 'Frazy przypięte poza grupowaniem automatycznym.');

		$previous = [10 => ['leader' => 1, 'members' => [1, 2], 'active' => true], 70 => ['leader' => null, 'members' => [], 'active' => false]];
		$identity = (new TopicIdentity())->assign($result['groups'], $previous, TopicIdentity::pins([$a, $b, $c]));
		self::assertSame([[1], [2, 3]], self::members($identity['groups']));
		self::assertSame([10, 70], $identity['ids']);
		self::assertSame([70, TopicGroup::PIN], [$identity['groups'][1]->pinnedTopicId, $identity['groups'][1]->basis[3]]);

		// Przypięcie do istniejącego tematu dołącza frazę, temat zachowuje pozostałe frazy i ID.
		$d = $this->keyword(4, 'audyt strony', 80, '/audyt-strony/', pinned: 10);
		$join = (new TopicIdentity())->assign($this->cluster([$a, $d], [])['groups'], [10 => ['leader' => 1, 'members' => [1], 'active' => true], 11 => ['leader' => 4, 'members' => [4], 'active' => true]], TopicIdentity::pins([$a, $d]));
		self::assertSame([[1, 4]], self::members($join['groups']));
		self::assertSame([10], $join['ids']);
		self::assertSame([11 => ['reason' => TopicIdentity::REASON_MERGED, 'into' => 10]], $join['retired']);
	}

	public function test_topic_ids_are_stable_when_keywords_are_added_removed_or_the_leader_changes(): void
	{
		$identity = new TopicIdentity();
		$previous = [10 => ['leader' => 1, 'members' => [1, 2], 'active' => true]];

		// Dodanie frazy: A+B → X, A+B+C → nadal X.
		self::assertSame([10], $identity->assign([self::group([1, 2, 3])], $previous)['ids']);

		// Usunięcie frazy (przestała być kandydatem): X = A+B+C → A+B.
		self::assertSame([10], $identity->assign([self::group([1, 2])], [10 => ['leader' => 1, 'members' => [1, 2, 3], 'active' => true]])['ids']);

		// Zmiana lidera (B ma teraz większy popyt) — ten sam temat.
		self::assertSame([10], $identity->assign([self::group([2, 1])], $previous)['ids']);

		// Temat bez żadnej aktywnej frazy — nieaktywny, nowa grupa z innych fraz dostaje nowe ID.
		$gone = $identity->assign([self::group([5])], $previous);
		self::assertSame([null], $gone['ids']);
		self::assertSame([10 => ['reason' => TopicIdentity::REASON_NO_KEYWORDS, 'into' => null]], $gone['retired']);
	}

	public function test_split_keeps_the_id_with_the_logical_successor_and_merge_keeps_the_larger_topic(): void
	{
		$identity = new TopicIdentity();

		// Podział X = A+B+C+D na A+B i C+D: remis 50/50 → grupa z poprzednim liderem (A) zachowuje X, druga — nowe ID (split).
		$split = $identity->assign([self::group([3, 4]), self::group([1, 2])], [10 => ['leader' => 1, 'members' => [1, 2, 3, 4], 'active' => true]]);
		self::assertSame([null, 10], $split['ids']);
		self::assertSame([0 => 10], $split['splits']);
		self::assertSame([], $split['retired']);

		// Scalenie X = A+B i Y = C+D+E w jedną grupę: zostaje temat z większym pokryciem (Y), X — scalony do Y.
		$merge = $identity->assign([self::group([1, 2, 3, 4, 5])], [10 => ['leader' => 1, 'members' => [1, 2], 'active' => true], 11 => ['leader' => 3, 'members' => [3, 4, 5], 'active' => true]]);
		self::assertSame([11], $merge['ids']);
		self::assertSame([10 => ['reason' => TopicIdentity::REASON_MERGED, 'into' => 11]], $merge['retired']);

		// Równe pokrycie — starszy temat (mniejsze ID); wynik niezależny od kolejności wejścia.
		$tie = $identity->assign([self::group([3, 4, 1, 2])], [11 => ['leader' => 3, 'members' => [3, 4], 'active' => true], 10 => ['leader' => 1, 'members' => [1, 2], 'active' => true]]);
		self::assertSame([10], $tie['ids'], 'Równe pokrycie i lider w grupie — starszy temat.');

		// Rozproszenie: żadna grupa nie ma 50% fraz X.
		$spread = $identity->assign([self::group([1]), self::group([2]), self::group([3])], [10 => ['leader' => 1, 'members' => [1, 2, 3], 'active' => true]]);
		self::assertSame([null, null, null], $spread['ids']);
		self::assertSame(TopicIdentity::REASON_SPLIT, $spread['retired'][10]['reason']);
	}

	public function test_undoing_a_merge_restores_the_former_topic(): void
	{
		// K (lider 4) scalony przypięciem z X; po odpięciu fraza 4 tworzy grupę — wraca temat K, nie nowe ID.
		$result = (new TopicIdentity())->assign([self::group([1, 2]), self::group([4])], [
			10 => ['leader' => 1, 'members' => [1, 2, 4], 'active' => true],
			11 => ['leader' => 4, 'members' => [], 'active' => false],
		]);

		self::assertSame([10, 11], $result['ids']);
		self::assertSame([], $result['retired']);
	}

	public function test_pinned_keywords_do_not_vote_and_keep_their_topic(): void
	{
		$result = (new TopicIdentity())->assign([self::group([1, 2])], [10 => ['leader' => 1, 'members' => [1, 2, 3, 4], 'active' => true], 11 => ['leader' => null, 'members' => [], 'active' => false]], [11 => [3, 4]]);

		self::assertSame([[1, 2], [3, 4]], self::members($result['groups']));
		self::assertSame([10, 11], $result['ids'], 'Istotne frazy X to tylko frazy grupowane automatycznie (1, 2) — X zostaje przy nich.');
	}

	/**
	 * @param list<KeywordSignals> $keywords
	 * @param array<int, list<int>> $top10 id frazy → adresy TOP10 (świeży pomiar, ten sam kontekst)
	 * @return array{groups: list<TopicGroup>, blocked: list<array<string, mixed>>}
	 */
	private function cluster(array $keywords, array $top10): array
	{
		$resolver = new TargetPageResolver();
		$targets = [];

		foreach ($keywords as $keyword) {
			$targets[$keyword->marketKeywordId] = $resolver->forKeyword($keyword);
		}

		$sides = [];

		foreach ($top10 as $id => $urls) {
			$results = [];

			foreach ($urls as $rank => $url) {
				$results[$url] = ['rank' => $rank + 1, 'domain_id' => $url * 10, 'home' => false];
			}

			$sides[$id] = new OverlapSide($id, 'k' . $id, $id * 100, '2616|pl|desktop', SerpFreshness::FRESH, $results);
		}

		$clusterer = new TopicClusterer();
		$overlaps = new OverlapIndex($sides, [], true);
		$result = $clusterer->cluster($keywords, $targets, $overlaps);
		$clusterer->suggest($result['groups'], $keywords, $targets, $overlaps);

		return $result;
	}

	private function keyword(int $id, string $text, int $volume, ?string $path, bool $confirmed = false, ?string $core = null, ?int $pinned = null): KeywordSignals
	{
		$url = $path === null ? null : 'https://example.pl' . $path;

		return StrategyFakes::keyword($text, [
			'id' => $id,
			'volume' => $volume,
			'core' => $core,
			'pinned' => $pinned,
			'gsc' => $url === null ? [] : ['pages' => [[$url, 400]]],
			'gap' => $url !== null && $confirmed ? ['gap_type' => 'weak', 'visibility_source' => 'labs', 'project_labs_rank' => 12, 'target' => ['url' => $url, 'source' => 'labs']] : null,
		]);
	}

	/**
	 * @param list<int> $members
	 */
	private static function group(array $members): TopicGroup
	{
		$group = new TopicGroup($members[0]);

		foreach (array_slice($members, 1) as $member) {
			$group->add($member, TopicGroup::SAME_TARGET);
		}

		return $group;
	}

	/**
	 * @param list<TopicGroup> $groups
	 * @return list<list<int>>
	 */
	private static function members(array $groups): array
	{
		return array_map(static fn (TopicGroup $group): array => $group->members, $groups);
	}
}
