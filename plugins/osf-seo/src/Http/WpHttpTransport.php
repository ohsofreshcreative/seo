<?php

declare(strict_types=1);

namespace OsfSeo\Http;

use OsfSeo\Plugin;

final class WpHttpTransport implements HttpTransport
{
	public function __construct(private readonly int $timeout = 15)
	{
	}

	public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
	{
		$response = wp_remote_request($url, [
			'method' => $method,
			'headers' => $headers,
			'body' => $body,
			'timeout' => $this->timeout,
			'redirection' => 0,
			'sslverify' => true,
			'user-agent' => 'OSF-SEO/' . Plugin::VERSION . '; ' . home_url('/'),
		]);

		if (is_wp_error($response)) {
			throw new TransportException(sprintf(
				'HTTP request to %s failed (%s).',
				(string) wp_parse_url($url, PHP_URL_HOST),
				$response->get_error_code(),
			));
		}

		$headers = [];

		foreach (wp_remote_retrieve_headers($response) as $name => $value) {
			$headers[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
		}

		return new HttpResponse(
			(int) wp_remote_retrieve_response_code($response),
			(string) wp_remote_retrieve_body($response),
			$headers,
		);
	}
}
