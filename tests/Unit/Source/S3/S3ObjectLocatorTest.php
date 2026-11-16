<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Tests\Unit\Source\S3;

use Aws\S3\S3ClientInterface;
use OCA\ArchiefMeta\Source\S3\S3ObjectLocator;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\TestCase;

/** Stands in for core's S3 connection (primary object store or AmazonS3 mount). */
class FakeS3Connection {
	public function __construct(
		private S3ClientInterface $client,
		private string $bucket,
	) {
	}

	public function getConnection(): S3ClientInterface {
		return $this->client;
	}

	public function getBucket(): string {
		return $this->bucket;
	}
}

/** Stands in for core's ObjectStoreStorage. */
class FakeObjectStoreStorage {
	public function __construct(
		private FakeS3Connection $objectStore,
	) {
	}

	public function getObjectStore(): FakeS3Connection {
		return $this->objectStore;
	}

	public function getURN(int $fileId): string {
		return 'urn:oid:' . $fileId;
	}
}

/** Stands in for an AmazonS3 external storage. */
class FakeExternalS3Storage extends FakeS3Connection {
}

/** Stands in for a jail or share wrapper around another storage. */
class FakeJail {
	public function __construct(
		private object $inner,
		private string $root,
	) {
	}

	public function getWrapperStorage(): object {
		return $this->inner;
	}

	public function getUnjailedPath(string $path): string {
		return trim($this->root . '/' . $path, '/');
	}
}

class S3ObjectLocatorTest extends TestCase {
	private function file(object $storage, string $internalPath, int $id = 42): File {
		$file = $this->createMock(File::class);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn($internalPath);
		$file->method('getId')->willReturn($id);
		return $file;
	}

	public function testPrimaryObjectStoreUsesUrnKey(): void {
		$client = $this->createMock(S3ClientInterface::class);
		$storage = new FakeObjectStoreStorage(new FakeS3Connection($client, 'primary'));

		$location = (new S3ObjectLocator())->locate($this->file($storage, 'files/Report.pdf'));

		$this->assertNotNull($location);
		$this->assertSame($client, $location->client);
		$this->assertSame('primary', $location->bucket);
		$this->assertSame('urn:oid:42', $location->key);
	}

	public function testExternalMountUsesPathThroughJails(): void {
		$client = $this->createMock(S3ClientInterface::class);
		$storage = new FakeJail(new FakeExternalS3Storage($client, 'external'), 'shared/folder');

		$location = (new S3ObjectLocator())->locate($this->file($storage, 'Report.pdf'));

		$this->assertNotNull($location);
		$this->assertSame('external', $location->bucket);
		$this->assertSame('shared/folder/Report.pdf', $location->key);
	}

	public function testLocalStorageIsNotS3(): void {
		$location = (new S3ObjectLocator())->locate($this->file($this->createMock(IStorage::class), 'files/Report.pdf'));
		$this->assertNull($location);
	}

	public function testFoldersAreSkipped(): void {
		$this->assertNull((new S3ObjectLocator())->locate($this->createMock(Folder::class)));
	}

	public function testStorageErrorsYieldNull(): void {
		$file = $this->createMock(File::class);
		$file->method('getStorage')->willThrowException(new \RuntimeException('gone'));
		$this->assertNull((new S3ObjectLocator())->locate($file));
	}
}
