<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Branding;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Roles;
use OsfSeo\Branding\BrandingService;
use OsfSeo\Support\Logger;
use OsfSeo\Support\ValidationException;
use OsfSeo\Tests\Integration\IntegrationTestCase;

/**
 * Logo aplikacji: biblioteka mediów WordPressa (identyfikator załącznika w opcji), wyłącznie PNG / JPG / WebP, zmiana tylko
 * z `osf_seo_manage_settings`, brak albo usunięty plik → napis „Whack-a-mole”.
 */
final class BrandingServiceTest extends IntegrationTestCase
{
	private BrandingService $branding;

	/** @var list<int> */
	private array $attachments = [];

	/** @var list<string> */
	private array $files = [];

	protected function setUp(): void
	{
		parent::setUp();
		delete_option(BrandingService::OPTION);
		$this->branding = new BrandingService($this->captureLogger());
	}

	protected function tearDown(): void
	{
		delete_option(BrandingService::OPTION);

		foreach ($this->attachments as $id) {
			wp_delete_attachment($id, true);
		}

		foreach ($this->files as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}

		parent::tearDown();
	}

	public function test_upload_select_change_and_remove(): void
	{
		$admin = $this->createUser(Roles::ADMIN);
		self::assertNull($this->branding->logo(), 'Brak logo — napis „Whack-a-mole”.');
		self::assertSame('Whack-a-mole', BrandingService::APP_NAME);

		// Upload PNG → biblioteka mediów → logo.
		$png = $this->track($this->branding->upload($this->image('png', 'logo-agencji.png', 600, 150), $admin)->id);
		self::assertSame($png, (int) get_option(BrandingService::OPTION), 'W opcji wyłącznie identyfikator załącznika.');
		$logo = $this->branding->logo();
		self::assertNotNull($logo);
		self::assertSame('attachment', get_post_type($png));
		self::assertSame('image/png', get_post_mime_type($png));
		self::assertStringEndsWith('.png', $logo->url);
		self::assertSame('Whack-a-mole', $logo->alt, 'Domyślny tekst alternatywny.');
		self::assertSame([300, 75], [$logo->width, $logo->height], 'Rozmiar medium z zachowaniem proporcji.');

		// Zmiana: nowy obraz WebP, potem JPG.
		$webp = $this->track($this->branding->upload($this->image('webp', 'logo.webp', 400, 400), $admin)->id);
		self::assertSame($webp, $this->branding->logo()?->id);
		$jpg = $this->track($this->branding->upload($this->image('jpg', 'logo.jpg', 320, 80), $admin)->id);
		self::assertSame('image/jpeg', get_post_mime_type($jpg));

		// Wybór z biblioteki mediów (wcześniejszy PNG) i własny tekst alternatywny z biblioteki.
		update_post_meta($png, '_wp_attachment_image_alt', 'Logo OhSoFresh');
		$library = array_map(static fn ($image): int => $image->id, $this->branding->libraryImages());
		self::assertSame([$jpg, $webp, $png], array_values(array_intersect($library, [$png, $webp, $jpg])));
		self::assertSame($png, $this->branding->select($png, $admin)->id);
		self::assertSame('Logo OhSoFresh', $this->branding->logo()?->alt);

		// Usunięcie: napis wraca, obraz zostaje w bibliotece mediów.
		$this->branding->remove($admin);
		self::assertNull($this->branding->logo());
		self::assertFalse(get_option(BrandingService::OPTION));
		self::assertSame('attachment', get_post_type($png));
	}

