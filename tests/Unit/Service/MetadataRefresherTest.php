<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\ArchiefMeta\Model\Field;
use OCA\ArchiefMeta\Model\FieldValue;
use OCA\ArchiefMeta\Model\Source;
use OCA\ArchiefMeta\Service\MetadataRefresher;
use OCA\ArchiefMeta\Source\IMetadataSource;
use OCA\ArchiefMeta\Storage\IMetadataStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MetadataRefresherTest extends TestCase {
	/**
	 * @param list<FieldValue> $values
	 */
	private function source(array $values): IMetadataSource {
		$source = $this->createMock(IMetadataSource::class);
		$source->method('read')->willReturn($values);
		return $source;
	}

	private function time(DateTimeImmutable $now): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn($now);
		return $time;
	}

	public function testFirstSourceWinsPerFieldAndResultIsStoredInFieldOrder(): void {
		$now = new DateTimeImmutable('2026-11-16T10:00:00+00:00');
		$s3Id = new FieldValue(Field::Identificatie, ['ZD-1'], Source::S3ObjectMetadata, $now);
		$s3Lock = new FieldValue(Field::Bewaartermijn, ['2036-01-01T00:00:00+00:00'], Source::S3ObjectLock, $now);
		$adminLock = new FieldValue(Field::Bewaartermijn, ['2030-01-01T00:00:00+00:00'], Source::Administrator, $now);
		$adminZorg = new FieldValue(Field::Zorgdrager, ['A'], Source::Administrator, $now);
		$fileId = new FieldValue(Field::Identificatie, ['42'], Source::NextcloudFileId, $now);

		$failing = $this->createMock(IMetadataSource::class);
		$failing->method('read')->willThrowException(new \RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$file = $this->createMock(File::class);
		$expected = [$s3Lock, $adminZorg, $s3Id];
		$store = $this->createMock(IMetadataStore::class);
		$store->expects($this->once())->method('save')->with($file, $expected);

		$refresher = new MetadataRefresher(
			[$failing, $this->source([$s3Id, $s3Lock]), $this->source([$adminLock, $adminZorg]), $this->source([$fileId])],
			$store,
			$this->time($now),
			$logger,
		);

		$this->assertSame($expected, $refresher->refresh($file));
	}

	public function testFoldersAreNotRefreshed(): void {
		$store = $this->createMock(IMetadataStore::class);
		$store->expects($this->never())->method('save');
		$refresher = new MetadataRefresher([], $store, $this->time(new DateTimeImmutable()), $this->createMock(LoggerInterface::class));

		$this->assertSame([], $refresher->refresh($this->createMock(Folder::class)));
	}

	public function testNoValuesClearsTheStore(): void {
		$file = $this->createMock(File::class);
		$store = $this->createMock(IMetadataStore::class);
		$store->expects($this->once())->method('save')->with($file, []);
		$refresher = new MetadataRefresher([$this->source([])], $store, $this->time(new DateTimeImmutable()), $this->createMock(LoggerInterface::class));

		$this->assertSame([], $refresher->refresh($file));
	}
}
