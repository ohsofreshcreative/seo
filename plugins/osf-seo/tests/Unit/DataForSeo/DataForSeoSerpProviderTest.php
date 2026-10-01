<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\DataForSeo;

use InvalidArgumentException;
use OsfSeo\DataForSeo\DataForSeoClient;
use OsfSeo\DataForSeo\DataForSeoConfig;
use OsfSeo\DataForSeo\DataForSeoSerpProvider;
use OsfSeo\Http\TransportException;
use OsfSeo\Market\ProviderErrorCategory;
use OsfSeo\Market\ProviderException;
use OsfSeo\Serp\ItemTypes;
use OsfSeo\Serp\SerpContext;
use OsfSeo\Serp\SerpDevice;
use OsfSeo\Serp\SerpItem;
use OsfSeo\Serp\SerpTaskRequest;
use OsfSeo\Support\Logger;
use OsfSeo\Support\Ulid;
use OsfSeo\Tests\Support\DataForSeoFakes;
use OsfSeo\Tests\Support\FakeHttpTransport;
use OsfSeo\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\TestCase;

final class DataForSeoSerpProviderTest extends TestCase
{
	private FakeHttpTransport $http;

	private DataForSeoSerpProvider $provider;

	private SerpContext $context;

	protected function setUp(): void
	{
		DataForSeoFakes::configure();
		$this->http = new FakeHttpTransport();
		$config = new DataForSeoConfig();
		$this->provider = new DataForSeoSerpProvider(new DataForSeoClient($config, $this->http, new RecordingSleeper(), new Logger(Logger::ERROR, static function (): void {
		})), $config);
		$this->context = new SerpContext(2616, 'pl', SerpDevice::Desktop, 100);
	}

	protected function tearDown(): void
	{
		DataForSeoFakes::clear();
		putenv(DataForSeoConfig::PRICE_SERP_PAGE);
		putenv(DataForSeoConfig::PRICE_SERP_NEXT_PAGE);
	}

	public function test_task_post_payload_uses_standard_queue_market_device_and_bounded_top100(): void
	{
		$tags = [Ulid::generate(), Ulid::generate()];
		$this->http->pushJson(200, DataForSeoFakes::serpTasksCreated($tags));

		$this->provider->submit([
			new SerpTaskRequest($tags[0], 'strony internetowe', $this->context),
			new SerpTaskRequest($tags[1], 'sklep internetowy', new SerpContext(2616, 'pl', SerpDevice::Mobile, 20)),
		]);

		self::assertSame('https://api.dataforseo.com/v3/serp/google/organic/task_post', $this->http->requests[0]['url']);
		self::assertSame('POST', $this->http->requests[0]['method']);
		self::assertCount(1, $this->http->requests, 'Jedno żądanie HTTP dla całej paczki.');
		self::assertSame([
			[
				'keyword' => 'strony internetowe',
				'location_code' => 2616,
				'language_code' => 'pl',
				'device' => 'desktop',
				'os' => 'windows',
				'depth' => 100,
				'max_crawl_pages' => 10,
				'tag' => $tags[0],
				'remove_from_url' => ['srsltid'],
			],
			[
				'keyword' => 'sklep internetowy',
				'location_code' => 2616,
				'language_code' => 'pl',
				'device' => 'mobile',
				'os' => 'android',
				'depth' => 20,
				'max_crawl_pages' => 2,
				'tag' => $tags[1],
				'remove_from_url' => ['srsltid'],
			],
		], json_decode($this->http->requests[0]['body'], true));

		$body = (string) $this->http->requests[0]['body'];

		foreach (['priority', 'calculate_rectangles', 'load_async_ai_overview', 'people_also_ask_click_depth', 'postback_url', 'pingback_url'] as $paid) {
			self::assertStringNotContainsString($paid, $body, "Bez parametru {$paid} (Standard, bez płatnych dodatków).");
		}
	}

