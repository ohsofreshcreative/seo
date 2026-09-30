<?php

namespace App\Panel;

/**
 * Komunikaty „flash” (po przekierowaniu) przechowywane per użytkownik w transiencie —
 * bez sesji PHP/Laravela. Odczyt usuwa komunikaty.
 */
final class Flash
{
	private const TTL = 300;

	public static function success(string $message): void
	{
		self::push('success', $message);
	}

	public static function error(string $message): void
	{
		self::push('error', $message);
	}

	public static function info(string $message): void
	{
		self::push('info', $message);
	}

	/**
	 * @return list<array{type: string, message: string}>
	 */
	public static function pull(): array
	{
		$userId = get_current_user_id();

		if ($userId <= 0) {
			return [];
		}

		$messages = get_transient(self::key($userId));

		if ($messages === false) {
			return [];
		}

		delete_transient(self::key($userId));

		return is_array($messages) ? array_values($messages) : [];
	}

	private static function push(string $type, string $message): void
	{
		$userId = get_current_user_id();

		if ($userId <= 0) {
			return;
		}

		$messages = get_transient(self::key($userId));
		$messages = is_array($messages) ? $messages : [];
		$messages[] = ['type' => $type, 'message' => $message];

		set_transient(self::key($userId), $messages, self::TTL);
	}

	private static function key(int $userId): string
	{
		return 'osf_seo_flash_' . $userId;
	}
}
