<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Model;

/**
 * Details of an MDTO begripGegevens value, and the closed MDTO list of waarderingen.
 *
 * The label is the field value; begripCode and begripBegrippenlijst are kept as details.
 */
final class Begrip {
	public const CODE = 'begripCode';
	public const BEGRIPPENLIJST = 'begripBegrippenlijst';
	public const WAARDERINGEN = 'Begrippenlijst Waarderingen MDTO';

	/** The closed MDTO list of waarderingen, label by code. */
	public const WAARDERING_LABELS = [
		'B' => 'Blijvend te bewaren',
		'V' => 'Tijdelijk te bewaren',
		'N' => 'Nader te bepalen',
	];

	/**
	 * Completes a waardering given as an MDTO code or label (case insensitive).
	 *
	 * Only applies when no begrippenlijst is given or it is the MDTO list.
	 *
	 * @param array<string, string> $details
	 * @return array{0: string, 1: array<string, string>}|null label and details, or null when the value is not in the MDTO list
	 */
	public static function waardering(string $value, array $details = []): ?array {
		$list = $details[self::BEGRIPPENLIJST] ?? self::WAARDERINGEN;
		if (strcasecmp($list, self::WAARDERINGEN) !== 0) {
			return null;
		}
		$code = strtoupper(trim($details[self::CODE] ?? ''));
		foreach (self::WAARDERING_LABELS as $knownCode => $label) {
			if (strcasecmp(trim($value), $knownCode) === 0 || strcasecmp(trim($value), $label) === 0) {
				if ($code !== '' && $code !== $knownCode) {
					return null;
				}
				return [$label, [self::CODE => $knownCode, self::BEGRIPPENLIJST => self::WAARDERINGEN]];
			}
		}
		return null;
	}
}
