<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Serp;

use OsfSeo\Serp\DomainFamily;
use OsfSeo\Serp\SerpItem;

/**
 * Kształt wyniku organicznego z danych zapisanego pomiaru (adres, host, flagi i data publikacji z prezentacji wyniku) —
 * deterministyczne reguły w stałej kolejności, każda z kodem powodu i pewnością. Bez pobierania stron i bez AI.
 *
 * Kolejność: platforma wideo → strona główna → listing (ścieżka albo wyszukiwanie) → produkt (ścieżka, cena) → artykuł (data
 * publikacji, encyklopedia, ścieżka) → flaga wideo → dedykowana podstrona (każda inna podstrona, pewność niska) → nieznany.
 */
final class ResultShapeClassifier
{
	/** Platformy wideo (rodziny domen) — wynik jest materiałem wideo. */
	private const VIDEO_HOSTS = ['youtube.com', 'youtu.be', 'vimeo.com', 'dailymotion.com', 'tiktok.com'];

	/** Encyklopedie (rodziny domen) — strona hasła jest treścią informacyjną. */
	private const ENCYCLOPEDIA_HOSTS = ['wikipedia.org', 'wiktionary.org', 'britannica.com'];

	private const LISTING_SEGMENTS = [
		'kategoria', 'kategorie', 'category', 'categories', 'c', 'k', 'listing', 'listings', 'oferty', 'ogloszenia', 'search', 'szukaj',
		'wyszukaj', 'wyszukiwarka', 'tag', 'tags', 'collections', 'catalog', 'katalog', 'produkty', 'products',
	];

	private const SEARCH_PARAMETERS = ['q', 'query', 's', 'search', 'string', 'szukaj', 'keyword', 'phrase'];

	/** Bez „oferta” — w polskich serwisach to zwykle opis usług firmy, nie karta produktu. */
	private const PRODUCT_SEGMENTS = ['product', 'produkt', 'p', 'dp', 'item', 'pd'];

	private const ARTICLE_SEGMENTS = [
		'blog', 'poradnik', 'poradniki', 'porady', 'artykul', 'artykuly', 'article', 'articles', 'news', 'aktualnosci', 'wiadomosci',
		'wiki', 'baza-wiedzy', 'wiedza', 'magazyn', 'magazine', 'guide', 'guides', 'faq', 'pytania', 'jak', 'how-to', 'encyklopedia',
	];

	/**
	 * @return array{shape: ResultShape, confidence: string, reason: string}
	 */
	public function classify(string $url, string $host, int $flags = 0, ?string $publishedAt = null): array
	{
		$parts = parse_url($url);

		if (! is_array($parts) || ! isset($parts['host'])) {
			return self::result(ResultShape::Unknown, SerpConfidence::LOW, 'unparsable_url');
		}

		$host = DomainFamily::normalize($host) ?? strtolower($host);
		$path = strtolower(rawurldecode((string) ($parts['path'] ?? '')));
		$segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
		parse_str((string) ($parts['query'] ?? ''), $query);

		if (self::inFamily($host, self::VIDEO_HOSTS)) {
			return self::result(ResultShape::Video, SerpConfidence::HIGH, 'video_host');
		}

		if (self::isHome($segments, $query)) {
			return self::result(ResultShape::Home, $segments === [] ? SerpConfidence::HIGH : SerpConfidence::MEDIUM, $segments === [] ? 'root_path' : 'language_root');
		}

		if (array_intersect(array_map('strval', array_keys($query)), self::SEARCH_PARAMETERS) !== []) {
			return self::result(ResultShape::Listing, SerpConfidence::MEDIUM, 'search_query');
		}

		if (array_intersect($segments, self::LISTING_SEGMENTS) !== []) {
			return self::result(ResultShape::Listing, SerpConfidence::MEDIUM, 'listing_path');
		}

		$price = ($flags & SerpItem::FLAG_PRICE) !== 0;

		if (array_intersect($segments, self::PRODUCT_SEGMENTS) !== [] || preg_match('#(-p-?\d{3,}|-\d{8,})(\.html?)?$|/\d{5,}(\.html?)?$#', $path) === 1) {
			return self::result(ResultShape::Product, $price ? SerpConfidence::HIGH : SerpConfidence::MEDIUM, 'product_path');
		}

		if ($price) {
			return self::result(ResultShape::Product, SerpConfidence::MEDIUM, 'price');
		}

		if ($publishedAt !== null && $publishedAt !== '') {
			return self::result(ResultShape::Article, SerpConfidence::HIGH, 'published_date');
		}

		if (self::inFamily($host, self::ENCYCLOPEDIA_HOSTS)) {
			return self::result(ResultShape::Article, SerpConfidence::HIGH, 'encyclopedia');
		}

		if (array_intersect($segments, self::ARTICLE_SEGMENTS) !== []) {
			return self::result(ResultShape::Article, SerpConfidence::MEDIUM, 'article_path');
		}

		if (preg_match('#/(19|20)\d{2}/(0?[1-9]|1[0-2])/#', $path) === 1) {
			return self::result(ResultShape::Article, SerpConfidence::MEDIUM, 'date_path');
		}

		if (($flags & SerpItem::FLAG_VIDEO) !== 0) {
			return self::result(ResultShape::Video, SerpConfidence::MEDIUM, 'video_flag');
		}

		return self::result(ResultShape::Subpage, SerpConfidence::LOW, 'deep_path');
	}

	/**
	 * Strona główna: pusta ścieżka, plik indeksu albo katalog języka (`/pl/`, `/en-gb/`) — bez parametrów wyszukiwania.
	 *
	 * @param list<string> $segments
	 * @param array<mixed> $query
	 */
	private static function isHome(array $segments, array $query): bool
	{
		if ($query !== [] && array_intersect(array_map('strval', array_keys($query)), self::SEARCH_PARAMETERS) !== []) {
			return false;
		}

		if ($segments === []) {
			return true;
		}

		if (count($segments) === 1 && preg_match('/^(index\.(html?|php)|[a-z]{2}(-[a-z]{2})?)$/', $segments[0]) === 1) {
			return true;
		}

		return count($segments) === 2 && preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $segments[0]) === 1 && preg_match('/^index\.(html?|php)$/', $segments[1]) === 1;
	}

	/**
	 * @param list<string> $families
	 */
	private static function inFamily(string $host, array $families): bool
	{
		foreach ($families as $family) {
			if (DomainFamily::matches($host, $family)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array{shape: ResultShape, confidence: string, reason: string}
	 */
	private static function result(ResultShape $shape, string $confidence, string $reason): array
	{
		return ['shape' => $shape, 'confidence' => $confidence, 'reason' => $reason];
	}
}
