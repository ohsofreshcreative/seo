<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Ai;

use OsfSeo\Ai\Contract\AnalysisContract;
use OsfSeo\Ai\Contract\OutputValidator;
use OsfSeo\Ai\Provider\FakeProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Walidacja odpowiedzi modelu po stronie PHP (7, 8, 19): poprawna odpowiedź atrapy przechodzi, każde naruszenie kontraktu
 * (struktura, typy, wyliczenia, limity, odwołania, podstawa twierdzeń, prognozy, obietnice) odrzuca odpowiedź w całości.
 */
final class OutputValidatorTest extends TestCase
{
	private const REFS = ['decision', 'gsc', 'kw:A', 'kw:B', 'serp', 'serp:7', 'target', 'topic'];

	public function test_7_valid_response_is_normalized(): void
	{
		$sample = FakeProvider::sample(['refs' => self::REFS, 'data_gaps' => ['page_content_not_fetched'], 'action' => 'optimize']);
		$sample['summary'] = "  Podsumowanie\u{200B} tematu  ";
		$result = (new OutputValidator())->validate((string) json_encode($sample), self::REFS);

		self::assertTrue($result->valid(), (string) json_encode($result->errors));
		self::assertSame([], $result->errors);
		self::assertSame('Podsumowanie tematu', $result->result['summary']);
		self::assertSame(AnalysisContract::VERSION, $result->result['contract_version']);
		self::assertSame(['decision', 'topic'], $result->result['findings'][0]['evidence_refs']);
		self::assertSame(['F1'], $result->result['recommendations'][0]['finding_ids']);
	}

	/**
	 * @return iterable<string, array{0: \Closure(array<string, mixed>): mixed, 1: string, 2: string}>
	 */
	public static function violations(): iterable
	{
		yield 'unknown ref' => [static function (array $d): array { $d['findings'][0]['evidence_refs'] = ['kw:INNY-PROJEKT']; return $d; }, '$.findings[0].evidence_refs[0]', 'unknown_ref'];
		yield 'evidence without refs' => [static function (array $d): array { $d['findings'][0]['evidence_refs'] = []; return $d; }, '$.findings[0].evidence_refs', 'evidence_without_refs'];
		yield 'extra field' => [static function (array $d): array { $d['findings'][0]['traffic_forecast'] = '+500'; return $d; }, '$.findings[0].traffic_forecast', 'unexpected_field'];
		yield 'missing field' => [static function (array $d): array { unset($d['caveats']); return $d; }, '$.caveats', 'missing_field'];
		yield 'wrong enum' => [static function (array $d): array { $d['recommendations'][0]['expected_impact'] = 'huge'; return $d; }, '$.recommendations[0].expected_impact', 'invalid_enum'];
		yield 'priority out of range' => [static function (array $d): array { $d['recommendations'][0]['priority'] = 9; return $d; }, '$.recommendations[0].priority', 'invalid_enum'];
		yield 'wrong type' => [static function (array $d): array { $d['summary'] = ['x']; return $d; }, '$.summary', 'wrong_type'];
		yield 'empty text' => [static function (array $d): array { $d['summary'] = " \u{200B} "; return $d; }, '$.summary', 'empty'];
		yield 'too long' => [static function (array $d): array { $d['summary'] = str_repeat('a', 1201); return $d; }, '$.summary', 'too_long'];
		yield 'too many items' => [static function (array $d): array { $d['caveats'] = array_fill(0, 9, 'x'); return $d; }, '$.caveats', 'too_many_items'];
		yield 'unknown finding' => [static function (array $d): array { $d['recommendations'][0]['finding_ids'] = ['F9']; return $d; }, '$.recommendations[0].finding_ids[0]', 'unknown_finding'];
		yield 'duplicate id' => [static function (array $d): array { $d['findings'][1] = $d['findings'][0]; return $d; }, '$.findings[1].id', 'duplicate_id'];
		yield 'invalid id' => [static function (array $d): array { $d['findings'][0]['id'] = '<script>'; return $d; }, '$.findings[0].id', 'invalid_id'];
		yield 'numeric forecast' => [static function (array $d): array { $d['recommendations'][0]['impact_rationale'] = 'Wzrost ruchu o 30% w 3 miesiące.'; return $d; }, '$.recommendations[0].impact_rationale', 'numeric_forecast'];
		yield 'multiplier forecast' => [static function (array $d): array { $d['recommendations'][0]['impact_rationale'] = 'Ruch wzrośnie 2x.'; return $d; }, '$.recommendations[0].impact_rationale', 'numeric_forecast'];
		yield 'promise' => [static function (array $d): array { $d['recommendations'][0]['action'] = 'Wdrożenie gwarantuje TOP3.'; return $d; }, '$.recommendations[0].action', 'promise'];
		yield 'contract version' => [static function (array $d): array { $d['contract_version'] = 2; return $d; }, '$.contract_version', 'contract_version'];
		yield 'boolean as string' => [static function (array $d): array { $d['recommendations'][0]['requires_manual_check'] = 'yes'; return $d; }, '$.recommendations[0].requires_manual_check', 'wrong_type'];
	}

