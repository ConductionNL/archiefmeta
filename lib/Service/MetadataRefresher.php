<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Service;

use OCA\ArchiefMeta\Model\Field;
use OCA\ArchiefMeta\Model\FieldValue;
use OCA\ArchiefMeta\Source\IMetadataSource;
use OCA\ArchiefMeta\Storage\IMetadataStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a file's archival metadata from the sources and stores the result.
 *
 * Sources are asked in order of precedence. Per field, the first source that has
 * a value wins, so the storage layer goes before values set by an administrator.
 */
class MetadataRefresher {
	/**
	 * @param list<IMetadataSource> $sources in order of precedence
	 */
	public function __construct(
		private array $sources,
		private IMetadataStore $store,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return list<FieldValue> the values now stored, in field order; empty for folders
	 */
	public function refresh(Node $node): array {
		if (!$node instanceof File) {
			return [];
		}

		$readAt = $this->timeFactory->now();
		$found = [];
		foreach ($this->sources as $source) {
			try {
				$values = $source->read($node, $readAt);
			} catch (Throwable $e) {
				$this->logger->warning('archiefmeta: source {source} failed for file {fileId}', [
					'source' => $source::class,
					'fileId' => $node->getId(),
					'exception' => $e,
				]);
				continue;
			}
			foreach ($values as $value) {
				$found[$value->field->value] ??= $value;
			}
		}

		$ordered = [];
		foreach (Field::cases() as $field) {
			if (isset($found[$field->value])) {
				$ordered[] = $found[$field->value];
			}
		}

		$this->store->save($node, $ordered);
		return $ordered;
	}
}
