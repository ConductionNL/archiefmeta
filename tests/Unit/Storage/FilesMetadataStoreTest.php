<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Tests\Unit\Storage;

use DateTimeImmutable;
use OCA\ArchiefMeta\Model\Field;
use OCA\ArchiefMeta\Model\FieldValue;
use OCA\ArchiefMeta\Model\Source;
use OCA\ArchiefMeta\Storage\FilesMetadataStore;
use OCP\Files\File;
use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;
use PHPUnit\Framework\TestCase;

class FilesMetadataStoreTest extends TestCase {
	public function testRegistersEveryFieldAsForbiddenToEdit(): void {
		$manager = $this->createMock(IFilesMetadataManager::class);
		$registered = [];
		$manager->expects($this->exactly(6))->method('initMetadata')
			->willReturnCallback(function (string $key, string $type, bool $indexed, int $edit) use (&$registered): void {
				$registered[$key] = [$type, $indexed, $edit];
			});

		(new FilesMetadataStore($manager))->registerFields();

		$this->assertSame([
			'archiefmeta-classificatie' => [IMetadataValueWrapper::TYPE_ARRAY, false, IMetadataValueWrapper::EDIT_FORBIDDEN],
			'archiefmeta-bewaartermijn' => [IMetadataValueWrapper::TYPE_ARRAY, false, IMetadataValueWrapper::EDIT_FORBIDDEN],
			'archiefmeta-waardering' => [IMetadataValueWrapper::TYPE_ARRAY, false, IMetadataValueWrapper::EDIT_FORBIDDEN],
			'archiefmeta-informatiecategorie' => [IMetadataValueWrapper::TYPE_ARRAY, false, IMetadataValueWrapper::EDIT_FORBIDDEN],
			'archiefmeta-zorgdrager' => [IMetadataValueWrapper::TYPE_ARRAY, false, IMetadataValueWrapper::EDIT_FORBIDDEN],
			'archiefmeta-identificatie' => [IMetadataValueWrapper::TYPE_ARRAY, false, IMetadataValueWrapper::EDIT_FORBIDDEN],
		], $registered);
	}

	public function testSaveWritesForbiddenValuesAndRemovesStaleFields(): void {
		$value = new FieldValue(Field::Classificatie, ['Openbaar'], Source::S3ObjectMetadata, new DateTimeImmutable('2026-11-16T10:00:00+00:00'));

		$metadata = $this->createMock(IFilesMetadata::class);
		$metadata->method('hasKey')->willReturnCallback(fn (string $key): bool => $key === 'archiefmeta-zorgdrager');
		$metadata->expects($this->once())->method('setArray')->with('archiefmeta-classificatie', $value->toArray());
		$metadata->expects($this->once())->method('setEditPermission')->with('archiefmeta-classificatie', IMetadataValueWrapper::EDIT_FORBIDDEN);
		$metadata->expects($this->once())->method('unset')->with('archiefmeta-zorgdrager');

		$manager = $this->createMock(IFilesMetadataManager::class);
		$manager->expects($this->once())->method('getMetadata')->with(42, true)->willReturn($metadata);
		$manager->expects($this->once())->method('saveMetadata')->with($metadata);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);

		(new FilesMetadataStore($manager))->save($file, [$value]);
	}

	public function testLoadReturnsStoredValuesInFieldOrder(): void {
		$zorgdrager = new FieldValue(Field::Zorgdrager, ['A', 'B'], Source::Administrator, new DateTimeImmutable('2026-11-16T10:00:00+00:00'));
		$classificatie = new FieldValue(Field::Classificatie, ['Intern'], Source::S3ObjectTags, new DateTimeImmutable('2026-11-16T10:00:00+00:00'));
		$stored = [
			'archiefmeta-zorgdrager' => $zorgdrager->toArray(),
			'archiefmeta-classificatie' => $classificatie->toArray(),
			'archiefmeta-identificatie' => ['garbage' => true],
		];

		$metadata = $this->createMock(IFilesMetadata::class);
		$metadata->method('hasKey')->willReturnCallback(fn (string $key): bool => isset($stored[$key]));
		$metadata->method('getArray')->willReturnCallback(fn (string $key): array => $stored[$key]);
		$manager = $this->createMock(IFilesMetadataManager::class);
		$manager->method('getMetadata')->with(42)->willReturn($metadata);

		$values = (new FilesMetadataStore($manager))->load(42);

		$this->assertEquals([$classificatie, $zorgdrager], $values);
	}

	public function testLoadWithoutMetadataIsEmpty(): void {
		$manager = $this->createMock(IFilesMetadataManager::class);
		$manager->method('getMetadata')->willThrowException(new FilesMetadataNotFoundException());

		$this->assertSame([], (new FilesMetadataStore($manager))->load(42));
	}
}
