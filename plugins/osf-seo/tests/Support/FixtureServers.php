<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Support;

use RuntimeException;

/**
 * Kontrolowane serwery testowe dla transportu Page Intelligence (bez internetu): wbudowany serwer PHP na wskazanym adresie pętli zwrotnej
 * (127.0.0.1 — „publiczny” w polityce testowej, 127.0.0.2 — „wewnętrzny”, zawsze blokowany) oraz serwer HTTPS z certyfikatem testowego CA
 * wystawionym dla nazwy `secure.fixture.example`. Procesy są zamykane w `stopAll()`.
 */
final class FixtureServers
{
	/** @var list<resource> */
	private static array $processes = [];

	private static ?string $tlsDir = null;

	/**
	 * Serwer HTTP (router `fixture-server/router.php`) — zwraca port.
	 *
	 * @param array<string, string> $env
	 * @param int|null $port ten sam port na innym adresie (np. 127.0.0.2 obok 127.0.0.1 — test DNS rebinding)
	 */
	public static function http(string $address, string $name, array $env = [], ?int $port = null): int
	{
		$port ??= self::freePort($address);
		self::start([PHP_BINARY, '-S', $address . ':' . $port, __DIR__ . '/fixture-server/router.php'], ['FIXTURE_NAME' => $name] + $env);
		self::waitFor($address, $port);

		return $port;
	}

	/**
	 * Serwer HTTPS z certyfikatem `secure.fixture.example` podpisanym testowym CA — zwraca [port, ścieżka certyfikatu CA].
	 *
	 * @return array{0: int, 1: string}
	 */
	public static function tls(int $httpPort = 0): array
	{
		$dir = self::certificates();
		$port = self::freePort('127.0.0.1');
		self::start([PHP_BINARY, __DIR__ . '/fixture-server/tls-server.php', (string) $port, $dir . '/server.pem'], ['FIXTURE_HTTP_PORT' => (string) $httpPort]);
		self::waitFor('127.0.0.1', $port);

		return [$port, $dir . '/ca.pem'];
	}

	public static function stopAll(): void
	{
		foreach (self::$processes as $process) {
			proc_terminate($process);
			proc_close($process);
		}

		self::$processes = [];
	}

	/** Czy środowisko pozwala uruchomić serwery (proc_open, ext-openssl dla TLS). */
	public static function supported(): bool
	{
		return function_exists('proc_open') && PHP_BINARY !== '';
	}

	private static function certificates(): string
	{
		if (self::$tlsDir !== null) {
			return self::$tlsDir;
		}

		$dir = sys_get_temp_dir() . '/osf-seo-tls-' . bin2hex(random_bytes(4));
		mkdir($dir, 0700, true);
		$config = $dir . '/openssl.cnf';
		file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n"
			. "[v3_srv]\nbasicConstraints=CA:FALSE\nsubjectAltName=DNS:secure.fixture.example\nextendedKeyUsage=serverAuth\n");
		$options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
		$caKey = openssl_pkey_new($options) ?: throw new RuntimeException('CA key');
		$ca = openssl_csr_sign(openssl_csr_new(['commonName' => 'OSF SEO Test CA'], $caKey, $options) ?: throw new RuntimeException('CA csr'), null, $caKey, 1, $options + ['x509_extensions' => 'v3_ca'], 1)
			?: throw new RuntimeException('CA cert');
		$key = openssl_pkey_new($options) ?: throw new RuntimeException('server key');
		$cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'secure.fixture.example'], $key, $options) ?: throw new RuntimeException('csr'), $ca, $caKey, 1, $options + ['x509_extensions' => 'v3_srv'], 2)
			?: throw new RuntimeException('server cert');
		openssl_x509_export($ca, $caPem);
		openssl_x509_export($cert, $certPem);
		openssl_pkey_export($key, $keyPem, null, $options);
		file_put_contents($dir . '/ca.pem', $caPem);
		file_put_contents($dir . '/server.pem', $certPem . $keyPem);

		return self::$tlsDir = $dir;
	}

	/**
	 * @param list<string> $command
	 * @param array<string, string> $env
	 */
	private static function start(array $command, array $env): void
	{
		$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env + ['PATH' => (string) getenv('PATH')]);

		if (! is_resource($process)) {
			throw new RuntimeException('Could not start fixture server.');
		}

		self::$processes[] = $process;
	}

	private static function freePort(string $address): int
	{
		$socket = stream_socket_server('tcp://' . $address . ':0', $errno, $error);

		if ($socket === false) {
			throw new RuntimeException('No free port on ' . $address . ': ' . $error);
		}

		$name = (string) stream_socket_get_name($socket, false);
		fclose($socket);

		return (int) substr($name, (int) strrpos($name, ':') + 1);
	}

	private static function waitFor(string $address, int $port): void
	{
		for ($i = 0; $i < 100; $i++) {
			$connection = @fsockopen($address, $port, $errno, $error, 0.1);

			if ($connection !== false) {
				fclose($connection);

				return;
			}

			usleep(50000);
		}

		throw new RuntimeException('Fixture server did not start on ' . $address . ':' . $port);
	}
}
