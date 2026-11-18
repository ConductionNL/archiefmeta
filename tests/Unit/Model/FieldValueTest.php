<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Tests\Unit\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\ArchiefMeta\Model\Field;
use OCA\ArchiefMeta\Model\FieldValue;
use OCA\ArchiefMeta\Model\Source;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class FieldValueTest extends TestCase {
	public function testRoundTrip(): void {
		$value = new FieldValue(Field::Zorgdrager, ['A', 'B'], Source::S3ObjectTags, new DateTimeImmutable('2026-11-16T10:00:00+00:00'), ['x' => 'y']);

		$copy = FieldValue::fromArray(Field::Zorgdrager, $value->toArray());

		$this->assertEquals($value, $copy);
		$this->assertSame('s3-object-tags', $value->toArray()['source']);
	}

	public function testUnusableDataYieldsNull(): void {
		$this->assertNull(FieldValue::fromArray(Field::Classificatie, []));
		$this->assertNull(FieldValue::fromArray(Field::Classificatie, ['values' => ['A'], 'source' => 'unknown', 'readAt' => '2026-11-16T10:00:00+00:00']));
		$this->assertNull(FieldValue::fromArray(Field::Classificatie, ['values' => [''], 'source' => 'administrator', 'readAt' => '2026-11-16T10:00:00+00:00']));
		$this->assertNull(FieldValue::fromArray(Field::Classificatie, ['values' => ['A'], 'source' => 'administrator', 'readAt' => 'yesterday']));
	}

	public function testNeedsAValue(): void {
		$this->expectException(InvalidArgumentException::class);
		new FieldValue(Field::Classificatie, [], Source::Administrator, new DateTimeImmutable());
	}

	public function testOnlyCustodianAndClassificationTakeSeveralValues(): void {
		$this->assertTrue(Field::Zorgdrager->isMultiple());
		$this->assertTrue(Field::Classificatie->isMultiple());
		$this->assertFalse(Field::Bewaartermijn->isMultiple());
		$this->assertFalse(Field::Identificatie->isMultiple());
		$this->assertFalse(Field::Waardering->isMultiple());
		$this->assertFalse(Field::Informatiecategorie->isMultiple());
	}

	public function testWaarderingAndInformatiecategorieAreBegrippen(): void {
		$this->assertTrue(Field::Waardering->isBegrip());
		$this->assertTrue(Field::Informatiecategorie->isBegrip());
		$this->assertFalse(Field::Classificatie->isBegrip());
		$this->assertFalse(Field::Bewaartermijn->isBegrip());
	}

	public function testEverySourceHasALabel(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		foreach (Source::cases() as $source) {
			$this->assertNotSame('', $source->label($l));
		}
		$this->assertTrue(Source::S3ObjectLock->isStorageLayer());
		$this->assertFalse(Source::Administrator->isStorageLayer());
		$this->assertFalse(Source::NextcloudFileId->isStorageLayer());
	}
}
