<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Model;

/**
 * The archival metadata fields the app shows, named after their MDTO element.
 */
enum Field: string {
	case Classificatie = 'classificatie';
	case Bewaartermijn = 'bewaartermijn';
	case Zorgdrager = 'zorgdrager';
	case Identificatie = 'identificatie';

	/**
	 * Whether the field may hold more than one value.
	 */
	public function isMultiple(): bool {
		return $this === self::Zorgdrager || $this === self::Classificatie;
	}
}
