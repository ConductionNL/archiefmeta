<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\ArchiefMeta\Db\ManualValue;
use OCA\ArchiefMeta\Db\ManualValueMapper;
use OCA\ArchiefMeta\Model\Field;
use OCA\ArchiefMeta\Service\ManualValueService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ManualValueServiceTest extends TestCase {
	private ManualValueMapper $mapper;
	private ManualValueService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(ManualValueMapper::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1763287200);
		$this->service = new ManualValueService($this->mapper, $time);
	}

	public function testSetReplacesTheFieldValue(): void {
		$this->mapper->expects($this->once())->method('deleteByFileId')->with(7, 'zorgdrager');
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(function (ManualValue $entity): ManualValue {
			$this->assertSame(7, $entity->getFileId());
			$this->assertSame('zorgdrager', $entity->getField());
			$this->assertSame(['Gemeente A', 'Gemeente B'], $entity->getValues());
			$this->assertSame('occ', $entity->getSetBy());
			$this->assertSame(1763287200, $entity->getSetAt());
			return $entity;
		});

		$this->service->set(7, Field::Zorgdrager, [' Gemeente A ', '', 'Gemeente B'], 'occ');
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function dates(): array {
		return [
			'date' => ['2036-01-01', '2036-01-01T00:00:00+00:00'],
			'date and time' => ['2036-01-01T12:00:00+02:00', '2036-01-01T10:00:00+00:00'],
		];
	}

	#[DataProvider('dates')]
	public function testRetentionDateIsNormalisedToUtc(string $input, string $stored): void {
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(function (ManualValue $entity) use ($stored): ManualValue {
			$this->assertSame([$stored], $entity->getValues());
			return $entity;
		});
		$this->service->set(7, Field::Bewaartermijn, [$input], 'occ');
	}

	/**
	 * @return array<string, array{Field, list<string>}>
	 */
	public static function invalid(): array {
		return [
			'empty' => [Field::Classificatie, ['  ']],
			'two identifiers' => [Field::Identificatie, ['a', 'b']],
			'not a date' => [Field::Bewaartermijn, ['ten years']],
			'impossible date' => [Field::Bewaartermijn, ['2036-02-30']],
		];
	}

	/**
	 * @param list<string> $values
	 */
	#[DataProvider('invalid')]
	public function testInvalidValuesAreRefused(Field $field, array $values): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(InvalidArgumentException::class);
		$this->service->set(7, $field, $values, 'occ');
	}

	public function testClear(): void {
		$this->mapper->expects($this->exactly(2))->method('deleteByFileId')
			->willReturnCallback(function (int $fileId, ?string $field): void {
				$this->assertSame(7, $fileId);
				$this->assertContains($field, [null, 'classificatie']);
			});
		$this->service->clear(7);
		$this->service->clear(7, Field::Classificatie);
	}
}
