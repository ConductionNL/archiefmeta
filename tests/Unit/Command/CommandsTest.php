<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Tests\Unit\Command;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\ArchiefMeta\Command\Refresh;
use OCA\ArchiefMeta\Command\SetValues;
use OCA\ArchiefMeta\Command\Show;
use OCA\ArchiefMeta\Model\Field;
use OCA\ArchiefMeta\Model\FieldValue;
use OCA\ArchiefMeta\Model\Source;
use OCA\ArchiefMeta\Service\ManualValueService;
use OCA\ArchiefMeta\Service\MetadataRefresher;
use OCA\ArchiefMeta\Service\NodeResolver;
use OCA\ArchiefMeta\Storage\IMetadataStore;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CommandsTest extends TestCase {
	private function file(int $id, string $path = '/alice/files/a.pdf'): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn($path);
		return $file;
	}

	private function resolver(mixed $node): NodeResolver {
		$resolver = $this->createMock(NodeResolver::class);
		if ($node instanceof \Throwable) {
			$resolver->method('resolve')->willThrowException($node);
		} else {
			$resolver->method('resolve')->willReturn($node);
		}
		return $resolver;
	}

	private function value(Field $field, string $value, Source $source): FieldValue {
		return new FieldValue($field, [$value], $source, new DateTimeImmutable('2026-11-16T10:00:00+00:00'));
	}

	public function testRefreshFile(): void {
		$file = $this->file(42);
		$refresher = $this->createMock(MetadataRefresher::class);
		$refresher->expects($this->once())->method('refresh')->with($file)->willReturn([
			$this->value(Field::Bewaartermijn, '2036-01-01T00:00:00+00:00', Source::S3ObjectLock),
			$this->value(Field::Identificatie, '42', Source::NextcloudFileId),
		]);

		$tester = new CommandTester(new Refresh($this->resolver($file), $refresher));
		$this->assertSame(Command::SUCCESS, $tester->execute(['target' => '42']));
		$this->assertStringContainsString('42 /alice/files/a.pdf: 2 field(s) from s3-object-lock, nextcloud-fileid', $tester->getDisplay());
		$this->assertStringContainsString('Refreshed 1 file(s).', $tester->getDisplay());
	}

	public function testRefreshFolderNeedsRecursive(): void {
		$child = $this->file(43, '/alice/files/dir/b.pdf');
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$child]);
		$refresher = $this->createMock(MetadataRefresher::class);
		$refresher->expects($this->once())->method('refresh')->with($child)->willReturn([]);

		$command = new Refresh($this->resolver($folder), $refresher);
		$tester = new CommandTester($command);
		$this->assertSame(Command::FAILURE, $tester->execute(['target' => 'alice/files/dir']));

		$tester = new CommandTester($command);
		$this->assertSame(Command::SUCCESS, $tester->execute(['target' => 'alice/files/dir', '--recursive' => true]));
		$this->assertStringContainsString('43 /alice/files/dir/b.pdf: 0 field(s) from no source', $tester->getDisplay());
	}

	public function testRefreshUnknownTarget(): void {
		$tester = new CommandTester(new Refresh($this->resolver(new NotFoundException('No file with ID 9')), $this->createMock(MetadataRefresher::class)));
		$this->assertSame(Command::FAILURE, $tester->execute(['target' => '9']));
		$this->assertStringContainsString('No file with ID 9', $tester->getDisplay());
	}

	public function testSetStoresValuesAndRefreshes(): void {
		$file = $this->file(42);
		$manual = $this->createMock(ManualValueService::class);
		$calls = [];
		$manual->method('set')->willReturnCallback(function (int $fileId, Field $field, array $values, string $setBy, array $details) use (&$calls): void {
			$calls[] = [$fileId, $field->value, $values, $setBy, $details];
		});
		$manual->expects($this->once())->method('clear')->with(42, Field::Identificatie);
		$refresher = $this->createMock(MetadataRefresher::class);
		$refresher->expects($this->once())->method('refresh')->with($file)->willReturn([
			$this->value(Field::Zorgdrager, 'Gemeente A', Source::Administrator),
		]);

		$tester = new CommandTester(new SetValues($this->resolver($file), $manual, $refresher));
		$status = $tester->execute([
			'target' => '42',
			'--zorgdrager' => ['Gemeente A', 'Gemeente B'],
			'--bewaartermijn' => '2036-01-01',
			'--waardering' => 'V',
			'--informatiecategorie' => 'Primair beleid',
			'--informatiecategorie-begripcode' => '2.1.1',
			'--informatiecategorie-begripbegrippenlijst' => 'Selectielijst ministerie van Infrastructuur en Milieu 2017',
			'--clear' => ['identificatie'],
		]);

		$this->assertSame(Command::SUCCESS, $status);
		$this->assertSame([
			[42, 'bewaartermijn', ['2036-01-01'], 'occ', []],
			[42, 'waardering', ['V'], 'occ', []],
			[42, 'informatiecategorie', ['Primair beleid'], 'occ', ['begripCode' => '2.1.1', 'begripBegrippenlijst' => 'Selectielijst ministerie van Infrastructuur en Milieu 2017']],
			[42, 'zorgdrager', ['Gemeente A', 'Gemeente B'], 'occ', []],
		], $calls);
		$this->assertStringContainsString('zorgdrager: Gemeente A (administrator)', $tester->getDisplay());
	}

	public function testSetRefusesInvalidInput(): void {
		$manual = $this->createMock(ManualValueService::class);
		$manual->method('set')->willThrowException(new InvalidArgumentException('bewaartermijn must be a date such as 2036-01-01'));
		$refresher = $this->createMock(MetadataRefresher::class);
		$refresher->expects($this->never())->method('refresh');

		$tester = new CommandTester(new SetValues($this->resolver($this->file(42)), $manual, $refresher));
		$this->assertSame(Command::FAILURE, $tester->execute(['target' => '42', '--bewaartermijn' => 'soon']));
		$this->assertStringContainsString('must be a date', $tester->getDisplay());
	}

	public function testSetRefusesUnknownClearAndFolders(): void {
		$tester = new CommandTester(new SetValues($this->resolver($this->file(42)), $this->createMock(ManualValueService::class), $this->createMock(MetadataRefresher::class)));
		$this->assertSame(Command::FAILURE, $tester->execute(['target' => '42', '--clear' => ['colour']]));

		$tester = new CommandTester(new SetValues($this->resolver($this->createMock(Folder::class)), $this->createMock(ManualValueService::class), $this->createMock(MetadataRefresher::class)));
		$this->assertSame(Command::FAILURE, $tester->execute(['target' => '1']));
	}

	public function testShowTableAndJson(): void {
		$store = $this->createMock(IMetadataStore::class);
		$store->method('load')->with(42)->willReturn([
			new FieldValue(Field::Bewaartermijn, ['2036-01-01T00:00:00+00:00'], Source::S3ObjectLock, new DateTimeImmutable('2026-11-16T10:00:00+00:00'), ['mode' => 'GOVERNANCE']),
		]);
		$command = new Show($this->resolver($this->file(42)), $store);

		$tester = new CommandTester($command);
		$this->assertSame(Command::SUCCESS, $tester->execute(['target' => '42']));
		$this->assertStringContainsString('s3-object-lock', $tester->getDisplay());
		$this->assertStringContainsString('mode=GOVERNANCE', $tester->getDisplay());

		$tester = new CommandTester($command);
		$tester->execute(['target' => '42', '--json' => true]);
		$json = json_decode($tester->getDisplay(), true);
		$this->assertSame(42, $json['fileId']);
		$this->assertSame('s3-object-lock', $json['fields']['bewaartermijn']['source']);
	}

	public function testShowEmpty(): void {
		$tester = new CommandTester(new Show($this->resolver($this->file(42)), $this->createMock(IMetadataStore::class)));
		$tester->execute(['target' => '42']);
		$this->assertStringContainsString('No archival metadata stored', $tester->getDisplay());
	}
}
