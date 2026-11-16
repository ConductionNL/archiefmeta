<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Source;

use DateTimeImmutable;
use OCA\ArchiefMeta\Db\ManualValueMapper;
use OCA\ArchiefMeta\Model\Field;
use OCA\ArchiefMeta\Model\FieldValue;
use OCA\ArchiefMeta\Model\Source;
use OCP\Files\Node;

/**
 * Values an administrator set with `occ archiefmeta:set`.
 *
 * Used where the storage layer has no value, for example on local storage.
 */
class AdministratorSource implements IMetadataSource {
	public function __construct(
		private ManualValueMapper $mapper,
	) {
	}

	#[\Override]
	public function read(Node $node, DateTimeImmutable $readAt): array {
		$result = [];
		foreach ($this->mapper->findByFileId($node->getId()) as $manual) {
			$field = Field::tryFrom($manual->getField());
			$values = $manual->getValues();
			if ($field === null || $values === []) {
				continue;
			}
			$details = ['setBy' => $manual->getSetBy()];
			if ($manual->getSetAt() > 0) {
				$details['setAt'] = (new DateTimeImmutable('@' . $manual->getSetAt()))->format(DATE_ATOM);
			}
			$result[] = new FieldValue($field, $values, Source::Administrator, $readAt, array_filter($details));
		}
		return $result;
	}
}
