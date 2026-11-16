<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Tests\Unit\Listener;

use OCA\ArchiefMeta\Listener\RefreshOnWrite;
use OCA\ArchiefMeta\Repair\RegisterMetadataFields;
use OCA\ArchiefMeta\Service\MetadataRefresher;
use OCA\ArchiefMeta\Storage\IMetadataStore;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ListenersTest extends TestCase {
	public function testRefreshesWrittenCopiedAndMovedFiles(): void {
		$written = $this->createMock(File::class);
		$copied = $this->createMock(File::class);
		$moved = $this->createMock(File::class);
		$refreshed = [];
		$refresher = $this->createMock(MetadataRefresher::class);
		$refresher->method('refresh')->willReturnCallback(function (File $file) use (&$refreshed): array {
			$refreshed[] = $file;
			return [];
		});
		$listener = new RefreshOnWrite($refresher, $this->createMock(LoggerInterface::class));

		$listener->handle(new NodeWrittenEvent($written));
		$listener->handle(new NodeCopiedEvent($this->createMock(File::class), $copied));
		$listener->handle(new NodeRenamedEvent($this->createMock(File::class), $moved));
		$listener->handle(new NodeWrittenEvent($this->createMock(Folder::class)));
		$listener->handle(new Event());

		$this->assertSame([$written, $copied, $moved], $refreshed);
	}

	public function testFailureNeverBlocksTheFileOperation(): void {
		$refresher = $this->createMock(MetadataRefresher::class);
		$refresher->method('refresh')->willThrowException(new \RuntimeException('S3 down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		(new RefreshOnWrite($refresher, $logger))->handle(new NodeWrittenEvent($this->createMock(File::class)));
	}

	public function testRepairStepRegistersFields(): void {
		$store = $this->createMock(IMetadataStore::class);
		$store->expects($this->once())->method('registerFields');
		$step = new RegisterMetadataFields($store);

		$step->run($this->createMock(IOutput::class));
		$this->assertNotSame('', $step->getName());
	}
}
