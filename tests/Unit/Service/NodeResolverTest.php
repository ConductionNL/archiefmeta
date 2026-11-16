<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Tests\Unit\Service;

use OCA\ArchiefMeta\Service\NodeResolver;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

class NodeResolverTest extends TestCase {
	public function testResolvesFileId(): void {
		$file = $this->createMock(File::class);
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->once())->method('getFirstNodeById')->with(42)->willReturn($file);

		$this->assertSame($file, (new NodeResolver($root))->resolve('42'));
	}

	public function testUnknownFileId(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getFirstNodeById')->willReturn(null);

		$this->expectException(NotFoundException::class);
		(new NodeResolver($root))->resolve('42');
	}

	public function testResolvesPath(): void {
		$file = $this->createMock(File::class);
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->once())->method('get')->with('/alice/files/Report.pdf')->willReturn($file);

		$this->assertSame($file, (new NodeResolver($root))->resolve('alice/files/Report.pdf'));
	}

	public function testEmptyTarget(): void {
		$this->expectException(NotFoundException::class);
		(new NodeResolver($this->createMock(IRootFolder::class)))->resolve(' / ');
	}
}
