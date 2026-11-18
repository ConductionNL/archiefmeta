<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Tests\Unit\Db;

use OCA\ArchiefMeta\Db\ManualValue;
use PHPUnit\Framework\TestCase;

class ManualValueTest extends TestCase {
	public function testDetailsRoundTrip(): void {
		$value = new ManualValue();
		$this->assertSame([], $value->getDetails());

		$value->setDetails(['begripCode' => '2.1.1', 'begripBegrippenlijst' => 'Selectielijst ministerie van Infrastructuur en Milieu 2017']);
		$this->assertSame(['begripCode' => '2.1.1', 'begripBegrippenlijst' => 'Selectielijst ministerie van Infrastructuur en Milieu 2017'], $value->getDetails());

		$value->setDetails([]);
		$this->assertNull($value->getDetailsJson());
	}

	public function testUnusableDetailsAreDropped(): void {
		$value = new ManualValue();
		$value->setDetailsJson('{"begripCode":"B","empty":"","nested":["x"]}');
		$this->assertSame(['begripCode' => 'B'], $value->getDetails());

		$value->setDetailsJson('not json');
		$this->assertSame([], $value->getDetails());
	}
}
