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

	public function test_link_labels(): void
	{
		self::assertSame('fraza w grupie szansy', OpportunityKeywordIndex::linkLabel(OpportunityKeywordIndex::LINK_MEMBER));
		self::assertSame('ta sama podstrona', OpportunityKeywordIndex::linkLabel(OpportunityKeywordIndex::LINK_PAGE));
		self::assertSame('ta sama fraza', OpportunityKeywordIndex::linkLabel(OpportunityKeywordIndex::LINK_KEYWORD));
	}
}
