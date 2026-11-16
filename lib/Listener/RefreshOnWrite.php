<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Listener;

use OCA\ArchiefMeta\Service\MetadataRefresher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-reads archival metadata when a file is written, copied or moved.
 *
 * A move can change the S3 object behind a file on an external mount, so the
 * target is read again. Failures are logged and never block the file operation.
 *
 * @template-implements IEventListener<Event>
 */
class RefreshOnWrite implements IEventListener {
	public function __construct(
		private MetadataRefresher $refresher,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($event instanceof NodeWrittenEvent) {
			$node = $event->getNode();
		} elseif ($event instanceof NodeCopiedEvent || $event instanceof NodeRenamedEvent) {
			$node = $event->getTarget();
		} else {
			return;
		}

		if (!$node instanceof File) {
			return;
		}

		try {
			$this->refresher->refresh($node);
		} catch (Throwable $e) {
			$this->logger->warning('archiefmeta: refresh after file change failed', ['exception' => $e]);
		}
	}
}