	public function test_batch_is_limited_to_100_tasks_and_rejects_search_operators_before_sending(): void
	{
		$tasks = array_map(fn (int $i): SerpTaskRequest => new SerpTaskRequest(Ulid::generate(), 'fraza ' . $i, $this->context), range(1, 101));

		try {
			$this->provider->submit($tasks);
			self::fail('Ponad 100 zadań w jednym POST.');
		} catch (InvalidArgumentException) {
		}

		try {
			$this->provider->submit([new SerpTaskRequest(Ulid::generate(), 'site:example.pl buty', $this->context)]);
			self::fail('Operator wyszukiwania (koszt ×5).');
		} catch (InvalidArgumentException) {
		}

		self::assertSame([], $this->http->requests, 'Nic nie zostało wysłane.');
	}

	public function test_submissions_are_matched_by_tag_with_per_task_errors_and_missing_tasks_as_uncertain(): void
	{
		$tags = [Ulid::generate(), Ulid::generate(), Ulid::generate()];
		$response = DataForSeoFakes::serpTasksCreated([$tags[1], $tags[0]], 0.00465, [$tags[0] => 40501]);
		$this->http->pushJson(200, $response);

		$result = $this->provider->submit(array_map(fn (string $tag): SerpTaskRequest => new SerpTaskRequest($tag, 'fraza ' . $tag, $this->context), $tags));

		self::assertTrue($result[$tags[1]]->accepted());
		self::assertSame($response['tasks'][0]['id'], $result[$tags[1]]->taskId, 'Dopasowanie po tagu, nie po kolejności.');
		self::assertSame(0.00465, $result[$tags[1]]->cost);
		self::assertFalse($result[$tags[0]]->accepted());
		self::assertSame(ProviderErrorCategory::InvalidRequest, $result[$tags[0]]->error);
		self::assertSame(40501, $result[$tags[0]]->statusCode);
		self::assertSame(ProviderErrorCategory::MalformedResponse, $result[$tags[2]]->error, 'Brak odpowiedzi dla zadania = wynik niepewny.');
	}

	public function test_whole_post_failures_are_classified(): void
	{
		$task = [new SerpTaskRequest(Ulid::generate(), 'strony internetowe', $this->context)];

		$this->http->push(new TransportException('timeout'));

		try {
			$this->provider->submit($task);
			self::fail('Brak odpowiedzi.');
		} catch (ProviderException $exception) {
			self::assertSame(ProviderErrorCategory::Network, $exception->category());
		}

		self::assertCount(1, $this->http->requests, 'Płatny POST po błędzie sieci nie jest ponawiany.');

		$this->http->pushJson(200, ['status_code' => 40200, 'status_message' => 'Payment Required.']);

		try {
			$this->provider->submit($task);
			self::fail('Brak środków.');
		} catch (ProviderException $exception) {
			self::assertSame(ProviderErrorCategory::Billing, $exception->category());
		}
	}

	public function test_tasks_ready_returns_ids_and_tags(): void
	{
		$id = DataForSeoFakes::taskId();
		$tag = Ulid::generate();
		$this->http->pushJson(200, DataForSeoFakes::serpTasksReady([[$id, $tag], [DataForSeoFakes::taskId(), null], ['nie uuid!', 'x']]));

		$ready = $this->provider->readyTasks();

		self::assertSame('https://api.dataforseo.com/v3/serp/google/organic/tasks_ready', $this->http->requests[0]['url']);
		self::assertSame('GET', $this->http->requests[0]['method']);
		self::assertCount(2, $ready, 'Nieprawidłowy identyfikator pominięty.');
		self::assertSame([$id, $tag], [$ready[0]->id, $ready[0]->tag]);
		self::assertNull($ready[1]->tag);
	}

