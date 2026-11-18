<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Tests\Unit\Model;

use OCA\ArchiefMeta\Model\Begrip;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BegripTest extends TestCase {
	/**
	 * @return array<string, array{string, array<string, string>, string, string}>
	 */
	public static function known(): array {
		return [
			'code' => ['V', [], 'Tijdelijk te bewaren', 'V'],
			'lower case code' => ['b', [], 'Blijvend te bewaren', 'B'],
			'label' => ['nader te bepalen', [], 'Nader te bepalen', 'N'],
			'matching code' => ['Blijvend te bewaren', [Begrip::CODE => 'b'], 'Blijvend te bewaren', 'B'],
			'MDTO list named' => ['V', [Begrip::BEGRIPPENLIJST => 'begrippenlijst waarderingen mdto'], 'Tijdelijk te bewaren', 'V'],
		];
	}

	/**
	 * @param array<string, string> $details
	 */
	#[DataProvider('known')]
	public function testCompletesAWaarderingFromTheMdtoList(string $value, array $details, string $label, string $code): void {
		$this->assertSame(
			[$label, [Begrip::CODE => $code, Begrip::BEGRIPPENLIJST => Begrip::WAARDERINGEN]],
			Begrip::waardering($value, $details),
		);
	}

	/**
	 * @return array<string, array{string, array<string, string>}>
	 */
	public static function unknown(): array {
		return [
			'not in the list' => ['Bewaren', []],
			'code contradicts label' => ['Blijvend te bewaren', [Begrip::CODE => 'V']],
			'another list' => ['V', [Begrip::BEGRIPPENLIJST => 'Selectielijst gemeenten 2020']],
		];
	}

	/**
	 * @param array<string, string> $details
	 */
	#[DataProvider('unknown')]
	public function testLeavesOtherValuesAlone(string $value, array $details): void {
		$this->assertNull(Begrip::waardering($value, $details));
	}
}
