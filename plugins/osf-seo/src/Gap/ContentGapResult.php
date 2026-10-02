<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

final class ContentGapResult
{
	public function __construct(
		public readonly ContentGap $gap,
		public readonly string $reason,
		/** low | medium | high */
		public readonly string $confidence,
	) {
	}

	public static function reasonLabel(string $reason): string
	{
		return match ($reason) {
			'no_project_data' => 'brak danych widoczności projektu (GSC, SERP, Labs)',
			'scattered' => 'wyświetlenia rozłożone na kilka stron projektu — sprawdź kanibalizację',
			'covered' => 'projekt ma stronę i porównywalną widoczność',
			'homepage_only' => 'projekt widoczny tylko stroną główną, konkurenci mają podstrony',
			'target_serp' => 'strona projektu z pomiaru SERP',
			'target_gsc' => 'strona projektu z GSC (większość wyświetleń grupy)',
			'target_labs' => 'strona projektu według DataForSEO Labs',
			'target_slug' => 'adres strony projektu pasuje do frazy (bez wyświetleń w GSC)',
			'no_target' => 'brak przekonującej strony docelowej projektu, konkurenci mają podstrony',
			'competitors_homepages' => 'konkurenci rankują stronami głównymi — temat może nie wymagać osobnej strony',
			'low_demand' => 'mały popyt na frazy grupy',
			default => $reason,
		};
	}

	public static function confidenceLabel(string $confidence): string
	{
		return match ($confidence) {
			'high' => 'wysoka',
			'medium' => 'średnia',
			default => 'niska',
		};
	}
}