	public function test_task_get_pending_no_results_and_errors(): void
	{
		$id = DataForSeoFakes::taskId();
		$this->http->pushJson(200, DataForSeoFakes::taskInQueue($id));
		self::assertNull($this->provider->fetch($id), 'Zadanie w kolejce = jeszcze nie gotowe.');
		self::assertSame('https://api.dataforseo.com/v3/serp/google/organic/task_get/advanced/' . $id, $this->http->requests[0]['url']);

		$this->http->pushJson(200, DataForSeoFakes::envelope(['id' => $id, 'status_code' => 40102, 'status_message' => 'No Search Results.']));
		self::assertSame([], $this->provider->fetch($id)?->items, 'Brak wyników = pomiar zakończony bez wyników.');

		$this->http->pushJson(200, DataForSeoFakes::envelope(['id' => $id, 'status_code' => 40401, 'status_message' => 'Task Not Found.']));

		try {
			$this->provider->fetch($id);
			self::fail('Błąd zadania.');
		} catch (ProviderException $exception) {
			self::assertSame(ProviderErrorCategory::TaskError, $exception->category());
		}

		$this->expectException(InvalidArgumentException::class);
		$this->provider->fetch('../../etc');
	}

	public function test_full_top100_is_parsed_in_order_with_rank_group_and_rank_absolute(): void
	{
		$items = DataForSeoFakes::serpTop([3 => 'konkurent.pl', 7 => 'www.example.pl', 28 => 'blog.example.pl'], 100, 2);
		array_unshift($items, ['type' => 'paid', 'rank_group' => 1, 'rank_absolute' => 1, 'domain' => 'reklama.pl', 'url' => 'https://reklama.pl/'],
			['type' => 'featured_snippet', 'rank_group' => 1, 'rank_absolute' => 2, 'page' => 1, 'domain' => 'Example.PL', 'title' => 'Wyróżniony', 'featured_title' => 'Jak?', 'description' => 'Odpowiedź', 'url' => 'https://example.pl/poradnik/'],
			['type' => 'people_also_ask', 'rank_group' => 1, 'rank_absolute' => 5, 'items' => []]);
		$id = DataForSeoFakes::taskId();
		$this->http->pushJson(200, DataForSeoFakes::serpResult($id, 'strony internetowe', $items, '2026-01-15 10:05:00 +00:00', ['paid', 'featured_snippet', 'organic', 'people_also_ask', 'ai_overview', 'nowy_typ'], ['keyword' => 'strony internetowe', 'type' => 'showing_results_for']));

		$page = $this->provider->fetch($id);

		self::assertCount(101, $page->items, 'Pełne TOP100 + wyróżniony fragment; reklamy i moduły nie są wynikami.');
		self::assertSame(100, $page->organicCount());
		self::assertSame(SerpItem::TYPE_FEATURED_SNIPPET, $page->items[0]->type);
		self::assertSame('example.pl', $page->items[0]->host, 'Domena znormalizowana (małe litery).');
		self::assertSame(['featured_title' => 'Jak?'], $page->items[0]->extra);
		self::assertSame(range(1, 100), array_map(static fn (SerpItem $item): int => $item->rankGroup, array_slice($page->items, 1)), 'Kolejność dostawcy.');
		self::assertSame([7, 9], [$page->items[7]->rankGroup, $page->items[7]->rankAbsolute], 'Pozycja SERP = rank_group; rank_absolute zachowany osobno.');
		self::assertSame('example.pl', $page->items[7]->host, 'www. usunięte.');
		self::assertSame('blog.example.pl', $page->items[28]->host);
		self::assertSame('https://www.example.pl/strona-7/', $page->items[7]->url, 'Adres bez zmian.');
		self::assertSame('2026-01-15 10:05:00', $page->checkedAt);
		self::assertSame('google.pl', $page->seDomain);
		self::assertSame(['organic', 'paid', 'featured_snippet', 'people_also_ask', 'ai_overview', 'other'], ItemTypes::fromMask($page->itemTypes));
		self::assertSame(['showing_results_for', 'strony internetowe'], [$page->spellType, $page->spellKeyword]);
	}