	/**
	 * @param \Closure(array<string, mixed>): mixed $mutate
	 */
	#[DataProvider('violations')]
	public function test_8_19_contract_violation_rejects_whole_response(\Closure $mutate, string $path, string $code): void
	{
		$data = $mutate(FakeProvider::sample(['refs' => self::REFS, 'action' => 'optimize']));
		$result = (new OutputValidator())->validate((string) json_encode($data), self::REFS);

		self::assertFalse($result->valid());
		self::assertNull($result->result, 'Odpowiedź z błędem nie daje wyniku.');
		self::assertContains(['path' => $path, 'code' => $code], $result->errors);
	}

	public function test_8_malformed_output_is_a_controlled_error(): void
	{
		$validator = new OutputValidator();

		self::assertSame([['path' => '$', 'code' => 'invalid_json']], $validator->validate('{"summary": "urwane', self::REFS)->errors);
		self::assertSame([['path' => '$', 'code' => 'invalid_json']], $validator->validate('Oto analiza: ...', self::REFS)->errors);
		self::assertSame([['path' => '$', 'code' => 'not_object']], $validator->validate('[1,2]', self::REFS)->errors);
		self::assertSame([['path' => '$', 'code' => 'too_large']], $validator->validate(str_repeat(' ', OutputValidator::MAX_BYTES + 1), self::REFS)->errors);

		// Nieoczekiwane pole o dowolnej nazwie — ścieżka bez treści z odpowiedzi.
		$data = FakeProvider::sample(['refs' => self::REFS]);
		$data['<img src=x onerror=alert(1)>'] = 1;
		self::assertContains(['path' => '$.?', 'code' => 'unexpected_field'], $validator->validate((string) json_encode($data), self::REFS)->errors);
	}

	public function test_hypothesis_may_have_no_refs_and_schema_is_strict(): void
	{
		$data = FakeProvider::sample(['refs' => self::REFS]);
		$data['findings'][0]['basis'] = 'hypothesis';
		$data['findings'][0]['evidence_refs'] = [];
		self::assertTrue((new OutputValidator())->validate((string) json_encode($data), self::REFS)->valid());

		$schema = AnalysisContract::schema();
		self::assertFalse($schema['additionalProperties']);
		self::assertSame(array_keys($schema['properties']), $schema['required']);
		self::assertFalse($schema['properties']['recommendations']['items']['additionalProperties']);
		self::assertSame(['high', 'medium', 'low', 'unknown'], $schema['properties']['recommendations']['items']['properties']['expected_impact']['enum']);
		self::assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,64}$/', AnalysisContract::NAME);
	}
}
