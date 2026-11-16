<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Source\S3;

use Aws\S3\S3ClientInterface;
use OCP\Files\File;
use OCP\Files\Node;
use Throwable;

/**
 * Finds the S3 object behind a Nextcloud file.
 *
 * Handles primary object storage (key "<objectPrefix><fileid>") and files_external
 * AmazonS3 mounts (key = path inside the mount). Storage wrappers, jails and shares
 * are unwrapped first. Core's S3 classes are private, so they are recognised by the
 * methods they expose rather than by class name.
 */
class S3ObjectLocator {
	public function locate(Node $node): ?S3ObjectLocation {
		if (!$node instanceof File) {
			return null;
		}

		try {
			$storage = $node->getStorage();
			$path = $node->getInternalPath();
			while (is_object($storage) && method_exists($storage, 'getWrapperStorage')) {
				if (method_exists($storage, 'getUnjailedPath')) {
					$path = (string)$storage->getUnjailedPath($path);
				}
				$storage = $storage->getWrapperStorage();
			}

			if (is_object($storage) && method_exists($storage, 'getObjectStore') && method_exists($storage, 'getURN')) {
				$objectStore = $storage->getObjectStore();
				$client = $this->clientOf($objectStore);
				if ($client === null) {
					return null;
				}
				return new S3ObjectLocation($client, (string)$objectStore->getBucket(), (string)$storage->getURN($node->getId()));
			}

			$client = $this->clientOf($storage);
			$key = trim($path, '/');
			if ($client === null || $key === '') {
				return null;
			}
			return new S3ObjectLocation($client, (string)$storage->getBucket(), $key);
		} catch (Throwable) {
			return null;
		}
	}

	private function clientOf(object $candidate): ?S3ClientInterface {
		if (!method_exists($candidate, 'getConnection') || !method_exists($candidate, 'getBucket')) {
			return null;
		}
		$client = $candidate->getConnection();
		return $client instanceof S3ClientInterface ? $client : null;
	}
}
