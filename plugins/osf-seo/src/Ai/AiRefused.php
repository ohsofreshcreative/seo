<?php

declare(strict_types=1);

namespace OsfSeo\Ai;

use RuntimeException;

/**
 * Odmowa uruchomienia analizy AI przed jakimkolwiek żądaniem (wyłącznik, konfiguracja, ceny, limity, brak potwierdzenia, blokada budżetu).
 * Kod jest stabilny (CLI, przyszły panel); komunikat bez wartości konfiguracji i sekretów.
 */
final class AiRefused extends RuntimeException
{
	/**
	 * @param list<string> $blockers wszystkie powody (pierwszy = `code`)
	 */
	public function __construct(private readonly string $code_, private readonly array $blockers = [])
	{
		parent::__construct('AI analysis refused: ' . implode(', ', $blockers !== [] ? $blockers : [$code_]) . '.');
	}

	public function code(): string
	{
		return $this->code_;
	}

	/**
	 * @return list<string>
	 */
	public function blockers(): array
	{
		return $this->blockers !== [] ? $this->blockers : [$this->code_];
	}
}
