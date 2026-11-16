<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Storage;

use OCA\ArchiefMeta\Model\Field;
use OCA\ArchiefMeta\Model\FieldValue;
use OCP\Files\Node;
use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\FilesMetadata\Exceptions\FilesMetadataTypeException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;

/**
 * Stores values as core file metadata (OCP\FilesMetadata), one array per field
 * under the key "archiefmeta-<field>".
 *
 * Every key is registered and saved with EDIT_FORBIDDEN, so core's WebDAV
 * PROPPATCH handler refuses changes from every user, owners included.
 */
class FilesMetadataStore implements IMetadataStore {
	public const KEY_PREFIX = 'archiefmeta-';

	public function __construct(
		private IFilesMetadataManager $manager,
	) {
	}

	public static function keyOf(Field $field): string {
		return self::KEY_PREFIX . $field->value;
	}

	#[\Override]
	public function registerFields(): void {
		foreach (Field::cases() as $field) {
			$this->manager->initMetadata(
				self::keyOf($field),
				IMetadataValueWrapper::TYPE_ARRAY,
				false,
				IMetadataValueWrapper::EDIT_FORBIDDEN,
			);
		}
	}

	#[\Override]
	public function save(Node $node, array $values): void {
		$byField = [];
		foreach ($values as $value) {
			$byField[$value->field->value] = $value;
		}

		$metadata = $this->manager->getMetadata($node->getId(), true);
		foreach (Field::cases() as $field) {
			$key = self::keyOf($field);
			if (isset($byField[$field->value])) {
				$metadata->setArray($key, $byField[$field->value]->toArray());
				$metadata->setEditPermission($key, IMetadataValueWrapper::EDIT_FORBIDDEN);
			} elseif ($metadata->hasKey($key)) {
				$metadata->unset($key);
			}
		}
		$this->manager->saveMetadata($metadata);
	}

	#[\Override]
	public function load(int $fileId): array {
		try {
			$metadata = $this->manager->getMetadata($fileId);
		} catch (FilesMetadataNotFoundException) {
			return [];
		}

		$values = [];
		foreach (Field::cases() as $field) {
			$key = self::keyOf($field);
			if (!$metadata->hasKey($key)) {
				continue;
			}
			try {
				$value = FieldValue::fromArray($field, $metadata->getArray($key));
			} catch (FilesMetadataNotFoundException|FilesMetadataTypeException) {
				$value = null;
			}
			if ($value !== null) {
				$values[] = $value;
			}
		}
		return $values;
	}
}
