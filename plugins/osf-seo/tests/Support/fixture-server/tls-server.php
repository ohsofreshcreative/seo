<?php

// Minimalny serwer HTTPS do testów weryfikacji TLS (wyłącznie testy): certyfikat podpisany testowym CA, jedna odpowiedź na połączenie.
// Argumenty: port, ścieżka PEM (certyfikat + klucz).

[$script, $port, $pem] = $argv;
$context = stream_context_create(['ssl' => ['local_cert' => $pem, 'verify_peer' => false]]);
$server = stream_socket_server('tls://127.0.0.1:' . $port, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

if ($server === false) {
	fwrite(STDERR, "bind failed: {$error}\n");
	exit(1);
}

$until = time() + 120;

while (time() < $until) {
	$client = @stream_socket_accept($server, 1);

	if ($client === false) {
		continue;
	}

	$request = (string) @fread($client, 8192);

	if (str_starts_with($request, 'GET /to-http')) {
		@fwrite($client, "HTTP/1.1 302 Found\r\nLocation: http://www.fixture.example:" . (string) getenv('FIXTURE_HTTP_PORT') . "/identity\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
	} elseif ($request !== '') {
		$body = '<!doctype html><html><head><title>Secure fixture</title></head><body><main><h1>TLS OK</h1></main></body></html>';
		@fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
	}

	@fclose($client);
}
