<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Source;

use DateTimeImmutable;
use OCA\ArchiefMeta\Model\Field;
use OCA\ArchiefMeta\Model\FieldValue;
use OCA\ArchiefMeta\Model\Source;
use OCP\Files\Node;
use OCP\IConfig;

/**
 * The file's current Nextcloud identifier, used for identificatie when no source has one.
 *
 * The file ID is unique within one instance only, so it is reported together with
 * the instance ID and labelled as not durable.
 */
class FileIdSource implements IMetadataSource {
	public function __construct(
		private IConfig $config,
	) {
	}

	#[\Override]
	public function read(Node $node, DateTimeImmutable $readAt): array {
		$fileId = $node->getId();
		if ($fileId <= 0) {
			return [];
		}
		return [new FieldValue(
			Field::Identificatie,
			[(string)$fileId],
			Source::NextcloudFileId,
			$readAt,
			array_filter(['instanceId' => (string)$this->config->getSystemValueString('instanceid', '')]),
		)];
	}
}
