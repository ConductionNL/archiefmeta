<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Source\S3;

use Aws\S3\S3ClientInterface;

/**
 * The S3 object that holds a file's bytes, with the client that can reach it.
 */
final class S3ObjectLocation {
	public function __construct(
		public readonly S3ClientInterface $client,
		public readonly string $bucket,
		public readonly string $key,
	) {
	}
}
