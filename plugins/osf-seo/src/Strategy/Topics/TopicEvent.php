<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Topics;

/**
 * Typy zdarzeń tematu — wyłącznie istotne zmiany (D61), bez pełnych migawek.
 */
final class TopicEvent
{
	public const CREATED = 'created';

	public const ACTION_CHANGED = 'action_changed';

	public const TARGET_CHANGED = 'target_changed';

	public const CONFIDENCE_BAND_CHANGED = 'confidence_band_changed';

	public const PRIORITY_BAND_CHANGED = 'priority_band_changed';

	public const SERP_BAND_CHANGED = 'serp_band_changed';

	public const STATUS_CHANGED = 'status_changed';

	public const ACTIVATED = 'activated';

	public const DEACTIVATED = 'deactivated';

	public const MERGED = 'merged';

	public const SPLIT = 'split';

	public const LEADER_CHANGED = 'leader_changed';

	public const PINNED = 'pinned';

	public const MANUAL_TARGET = 'manual_target';

	public static function label(string $type): string
	{
		return match ($type) {
			self::CREATED => 'Nowy temat',
			self::ACTION_CHANGED => 'Zmiana działania',
			self::TARGET_CHANGED => 'Zmiana strony docelowej',
			self::CONFIDENCE_BAND_CHANGED => 'Zmiana poziomu pewności',
			self::PRIORITY_BAND_CHANGED => 'Zmiana pasma priorytetu',
			self::SERP_BAND_CHANGED => 'Zmiana pasma Pozycji SERP',
			self::STATUS_CHANGED => 'Zmiana statusu',
			self::ACTIVATED => 'Temat ponownie aktywny',
			self::DEACTIVATED => 'Temat nieaktywny',
			self::MERGED => 'Scalony z innym tematem',
			self::SPLIT => 'Podział tematu',
			self::LEADER_CHANGED => 'Zmiana frazy głównej',
			self::PINNED => 'Przypięcie fraz',
			self::MANUAL_TARGET => 'Ręczna strona docelowa',
			default => $type,
		};
	}
}
