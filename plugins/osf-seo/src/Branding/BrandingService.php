<?php

declare(strict_types=1);

namespace OsfSeo\Branding;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Support\Logger;
use OsfSeo\Support\ValidationException;
use WP_Post;

/**
 * Wygląd aplikacji (nazwa w UI i logotyp w panelu — docs/ARCHITECTURE.md, sekcja 4.5): logo to załącznik biblioteki mediów WordPressa,
 * w opcji zapisany wyłącznie jego identyfikator (nigdy wpisany ręcznie adres). Dozwolone wyłącznie PNG, JPG i WebP — bez SVG (projekt nie ma
 * sanityzacji SVG). Zmiana tylko z `osf_seo_manage_settings`; brak logo albo usunięty plik → napis „Whack-a-mole”.
 */
final class BrandingService
{
	/** Nazwa aplikacji w interfejsie (identyfikatory techniczne `osf-seo` / `osf_seo_*` bez zmian). */
	public const APP_NAME = 'Whack-a-mole';

	public const OPTION = 'osf_seo_branding_logo';

	public const MAX_BYTES = 2 * 1024 * 1024;

	public const MAX_DIMENSION = 4000;

	/** Rozszerzenia → typ MIME (format `upload_mimes`); typ sprawdzany także z zawartości pliku. */
	public const ALLOWED_MIMES = [
		'png' => 'image/png',
		'jpg|jpeg|jpe' => 'image/jpeg',
		'webp' => 'image/webp',
	];

	public function __construct(private readonly Logger $logger)
	{
	}

	/**
	 * Ustawione logo albo null (brak, plik usunięty z biblioteki albo niedozwolony typ) — wtedy panel pokazuje nazwę aplikacji.
	 */
	public function logo(): ?BrandLogo
	{
		$id = (int) get_option(self::OPTION, 0);

		return $id > 0 ? $this->image($id) : null;
	}

	/**
	 * Obrazy biblioteki mediów, które mogą być logo (PNG, JPG, WebP), od najnowszych.
	 *
	 * @return list<BrandLogo>
	 */
	public function libraryImages(int $limit = 24): array
	{
		$images = [];
		$posts = get_posts([
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'post_mime_type' => array_values(array_unique(self::ALLOWED_MIMES)),
			'posts_per_page' => max(1, min(100, $limit)),
			'orderby' => 'date',
			'order' => 'DESC',
			'no_found_rows' => true,
		]);

		foreach ($posts as $post) {
			$image = $post instanceof WP_Post ? $this->image($post->ID) : null;

			if ($image !== null) {
				$images[] = $image;
			}
		}

		return $images;
	}

	/**
	 * Wybór istniejącego obrazu z biblioteki mediów.
	 *
	 * @throws AccessDenied
	 * @throws ValidationException
	 */
	public function select(int $attachmentId, int $userId): BrandLogo
	{
		$this->assertCan($userId);
		$image = $attachmentId > 0 ? $this->image($attachmentId) : null;

		if ($image === null) {
			throw new ValidationException(['logo' => 'Wybierz obraz PNG, JPG albo WebP z biblioteki mediów.']);
		}

		update_option(self::OPTION, $image->id, true);
		$this->logger->info('Application logo set to attachment {attachment} by user {user}.', ['attachment' => $image->id, 'user' => $userId]);

		return $image;
	}

	/**
	 * Nowy obraz: walidacja (rozmiar, rozszerzenie i typ z zawartości, wymiary) → biblioteka mediów WordPressa → logo.
	 *
	 * @param array{name?: string, tmp_name?: string, error?: int, size?: int} $file plik po kontroli `is_uploaded_file` (kontroler)
	 * @throws AccessDenied
	 * @throws ValidationException
	 */
	public function upload(array $file, int $userId): BrandLogo
	{
		$this->assertCan($userId);
		$tmp = (string) ($file['tmp_name'] ?? '');
		$name = sanitize_file_name((string) ($file['name'] ?? ''));
		$error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

		if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
			throw new ValidationException(['logo' => 'Plik jest za duży dla ustawień serwera.']);
		}

		if ($error !== UPLOAD_ERR_OK || $tmp === '' || ! is_file($tmp)) {
			throw new ValidationException(['logo' => 'Wybierz plik obrazu do przesłania.']);
		}

