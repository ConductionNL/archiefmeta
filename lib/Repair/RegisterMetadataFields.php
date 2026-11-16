<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Repair;

use OCA\ArchiefMeta\Storage\IMetadataStore;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Declares the archival fields to Nextcloud as read-only for every user.
 */
class RegisterMetadataFields implements IRepairStep {
	public function __construct(
		private IMetadataStore $store,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Register read-only archival metadata fields';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$this->store->registerFields();
	}
}
