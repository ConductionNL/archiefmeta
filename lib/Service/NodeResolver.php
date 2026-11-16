<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Service;

use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;

/**
 * Turns a command line target (a file ID, or a path such as "alice/files/Report.pdf") into a node.
 */
class NodeResolver {
	public function __construct(
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * @throws NotFoundException when nothing matches
	 */
	public function resolve(string $target): Node {
		$target = trim($target);
		if ($target !== '' && ctype_digit($target)) {
			$node = $this->rootFolder->getFirstNodeById((int)$target);
			if ($node === null) {
				throw new NotFoundException('No file with ID ' . $target);
			}
			return $node;
		}

		$path = '/' . ltrim($target, '/');
		if ($path === '/') {
			throw new NotFoundException('Give a file ID or a path such as alice/files/Report.pdf');
		}
		return $this->rootFolder->get($path);
	}
}
