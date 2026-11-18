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
 * Adds the details of an administrator value, such as the begripCode and
 * begripBegrippenlijst of a waardering.
 */
class Version1001Date20261118000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('archiefmeta_manual')) {
			return null;
		}
		$table = $schema->getTable('archiefmeta_manual');
		if ($table->hasColumn('details_json')) {
			return null;
		}
		$table->addColumn('details_json', Types::TEXT, ['notnull' => false]);
		return $schema;
	}
}
