<?php

// Router wbudowanego serwera PHP dla testów transportu Page Intelligence (wyłącznie testy — nie jest częścią pluginu).
// Nazwa serwera (FIXTURE_NAME) pozwala sprawdzić, który serwer faktycznie odpowiedział (test przypięcia IP i DNS rebinding).

$name = (string) getenv('FIXTURE_NAME');
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY), $query);

$html = static function (string $body, int $status = 200, array $headers = []): void {
	http_response_code($status);
	header('Content-Type: text/html; charset=utf-8');

	foreach ($headers as $header) {
		header($header);
	}

	echo $body;
};

switch (true) {
	case $path === '/identity':
		$html('<!doctype html><html lang="pl"><head><title>' . $name . '</title></head><body><main><h1>Serwer ' . $name . '</h1><p>Odpowiedź serwera ' . $name . '.</p></main></body></html>');
		break;
	case $path === '/headers':
		$html('<html><head><title>headers</title></head><body><pre>' . htmlspecialchars(json_encode([
			'host' => $_SERVER['HTTP_HOST'] ?? null,
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
			'if_none_match' => $_SERVER['HTTP_IF_NONE_MATCH'] ?? null,
			'cookie' => $_SERVER['HTTP_COOKIE'] ?? null,
			'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
		])) . '</pre></body></html>', 200, ['Set-Cookie: session=secret-cookie']);
		break;
	case $path === '/redirect':
		http_response_code((int) ($query['code'] ?? 302));
		header('Location: ' . (string) ($query['to'] ?? '/identity'));
		break;
	case preg_match('#^/chain/(\d+)$#', $path, $match) === 1:
		http_response_code(302);
		header('Location: /chain/' . ((int) $match[1] + 1));
		break;
	case preg_match('#^/status/(\d{3})$#', $path, $match) === 1:
		http_response_code((int) $match[1]);
		header('Content-Type: text/html');

		if ((int) $match[1] === 429) {
			header('Retry-After: 120');
		}

		echo '<html><body>status ' . $match[1] . '</body></html>';
		break;
	case $path === '/slow':
		sleep((int) ($query['seconds'] ?? 3));
		$html('<html><body>slow</body></html>');
		break;
	case $path === '/big':
		header('Content-Type: text/html');
		$chunk = str_repeat('<p>' . str_repeat('x', 1020) . '</p>', 64);

		for ($i = 0; $i < (int) ($query['kb'] ?? 3072) / 64; $i++) {
			echo $chunk;
			flush();
		}

		break;
	case $path === '/gzip-bomb':
		header('Content-Type: text/html');
		header('Content-Encoding: gzip');
		echo gzencode('<html><body>' . str_repeat('A', 8 * 1024 * 1024) . '</body></html>', 9);
		break;
	case $path === '/pdf':
		header('Content-Type: application/pdf');
		echo '%PDF-1.4';
		break;
	case $path === '/no-type':
		header('Content-Type:');
		echo '<html></html>';
		break;
	case $path === '/conditional':
		$etag = '"v1"';

		if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? null) === $etag) {
			http_response_code(304);
			header('ETag: ' . $etag);
			break;
		}

		$html('<html><head><title>Conditional</title></head><body><h1>Treść</h1></body></html>', 200, ['ETag: ' . $etag, 'Last-Modified: Wed, 14 Jan 2026 10:00:00 GMT']);
		break;
	case $path === '/robots.txt':
		$robots = getenv('FIXTURE_ROBOTS');

		if ($robots === false || $robots === '') {
			http_response_code(404);
			break;
		}

		header('Content-Type: text/plain');
		echo str_replace('\n', "\n", $robots);
		break;
	case $path === '/content':
		// Treść z pliku wskazanego przez test (symulacja zmiany HTML między pobraniami).
		$file = (string) getenv('FIXTURE_CONTENT');
		$html($file !== '' && is_file($file) ? (string) file_get_contents($file) : '<html><body>brak treści</body></html>');
		break;
	case $path === '/latin2':
		http_response_code(200);
		header('Content-Type: text/html; charset=iso-8859-2');
		echo iconv('UTF-8', 'ISO-8859-2', '<html><head><title>Zażółć gęślą jaźń</title></head><body><h1>Łódź</h1></body></html>');
		break;
	default:
		$html('<html><head><title>Fixture ' . $name . '</title></head><body><h1>' . $name . '</h1></body></html>');
}
