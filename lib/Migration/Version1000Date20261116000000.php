<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the table for values set by an administrator.
 */
class Version1000Date20261116000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('archiefmeta_manual')) {
			return null;
		}

		$table = $schema->createTable('archiefmeta_manual');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('field', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('values_json', Types::TEXT, ['notnull' => true]);
		$table->addColumn('set_by', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
		$table->addColumn('set_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['file_id', 'field'], 'archiefmeta_manual_file_field');

		return $schema;
	}
}