		if ((int) filesize($tmp) > self::MAX_BYTES) {
			throw new ValidationException(['logo' => 'Plik jest za duży — maksymalnie 2 MB.']);
		}

		$check = wp_check_filetype_and_ext($tmp, $name, self::ALLOWED_MIMES);
		$size = function_exists('wp_getimagesize') ? wp_getimagesize($tmp) : @getimagesize($tmp);

		if (empty($check['ext']) || empty($check['type']) || ! is_array($size) || ! in_array($size['mime'] ?? '', self::ALLOWED_MIMES, true)
			|| $size['mime'] !== $check['type']) {
			throw new ValidationException(['logo' => 'Dozwolone są wyłącznie obrazy PNG, JPG i WebP (rozszerzenie zgodne z zawartością pliku).']);
		}

		if ($size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_DIMENSION || $size[1] > self::MAX_DIMENSION) {
			throw new ValidationException(['logo' => sprintf('Obraz może mieć najwyżej %d × %d px.', self::MAX_DIMENSION, self::MAX_DIMENSION)]);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Na czas zapisu WordPress przyjmuje wyłącznie te typy (także gdy inna wtyczka globalnie dopuszcza SVG).
		$mimes = static fn (): array => self::ALLOWED_MIMES;
		add_filter('upload_mimes', $mimes, PHP_INT_MAX);

		try {
			$attachmentId = media_handle_sideload(
				['name' => $name !== '' ? $name : 'logo.' . explode('|', (string) $check['ext'])[0], 'tmp_name' => $tmp, 'error' => 0, 'size' => (int) filesize($tmp)],
				0,
				// Tytuł z nazwy pliku (jak w bibliotece mediów) — obrazy w wyborze logo są rozróżnialne.
				null,
			);
		} finally {
			remove_filter('upload_mimes', $mimes, PHP_INT_MAX);
		}

		if (is_wp_error($attachmentId)) {
			$this->logger->warning('Application logo upload rejected by WordPress: {message}', ['message' => $attachmentId->get_error_message()]);

			throw new ValidationException(['logo' => 'Nie udało się zapisać obrazu w bibliotece mediów.']);
		}

		if (get_post_meta((int) $attachmentId, '_wp_attachment_image_alt', true) === '') {
			update_post_meta((int) $attachmentId, '_wp_attachment_image_alt', self::APP_NAME);
		}

		return $this->select((int) $attachmentId, $userId);
	}

	/**
	 * Usunięcie logo (powrót do napisu). Obraz zostaje w bibliotece mediów.
	 *
	 * @throws AccessDenied
	 */
	public function remove(int $userId): void
	{
		$this->assertCan($userId);
		delete_option(self::OPTION);
		$this->logger->info('Application logo removed by user {user}.', ['user' => $userId]);
	}

	private function image(int $attachmentId): ?BrandLogo
	{
		$post = get_post($attachmentId);

		if (! $post instanceof WP_Post || $post->post_type !== 'attachment' || ! in_array($post->post_mime_type, self::ALLOWED_MIMES, true)) {
			return null;
		}

		$file = get_attached_file($attachmentId);

		if (! is_string($file) || $file === '' || ! is_file($file)) {
			return null;
		}

		$medium = wp_get_attachment_image_src($attachmentId, 'medium');
		$full = wp_get_attachment_image_src($attachmentId, 'full');

		if (! is_array($full)) {
			return null;
		}

		$src = is_array($medium) ? $medium : $full;
		$srcset = wp_get_attachment_image_srcset($attachmentId, 'medium');
		$alt = trim((string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true));

		return new BrandLogo(
			$attachmentId,
			(string) $src[0],
			(int) $src[1],
			(int) $src[2],
			is_string($srcset) ? $srcset : null,
			$alt !== '' ? $alt : self::APP_NAME,
			(string) wp_get_attachment_image_url($attachmentId, 'thumbnail') ?: (string) $src[0],
			$post->post_title !== '' ? $post->post_title : basename($file),
		);
	}

	/** @throws AccessDenied */
	private function assertCan(int $userId): void
	{
		if ($userId <= 0 || ! user_can($userId, Capabilities::MANAGE_SETTINGS)) {
			throw new AccessDenied('Changing the application logo requires ' . Capabilities::MANAGE_SETTINGS . '.');
		}
	}
}
