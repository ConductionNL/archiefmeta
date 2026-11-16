<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Source;

use DateTimeImmutable;
use OCA\ArchiefMeta\Model\FieldValue;
use OCP\Files\Node;

/**
 * A place archival metadata can be read from, such as the storage layer or a register.
 *
 * Sources only read. They never write back, and they must not throw for a file
 * they cannot handle: they return an empty list instead.
 */
interface IMetadataSource {
	/**
	 * Reads the fields this source knows for a file.
	 *
	 * @param DateTimeImmutable $readAt the moment to record as the read time
	 * @return list<FieldValue> at most one value per field
	 */
	public function read(Node $node, DateTimeImmutable $readAt): array;
}
