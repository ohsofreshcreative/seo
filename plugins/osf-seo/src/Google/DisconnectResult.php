<?php

declare(strict_types=1);

namespace OsfSeo\Google;

final class DisconnectResult
{
	public function __construct(
		/** Projekt miał połączenie i zostało odpięte. */
		public readonly bool $detached,
		/** Połączenie nie było używane przez inne projekty — usunięto je z bazy. */
		public readonly bool $connectionRemoved,
		/** Dostęp unieważniono w Google (false: błąd sieci/Google — do ręcznego odwołania). */
		public readonly bool $revoked,
	) {
	}
}
