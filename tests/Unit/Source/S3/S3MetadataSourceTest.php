<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Tests\Unit\Source\S3;

use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use DateTimeImmutable;
use GuzzleHttp\Promise\Create;
use OCA\ArchiefMeta\Model\Field;
use OCA\ArchiefMeta\Model\FieldValue;
use OCA\ArchiefMeta\Model\Source;
use OCA\ArchiefMeta\Source\S3\S3MetadataSource;
use OCA\ArchiefMeta\Source\S3\S3ObjectLocation;
use OCA\ArchiefMeta\Source\S3\S3ObjectLocator;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class S3MetadataSourceTest extends TestCase {
	/** @var list<string> */
	private array $calls = [];

	/**
	 * Builds a real S3 client whose transport answers from $responses, keyed by operation name.
	 * A string answer is thrown as an S3 error with that code.
	 *
	 * @param array<string, array<string, mixed>|string> $responses
	 */
	private function client(array $responses): S3Client {
		return new S3Client([
			'region' => 'us-east-1',
			'version' => 'latest',
			'credentials' => ['key' => 'k', 'secret' => 's'],
			'handler' => function (CommandInterface $command) use ($responses) {
				$name = $command->getName();
				$this->calls[] = $name;
				$this->assertSame('archive', $command['Bucket']);
				$this->assertSame('urn:oid:42', $command['Key']);
				$answer = $responses[$name] ?? 'NoSuchKey';
				if (is_string($answer)) {
					return Create::rejectionFor(new S3Exception('error', $command, ['code' => $answer]));
				}
				return Create::promiseFor(new Result($answer));
			},
		]);
	}

	/**
	 * @param array<string, array<string, mixed>|string> $responses
	 * @return array<string, FieldValue>
	 */
	private function read(array $responses, ?LoggerInterface $logger = null): array {
		$locator = $this->createMock(S3ObjectLocator::class);
		$locator->method('locate')->willReturn(new S3ObjectLocation($this->client($responses), 'archive', 'urn:oid:42'));
		$source = new S3MetadataSource($locator, $logger ?? $this->createMock(LoggerInterface::class));

		$result = [];
		foreach ($source->read($this->createMock(File::class), new DateTimeImmutable('2026-11-16T10:00:00+00:00')) as $value) {
			$result[$value->field->value] = $value;
		}
		return $result;
	}

	public function testReadsObjectLockAndUserMetadata(): void {
		$values = $this->read([
			'HeadObject' => ['Metadata' => [
				'mdto-classificatie' => 'Openbaar',
				'mdto-zorgdrager' => 'Gemeente Zuiddrecht; Regionaal%20Archief',
				'mdto-identificatie' => 'ZD-2026-0001',
				'mdto-identificatie-systeem' => 'Zaaksysteem',
			]],
			'GetObjectRetention' => ['Retention' => ['Mode' => 'GOVERNANCE', 'RetainUntilDate' => new DateTimeImmutable('2036-01-01T12:00:00+01:00')]],
			'GetObjectLegalHold' => ['LegalHold' => ['Status' => 'OFF']],
		]);

		$this->assertSame(['2036-01-01T11:00:00+00:00'], $values['bewaartermijn']->values);
		$this->assertSame(Source::S3ObjectLock, $values['bewaartermijn']->source);
		$this->assertSame(['legalHold' => 'OFF', 'mode' => 'GOVERNANCE'], $values['bewaartermijn']->details);
		$this->assertSame(['Openbaar'], $values['classificatie']->values);
		$this->assertSame(Source::S3ObjectMetadata, $values['classificatie']->source);
		$this->assertSame(['Gemeente Zuiddrecht', 'Regionaal Archief'], $values['zorgdrager']->values);
		$this->assertSame(['ZD-2026-0001'], $values['identificatie']->values);
		$this->assertSame(['kenmerkSysteem' => 'Zaaksysteem'], $values['identificatie']->details);
		$this->assertSame('2026-11-16T10:00:00+00:00', $values['identificatie']->readAt->format(DATE_ATOM));
		$this->assertNotContains('GetObjectTagging', $this->calls, 'Tags are only read when metadata lacks a field');
	}

	public function testFallsBackToTagsAndMetadataRetention(): void {
		$values = $this->read([
			'HeadObject' => ['Metadata' => ['mdto-bewaartermijn' => '2040-01-01']],
			'GetObjectRetention' => 'ObjectLockConfigurationNotFoundError',
			'GetObjectLegalHold' => 'ObjectLockConfigurationNotFoundError',
			'GetObjectTagging' => ['TagSet' => [
				['Key' => 'mdto-classificatie', 'Value' => 'Intern'],
				['Key' => 'mdto-zorgdrager-2', 'Value' => 'Regionaal Archief'],
				['Key' => 'mdto-zorgdrager-1', 'Value' => 'Gemeente A'],
				['Key' => 'mdto-identificatie-1', 'Value' => 'ignored, identificatie takes one value'],
			]],
		]);

		$this->assertSame(Source::S3ObjectMetadata, $values['bewaartermijn']->source);
		$this->assertSame(['2040-01-01'], $values['bewaartermijn']->values);
		$this->assertSame(Source::S3ObjectTags, $values['classificatie']->source);
		$this->assertSame(['Gemeente A', 'Regionaal Archief'], $values['zorgdrager']->values);
		$this->assertArrayNotHasKey('identificatie', $values);
	}

	public function testLegalHoldWithoutRetentionHasNoEndDate(): void {
		$values = $this->read([
			'HeadObject' => ['Metadata' => []],
			'GetObjectRetention' => 'NoSuchObjectLockConfiguration',
			'GetObjectLegalHold' => ['LegalHold' => ['Status' => 'ON']],
			'GetObjectTagging' => ['TagSet' => []],
		]);

		$this->assertSame([S3MetadataSource::RETENTION_INDEFINITE], $values['bewaartermijn']->values);
		$this->assertSame(['legalHold' => 'ON'], $values['bewaartermijn']->details);
	}

	public function testLockedObjectWithoutLegalHoldReportsItOff(): void {
		$values = $this->read([
			'HeadObject' => ['Metadata' => []],
			'GetObjectRetention' => ['Retention' => ['Mode' => 'COMPLIANCE', 'RetainUntilDate' => '2030-05-01T00:00:00Z']],
			'GetObjectLegalHold' => 'NoSuchObjectLockConfiguration',
			'GetObjectTagging' => ['TagSet' => []],
		]);

		$this->assertSame(['2030-05-01T00:00:00+00:00'], $values['bewaartermijn']->values);
		$this->assertSame(['legalHold' => 'OFF', 'mode' => 'COMPLIANCE'], $values['bewaartermijn']->details);
	}

	public function testUnexpectedErrorsAreLoggedAndYieldNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('warning');

		$values = $this->read([
			'HeadObject' => 'AccessDenied',
			'GetObjectRetention' => 'AccessDenied',
			'GetObjectLegalHold' => 'AccessDenied',
			'GetObjectTagging' => 'AccessDenied',
		], $logger);

		$this->assertSame([], $values);
	}

	public function testAbsentSettingsAreNotLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$values = $this->read([
			'HeadObject' => ['Metadata' => []],
			'GetObjectRetention' => 'NoSuchObjectLockConfiguration',
			'GetObjectLegalHold' => 'NoSuchObjectLockConfiguration',
			'GetObjectTagging' => ['TagSet' => []],
		], $logger);

		$this->assertSame([], $values);
	}

	public function testFileOutsideS3IsSkipped(): void {
		$locator = $this->createMock(S3ObjectLocator::class);
		$locator->method('locate')->willReturn(null);
		$source = new S3MetadataSource($locator, $this->createMock(LoggerInterface::class));

		$this->assertSame([], $source->read($this->createMock(File::class), new DateTimeImmutable()));
	}

	public function testEmptyValuesAreIgnored(): void {
		$values = $this->read([
			'HeadObject' => ['Metadata' => ['mdto-zorgdrager' => ' ; ', 'mdto-classificatie' => '']],
			'GetObjectRetention' => 'NoSuchObjectLockConfiguration',
			'GetObjectLegalHold' => 'NoSuchObjectLockConfiguration',
			'GetObjectTagging' => ['TagSet' => []],
		]);

		$this->assertArrayNotHasKey('zorgdrager', $values);
		$this->assertArrayNotHasKey('classificatie', $values);
		$this->assertArrayNotHasKey(Field::Bewaartermijn->value, $values);
	}
}
