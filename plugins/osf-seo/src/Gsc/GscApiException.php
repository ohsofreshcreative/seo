<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

use OsfSeo\Http\HttpResponse;
use RuntimeException;
use Throwable;

/**
 * Błąd Search Console API. Przechowuje wyłącznie kategorię, status HTTP, kod powodu Google
 * (np. `forbidden`, `RESOURCE_EXHAUSTED`) i skrócony komunikat — nigdy nagłówków ani treści żądania.
 */
final class GscApiException extends RuntimeException
{
	private const RATE_LIMIT_REASONS = ['ratelimitexceeded', 'userratelimitexceeded', 'quotaexceeded', 'dailylimitexceeded', 'resource_exhausted'];

	public function __construct(
		private readonly ErrorCategory $category,
		private readonly ?int $status = null,
		private readonly string $reason = '',
		string $detail = '',
		private readonly ?int $retryAfter = null,
		?Throwable $previous = null,
	) {
		parent::__construct(self::describe($category, $status, $reason, $detail), 0, $previous);
	}

	public static function fromResponse(HttpResponse $response): self
	{
		$json = $response->json();
		$error = is_array($json['error'] ?? null) ? $json['error'] : [];
		$first = is_array($error['errors'][0] ?? null) ? $error['errors'][0] : [];

		$reason = self::token($first['reason'] ?? null) ?? self::token($error['status'] ?? null) ?? '';
		$status = self::token($error['status'] ?? null) ?? '';
		$detail = is_string($error['message'] ?? null) ? $error['message'] : '';

		$rateLimited = in_array(strtolower($reason), self::RATE_LIMIT_REASONS, true)
			|| in_array(strtolower($status), self::RATE_LIMIT_REASONS, true);

		$category = match (true) {
			$response->status === 429, $response->status === 403 && $rateLimited => ErrorCategory::RateLimited,
			in_array($response->status, [500, 502, 503, 504], true) => ErrorCategory::Transient,
			$response->status === 401 => ErrorCategory::Unauthorized,
			$response->status === 403 => ErrorCategory::PermissionDenied,
			$response->status === 404 => ErrorCategory::NotFound,
			$response->status === 400 => ErrorCategory::BadRequest,
			default => ErrorCategory::Http,
		};

		return new self($category, $response->status, $reason, $detail, self::parseRetryAfter($response));
	}

	public static function malformed(string $detail): self
	{
		return new self(ErrorCategory::Malformed, null, '', $detail);
	}

	public static function pagination(string $detail): self
	{
		return new self(ErrorCategory::Pagination, null, '', $detail);
	}

	public function category(): ErrorCategory
	{
		return $this->category;
	}

	public function status(): ?int
	{
		return $this->status;
	}

	public function reason(): string
	{
		return $this->reason;
	}

	/** Sekundy z nagłówka Retry-After (tylko postać liczbowa). */
	public function retryAfter(): ?int
	{
		return $this->retryAfter;
	}

	public function isRetryable(): bool
	{
		return $this->category->isRetryable();
	}

	public function userMessage(): string
	{
		return $this->category->userMessage();
	}

	private static function describe(ErrorCategory $category, ?int $status, string $reason, string $detail): string
	{
		$message = 'Search Console API error: ' . $category->value;

		if ($status !== null) {
			$message .= ' (HTTP ' . $status . ($reason !== '' ? ', ' . $reason : '') . ')';
		}

		$detail = trim((string) preg_replace('/\s+/', ' ', $detail));

		if ($detail !== '') {
			$message .= ': ' . mb_substr($detail, 0, 200);
		}

		return $message;
	}

	private static function token(mixed $value): ?string
	{
		return is_string($value) && preg_match('/^[A-Za-z_]{1,64}$/', $value) === 1 ? $value : null;
	}

	private static function parseRetryAfter(HttpResponse $response): ?int
	{
		$value = trim($response->headers['retry-after'] ?? '');

		return ctype_digit($value) ? (int) $value : null;
	}
}