	public function test_multiple_urls_of_one_domain_rich_metadata_and_invalid_items(): void
	{
		$items = [
			DataForSeoFakes::serpOrganic(1, 'konkurent.pl', 'https://konkurent.pl/a/?srsltid=x#sekcja', null, [
				'checks' => ['is_featured_snippet', 'amp_version'],
				'rating' => ['rating_type' => 'Max5', 'value' => 4.6, 'votes_count' => 120, 'rating_max' => 5],
				'price' => ['current' => 99.9, 'currency' => 'PLN'],
				'links' => [['title' => 'Kontakt', 'url' => 'https://konkurent.pl/kontakt/'], ['title' => 'Zły', 'url' => 'javascript:alert(1)']],
				'highlighted' => ['strony', 'internetowe'],
				'timestamp' => '2026-01-10 08:00:00 +02:00',
			]),
			DataForSeoFakes::serpOrganic(2, 'konkurent.pl', 'https://konkurent.pl/b/'),
			['type' => 'organic', 'rank_group' => 3, 'rank_absolute' => 3, 'domain' => 'zly.pl', 'url' => 'ftp://zly.pl/'],
			['type' => 'organic', 'rank_group' => 4, 'rank_absolute' => 4, 'domain' => '', 'url' => 'https://xn--d1acufc.xn--p1ai/'],
			['type' => 'organic', 'rank_group' => 'x', 'rank_absolute' => 5, 'domain' => 'a.pl', 'url' => 'https://a.pl/'],
		];
		$page = DataForSeoSerpProvider::parse(DataForSeoFakes::serpResult(DataForSeoFakes::taskId(), 'x', $items)['tasks'][0]['result']);

		self::assertCount(3, $page->items);
		self::assertSame(2, $page->skipped, 'Adres nie-http i pozycja spoza typu pominięte.');
		self::assertSame(['https://konkurent.pl/a/?srsltid=x', 'https://konkurent.pl/b/'], [$page->items[0]->url, $page->items[1]->url], 'Obie strony domeny zachowane; fragment usunięty.');
		self::assertSame('xn--d1acufc.xn--p1ai', $page->items[2]->host, 'Domena z adresu, gdy brak pola domain.');
		$first = $page->items[0];
		self::assertSame(SerpItem::FLAG_FEATURED | SerpItem::FLAG_AMP | SerpItem::FLAG_RATING | SerpItem::FLAG_PRICE | SerpItem::FLAG_SITELINKS, $first->flags);
		self::assertSame(['value' => 4.6, 'votes' => 120, 'max' => 5.0], $first->extra['rating']);
		self::assertSame([['title' => 'Kontakt', 'url' => 'https://konkurent.pl/kontakt/']], $first->extra['links'], 'Tylko linki http(s).');
		self::assertSame('2026-01-10 06:00:00', $first->extra['published_at'], 'Data publikacji w UTC.');
		self::assertNotSame($first->snippetHash(), $page->items[1]->snippetHash());
		self::assertSame(md5('https://konkurent.pl/b/'), $page->items[1]->urlHash());
	}

	public function test_cost_estimate_is_first_page_plus_discounted_next_pages_and_configurable(): void
	{
		self::assertSame(0.00465, $this->provider->estimateCost($this->context), 'TOP100 = 10 stron: 0,0006 + 9 × 0,00045.');
		self::assertSame(0.0006, $this->provider->estimateCost(new SerpContext(2616, 'pl', SerpDevice::Desktop, 10)));
		self::assertSame(0.00105, $this->provider->estimateCost(new SerpContext(2616, 'pl', SerpDevice::Desktop, 20)));

		putenv(DataForSeoConfig::PRICE_SERP_PAGE . '=0.0006');
		putenv(DataForSeoConfig::PRICE_SERP_NEXT_PAGE . '=0.0006');
		self::assertSame(0.006, $this->provider->estimateCost($this->context), 'Cena bez rabatu (wariant z FAQ) ustawiana konfiguracją.');
	}
}
