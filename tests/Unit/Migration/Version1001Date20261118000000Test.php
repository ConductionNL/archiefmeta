<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\ArchiefMeta\Migration\Version1001Date20261118000000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version1001Date20261118000000Test extends TestCase {
	private function migrate(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version1001Date20261118000000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	public function testAddsTheDetailsColumn(): void {
		$table = $this->createMock(Table::class);
		$table->method('hasColumn')->with('details_json')->willReturn(false);
		$table->expects($this->once())->method('addColumn')->with('details_json', Types::TEXT, ['notnull' => false]);
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('archiefmeta_manual')->willReturn(true);
		$schema->method('getTable')->with('archiefmeta_manual')->willReturn($table);

		$this->assertSame($schema, $this->migrate($schema));
	}

	public function testDoesNothingWhenTheColumnExists(): void {
		$table = $this->createMock(Table::class);
		$table->method('hasColumn')->willReturn(true);
		$table->expects($this->never())->method('addColumn');
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($table);

		$this->assertNull($this->migrate($schema));
	}
}
