<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Opportunities;

use OsfSeo\Opportunities\OpportunityKeywordIndex;
use PHPUnit\Framework\TestCase;

final class OpportunityKeywordIndexTest extends TestCase
{
	public function test_page_group_members_exclude_the_page_url(): void
	{
		[$urls, $members] = OpportunityKeywordIndex::split('near_top', 'https://example.pl/sklep/', null, "https://example.pl/sklep/\nsklep internetowy\nsklep na wordpress");

		self::assertSame(['https://example.pl/sklep/'], $urls);
		self::assertSame(['sklep internetowy', 'sklep na wordpress'], $members);
	}

	public function test_keyword_level_opportunity_keeps_its_keyword(): void
	{
		[$urls, $members] = OpportunityKeywordIndex::split('weak_position', null, 'Buty Damskie', 'Buty Damskie');

		self::assertSame([], $urls);
		self::assertSame(['Buty Damskie'], $members);
	}

	public function test_cannibalization_pair_urls_come_first(): void
	{
		[$urls, $members] = OpportunityKeywordIndex::split('cannibalization', 'https://example.pl/a/', null, "https://example.pl/a/\nhttps://example.pl/b/\nfraza 1\nfraza 2");

		self::assertSame(['https://example.pl/a/', 'https://example.pl/b/'], $urls);
		self::assertSame(['fraza 1', 'fraza 2'], $members);
	}

	public function test_numeric_and_duplicate_keywords_stay_strings_and_unique(): void
	{
		[, $members] = OpportunityKeywordIndex::split('low_ctr', 'https://example.pl/', null, "https://example.pl/\n2024\n2024\nłódź sklep");

		self::assertSame(['2024', 'łódź sklep'], $members);
	}

	public function test_truncated_search_text_drops_the_possibly_cut_last_line(): void
	{
		// Regresja: „…\nsklep internetowy” ucięte na „…\nsklep” dopasowywało krótszą frazę GSC „sklep” jako członka grupy.
		$head = "https://example.pl/sklep/\n" . implode("\n", array_map(static fn (int $i): string => 'fraza ' . $i, range(1, 1500)));
		$text = mb_substr($head . "\nsklep internetowy", 0, OpportunityKeywordIndex::SEARCH_TEXT_LIMIT - 6) . "\nsklep";
		self::assertSame(OpportunityKeywordIndex::SEARCH_TEXT_LIMIT, mb_strlen($text));
		self::assertTrue(OpportunityKeywordIndex::isTruncated($text));

		[, $members] = OpportunityKeywordIndex::split('near_top', 'https://example.pl/sklep/', null, $text);

		self::assertNotContains('sklep', $members);
		self::assertContains('fraza 1', $members);

		// Tekst krótszy niż limit jest pełny — ostatnia linia jest pełną frazą.
		[, $complete] = OpportunityKeywordIndex::split('near_top', 'https://example.pl/sklep/', null, "https://example.pl/sklep/\nfraza 1\nsklep");
		self::assertFalse(OpportunityKeywordIndex::isTruncated("https://example.pl/sklep/\nfraza 1\nsklep"));
		self::assertSame(['fraza 1', 'sklep'], $complete);
	}

	public function test_only_keyword_and_member_links_are_direct(): void
	{
		self::assertTrue(OpportunityKeywordIndex::isDirect(OpportunityKeywordIndex::LINK_KEYWORD));
		self::assertTrue(OpportunityKeywordIndex::isDirect(OpportunityKeywordIndex::LINK_MEMBER));
		self::assertFalse(OpportunityKeywordIndex::isDirect(OpportunityKeywordIndex::LINK_PAGE), 'Wspólna podstrona to kontekst, nie dowód.');
	}

	public function test_link_labels(): void
	{
		self::assertSame('fraza w grupie szansy', OpportunityKeywordIndex::linkLabel(OpportunityKeywordIndex::LINK_MEMBER));
		self::assertSame('ta sama podstrona — kontekst', OpportunityKeywordIndex::linkLabel(OpportunityKeywordIndex::LINK_PAGE));
		self::assertSame('ta sama fraza', OpportunityKeywordIndex::linkLabel(OpportunityKeywordIndex::LINK_KEYWORD));
	}
}
