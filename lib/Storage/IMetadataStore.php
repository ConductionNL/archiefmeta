<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Storage;

use OCA\ArchiefMeta\Model\FieldValue;
use OCP\Files\Node;

/**
 * Where the app keeps the values it read, and how it declares its fields to Nextcloud.
 *
 * This is the only place that knows the platform's metadata API. Moving to another
 * API (such as a field schema registry) means writing one new implementation.
 */
interface IMetadataStore {
	/**
	 * Declares every field to the platform as read-only for all users.
	 */
	public function registerFields(): void;

	/**
	 * Replaces all stored archival values of a file. Fields not in the list are removed.
	 *
	 * @param list<FieldValue> $values
	 */
	public function save(Node $node, array $values): void;

	/**
	 * @return list<FieldValue> the stored values, in field order
	 */
	public function load(int $fileId): array;
}
