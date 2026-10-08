<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Provider;

use OsfSeo\Ai\AiConfig;
use OsfSeo\Http\HttpResponse;
use OsfSeo\Http\HttpTransport;
use OsfSeo\Http\TransportException;

/**
 * Adapter OpenAI — Responses API (`POST https://api.openai.com/v1/responses`, oficjalna specyfikacja OpenAPI openai/openai-openapi):
 * `instructions` (instrukcje aplikacji) + `input` (tekst), odpowiedź strukturalna `text.format` = `json_schema` ze `strict`,
 * `max_output_tokens` (obejmuje także tokeny rozumowania), `store: false` (odpowiedź nie jest przechowywana u dostawcy do późniejszego
 * pobrania). Model i ceny wyłącznie z konfiguracji (D88) — adapter nie zna żadnego modelu ani cennika.
 *
 * Klucz `OSF_SEO_OPENAI_API_KEY` czytany w chwili budowania nagłówka; brak klucza → wyjątek `config` bez żadnego żądania.
 * Bez ponowień (płatne żądanie mogło zostać wykonane). Wyjątki i logi: rodzaj, status HTTP i kod błędu — bez treści i nagłówków.
 */
final class OpenAiProvider implements AiProvider
{
	public const ID = 'openai';

	public const ENDPOINT = 'https://api.openai.com/v1/responses';

	public function __construct(
		private readonly AiConfig $config,
		private readonly HttpTransport $http,
	) {
	}

	public function id(): string
	{
		return self::ID;
	}

	public function isPaid(): bool
	{
		return true;
	}

	public function model(): ?string
	{
		return $this->config->model();
	}

	public function problems(): array
	{
		$problems = [];

		if (! $this->config->hasApiKey(AiConfig::OPENAI_API_KEY)) {
			$problems[] = 'missing_api_key';
		}

		if ($this->config->model() === null) {
			$problems[] = 'model_not_configured';
		}

		return $problems;
	}

	public function generate(AiRequest $request): AiResponse
	{
		if (! $this->config->hasApiKey(AiConfig::OPENAI_API_KEY)) {
			throw new AiProviderException(AiProviderException::CONFIG, null, 'missing_api_key');
		}

		if (! AiConfig::validModel($request->model)) {
			throw new AiProviderException(AiProviderException::CONFIG, null, 'invalid_model');
		}

		try {
			$response = $this->http->request('POST', self::ENDPOINT, [
				'Authorization' => 'Bearer ' . $this->config->apiKey(AiConfig::OPENAI_API_KEY),
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			], self::body($request));
		} catch (TransportException) {
			throw new AiProviderException(AiProviderException::TRANSPORT);
		}

		return self::parse($response);
	}

	/**
	 * Treść żądania (bez `hints` — te są wyłącznie dla dostawcy testowego).
	 */
	public static function body(AiRequest $request): string
	{
		$body = [
			'model' => $request->model,
			'instructions' => $request->instructions,
			'input' => $request->input,
			'text' => [
				'format' => [
					'type' => 'json_schema',
					'name' => $request->schemaName,
					'schema' => $request->schema,
					'strict' => true,
				],
			],
			'max_output_tokens' => $request->maxOutputTokens,
			'store' => false,
		];

		if ($request->temperature !== null) {
			$body['temperature'] = $request->temperature;
		}

		return (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
	}

	/**
	 * Mapowanie odpowiedzi HTTP na wynik albo wyjątek z rodzajem (rozliczenie zależy od rodzaju i zgłoszonego zużycia).
	 *
	 * @throws AiProviderException
	 */
	public static function parse(HttpResponse $response): AiResponse
	{
		$json = $response->json();

		if (! $response->isSuccess()) {
			$code = AiProviderException::code($json['error']['code'] ?? null) ?? AiProviderException::code($json['error']['type'] ?? null);
			$kind = match (true) {
				in_array($response->status, [401, 403], true) => AiProviderException::AUTH,
				$response->status === 429 => AiProviderException::RATE_LIMITED,
				$response->status === 408 || $response->status >= 500 => AiProviderException::SERVER,
				$response->status >= 400 => AiProviderException::REJECTED,
				default => AiProviderException::INVALID_RESPONSE,
			};

			throw new AiProviderException($kind, $response->status, $code);
		}

		if ($json === null) {
			throw new AiProviderException(AiProviderException::INVALID_RESPONSE, $response->status);
		}

		$usage = AiUsage::fromArray($json['usage'] ?? null);
		$id = is_string($json['id'] ?? null) && preg_match('/^[A-Za-z0-9_\-]{1,100}$/', $json['id']) === 1 ? $json['id'] : null;
		$status = $json['status'] ?? null;

		if ($status === 'failed') {
			throw new AiProviderException(AiProviderException::FAILED, $response->status, AiProviderException::code($json['error']['code'] ?? null), $usage, $id);
		}

		if ($status === 'incomplete') {
			throw new AiProviderException(AiProviderException::INCOMPLETE, $response->status, AiProviderException::code($json['incomplete_details']['reason'] ?? null), $usage, $id);
		}

		if ($status !== 'completed') {
			throw new AiProviderException(AiProviderException::INVALID_RESPONSE, $response->status, AiProviderException::code($status), $usage, $id);
		}

		$text = '';
		$refused = false;

		foreach ((array) ($json['output'] ?? []) as $item) {
			if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
				continue;
			}

			foreach ((array) ($item['content'] ?? []) as $part) {
				if (! is_array($part)) {
					continue;
				}

				if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
					$text .= $part['text'];
				} elseif (($part['type'] ?? null) === 'refusal') {
					$refused = true;
				}
			}
		}

		if ($refused) {
			throw new AiProviderException(AiProviderException::REFUSED, $response->status, null, $usage, $id);
		}

		if (trim($text) === '') {
			throw new AiProviderException(AiProviderException::INVALID_RESPONSE, $response->status, 'empty_output', $usage, $id);
		}

		$model = is_string($json['model'] ?? null) && AiConfig::validModel($json['model']) ? $json['model'] : null;

		return new AiResponse($text, $usage, $id, $model);
	}
}
