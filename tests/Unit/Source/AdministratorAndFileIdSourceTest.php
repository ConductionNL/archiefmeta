<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Tests\Unit\Source;

use DateTimeImmutable;
use OCA\ArchiefMeta\Db\ManualValue;
use OCA\ArchiefMeta\Db\ManualValueMapper;
use OCA\ArchiefMeta\Model\Field;
use OCA\ArchiefMeta\Model\Source;
use OCA\ArchiefMeta\Source\AdministratorSource;
use OCA\ArchiefMeta\Source\FileIdSource;
use OCP\Files\File;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class AdministratorAndFileIdSourceTest extends TestCase {
	private function file(int $id): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		return $file;
	}

	public function testAdministratorValues(): void {
		$zorgdrager = new ManualValue();
		$zorgdrager->setField('zorgdrager');
		$zorgdrager->setValues(['Gemeente A', 'Gemeente B']);
		$zorgdrager->setSetBy('occ');
		$zorgdrager->setSetAt(1763287200);
		$unknown = new ManualValue();
		$unknown->setField('not-a-field');
		$unknown->setValues(['x']);

		$mapper = $this->createMock(ManualValueMapper::class);
		$mapper->expects($this->once())->method('findByFileId')->with(7)->willReturn([$zorgdrager, $unknown]);

		$values = (new AdministratorSource($mapper))->read($this->file(7), new DateTimeImmutable('2026-11-16T10:00:00+00:00'));

		$this->assertCount(1, $values);
		$this->assertSame(Field::Zorgdrager, $values[0]->field);
		$this->assertSame(['Gemeente A', 'Gemeente B'], $values[0]->values);
		$this->assertSame(Source::Administrator, $values[0]->source);
		$this->assertSame('occ', $values[0]->details['setBy']);
		$this->assertSame('2025-11-16T10:00:00+00:00', $values[0]->details['setAt']);
	}

	public function testFileIdIsReportedWithInstanceAndNotDurableSource(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('instanceid')->willReturn('oc1234');

		$values = (new FileIdSource($config))->read($this->file(42), new DateTimeImmutable());

		$this->assertCount(1, $values);
		$this->assertSame(Field::Identificatie, $values[0]->field);
		$this->assertSame(['42'], $values[0]->values);
		$this->assertSame(Source::NextcloudFileId, $values[0]->source);
		$this->assertSame(['instanceId' => 'oc1234'], $values[0]->details);
	}

	public function testNoFileIdNoValue(): void {
		$this->assertSame([], (new FileIdSource($this->createMock(IConfig::class)))->read($this->file(0), new DateTimeImmutable()));
	}
}
