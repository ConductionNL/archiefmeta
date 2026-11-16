<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Model;

use OCP\IL10N;

/**
 * Where a value was read from ("bron").
 */
enum Source: string {
	case S3ObjectLock = 's3-object-lock';
	case S3ObjectMetadata = 's3-object-metadata';
	case S3ObjectTags = 's3-object-tags';
	case Administrator = 'administrator';
	case NextcloudFileId = 'nextcloud-fileid';

	public function label(IL10N $l): string {
		return match ($this) {
			self::S3ObjectLock => $l->t('Storage layer (S3 Object Lock)'),
			self::S3ObjectMetadata => $l->t('Storage layer (S3 object metadata)'),
			self::S3ObjectTags => $l->t('Storage layer (S3 object tags)'),
			self::Administrator => $l->t('Administrator'),
			self::NextcloudFileId => $l->t('Nextcloud file ID (not durable)'),
		};
	}

	/**
	 * Whether the value comes from the storage layer itself.
	 */
	public function isStorageLayer(): bool {
		return $this === self::S3ObjectLock || $this === self::S3ObjectMetadata || $this === self::S3ObjectTags;
	}
}