	public function test_invalid_files_are_rejected(): void
	{
		$admin = $this->createUser(Roles::ADMIN);
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>';
		$cases = [
			'SVG' => $this->file('logo.svg', $svg),
			'SVG udający PNG' => $this->file('logo.png', $svg),
			'tekst udający PNG' => $this->file('logo.png', 'to nie jest obraz'),
			'PHP udający JPG' => $this->file('logo.jpg', '<?php echo 1;'),
			'GIF' => $this->image('gif', 'logo.gif', 20, 20),
			'za duże wymiary' => $this->image('png', 'szeroki.png', BrandingService::MAX_DIMENSION + 1, 10),
			'za duży plik' => $this->file('ciezki.png', str_repeat('x', BrandingService::MAX_BYTES + 1)),
			'brak pliku' => ['name' => 'logo.png', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0],
			'limit serwera' => ['name' => 'logo.png', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0],
		];
		$before = $this->attachmentCount();

		foreach ($cases as $label => $file) {
			try {
				$this->branding->upload($file, $admin);
				self::fail('Odrzucony plik: ' . $label);
			} catch (ValidationException $exception) {
				self::assertArrayHasKey('logo', $exception->errors(), $label);
			}
		}

		self::assertSame($before, $this->attachmentCount(), 'Nic nie trafiło do biblioteki mediów.');
		self::assertNull($this->branding->logo());

		// Wybór załącznika, który nie jest dozwolonym obrazem (SVG, dokument) albo nie istnieje.
		$document = $this->track(wp_insert_attachment(['post_title' => 'dokument', 'post_mime_type' => 'image/svg+xml', 'post_status' => 'inherit'], 'logo.svg'));
		$post = $this->track(wp_insert_post(['post_title' => 'wpis', 'post_status' => 'publish']));

		foreach ([$document, $post, 0, 999999] as $id) {
			try {
				$this->branding->select($id, $admin);
				self::fail('Odrzucony wybór: ' . $id);
			} catch (ValidationException) {
			}
		}

		self::assertFalse(get_option(BrandingService::OPTION));
	}

	public function test_client_and_users_without_capability_cannot_change_the_logo(): void
	{
		$admin = $this->createUser(Roles::ADMIN);
		$png = $this->track($this->branding->upload($this->image('png', 'logo.png', 200, 50), $admin)->id);
		$client = $this->createUser(Roles::CLIENT);
		$subscriber = $this->createUser('subscriber');

		foreach (['klient' => $client, 'subskrybent' => $subscriber, 'gość' => 0] as $label => $userId) {
			foreach ([
				'upload' => fn () => $this->branding->upload($this->image('png', 'inne.png', 100, 30), $userId),
				'wybór' => fn () => $this->branding->select($png, $userId),
				'usunięcie' => fn () => $this->branding->remove($userId),
			] as $operation => $call) {
				try {
					$call();
					self::fail($label . ' nie może: ' . $operation);
				} catch (AccessDenied) {
				}
			}
		}

		// Klient widzi ustawione logo (odczyt bez uprawnień), ale go nie zmienia.
		self::assertSame($png, $this->branding->logo()?->id);
	}

	public function test_deleted_file_falls_back_to_the_app_name(): void
	{
		$admin = $this->createUser(Roles::ADMIN);
		$logo = $this->branding->upload($this->image('png', 'logo.png', 200, 50), $admin);
		$file = (string) get_attached_file($logo->id);
		unlink($file);
		$this->track($logo->id);
		self::assertNull($this->branding->logo(), 'Plik usunięty z dysku — napis.');

		$second = $this->branding->upload($this->image('png', 'drugie.png', 200, 50), $admin);
		wp_delete_attachment($second->id, true);
		self::assertNull($this->branding->logo(), 'Załącznik usunięty z biblioteki — napis.');
		self::assertNotFalse(get_option(BrandingService::OPTION), 'Opcja zostaje — logo wróci po ponownym wyborze.');
	}

	/**
	 * Syntetyczny obraz w pliku tymczasowym (GD).
	 *
	 * @return array{name: string, tmp_name: string, error: int, size: int}
	 */
	private function image(string $format, string $name, int $width, int $height): array
	{
		$image = imagecreatetruecolor($width, $height);
		imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 120, 200));
		$path = (string) tempnam(sys_get_temp_dir(), 'osf-logo-');
		match ($format) {
			'png' => imagepng($image, $path),
			'webp' => imagewebp($image, $path),
			'jpg' => imagejpeg($image, $path),
			'gif' => imagegif($image, $path),
		};
		$this->files[] = $path;

		return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($path)];
	}

	/**
	 * @return array{name: string, tmp_name: string, error: int, size: int}
	 */
	private function file(string $name, string $contents): array
	{
		$path = (string) tempnam(sys_get_temp_dir(), 'osf-logo-');
		file_put_contents($path, $contents);
		$this->files[] = $path;

		return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($contents)];
	}

	private function track(int $id): int
	{
		$this->attachments[] = $id;

		return $id;
	}

	private function attachmentCount(): int
	{
		return (int) (wp_count_posts('attachment')->inherit ?? 0);
	}
}
