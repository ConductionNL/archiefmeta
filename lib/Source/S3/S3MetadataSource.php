<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Source\S3;

use Aws\Exception\AwsException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\ArchiefMeta\Model\Begrip;
use OCA\ArchiefMeta\Model\Field;
use OCA\ArchiefMeta\Model\FieldValue;
use OCA\ArchiefMeta\Model\Source;
use OCA\ArchiefMeta\Source\IMetadataSource;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads archival metadata from the S3 object behind a file.
 *
 * - bewaartermijn: Object Lock retention (GetObjectRetention) and legal hold
 *   (GetObjectLegalHold). Falls back to the object metadata key when the object
 *   has no lock.
 * - classificatie, waardering, informatiecategorie, zorgdrager, identificatie:
 *   user metadata (x-amz-meta-mdto-*), then object tags with the same names.
 *   Multiple values are separated by ";" or given in numbered keys
 *   (mdto-zorgdrager-1, mdto-zorgdrager-2). Values may be percent-encoded UTF-8.
 * - waardering and informatiecategorie hold the begripLabel; begripCode and
 *   begripBegrippenlijst come from "<key>-begripcode" and "<key>-begripbegrippenlijst"
 *   in the same place. A waardering given as an MDTO code or label is completed
 *   from the MDTO list.
 */
class S3MetadataSource implements IMetadataSource {
	public const KEY_PREFIX = 'mdto-';
	public const KEY_IDENTIFICATIE_SYSTEEM = 'mdto-identificatie-systeem';
	public const SUFFIX_BEGRIP_CODE = '-begripcode';
	public const SUFFIX_BEGRIP_BEGRIPPENLIJST = '-begripbegrippenlijst';
	public const RETENTION_INDEFINITE = 'indefinite';

	/** S3 error codes that mean "this object or bucket has no such setting". */
	private const ABSENT_ERRORS = [
		'NoSuchObjectLockConfiguration',
		'ObjectLockConfigurationNotFoundError',
		'InvalidRequest',
		'NoSuchTagSet',
	];

	public function __construct(
		private S3ObjectLocator $locator,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function read(Node $node, DateTimeImmutable $readAt): array {
		$location = $this->locator->locate($node);
		if ($location === null) {
			return [];
		}

		$metadata = $this->userMetadata($location);
		$tags = null;
		$lookup = function (Field $field) use ($location, $metadata, &$tags): ?array {
			$name = self::KEY_PREFIX . $field->value;
			$raw = $this->collect($metadata, $name, $field->isMultiple());
			if ($raw !== []) {
				return [$raw, Source::S3ObjectMetadata];
			}
			$tags ??= $this->tags($location);
			$raw = $this->collect($tags, $name, $field->isMultiple());
			return $raw !== [] ? [$raw, Source::S3ObjectTags] : null;
		};

		$values = [];
		$retention = $this->retention($location, $readAt);
		if ($retention !== null) {
			$values[] = $retention;
		} elseif (($found = $lookup(Field::Bewaartermijn)) !== null) {
			$values[] = $this->fieldValue(Field::Bewaartermijn, $found[0], $found[1], $readAt);
		}

		foreach ([Field::Classificatie, Field::Waardering, Field::Informatiecategorie, Field::Zorgdrager, Field::Identificatie] as $field) {
			$found = $lookup($field);
			if ($found === null) {
				continue;
			}
			$details = [];
			if ($field === Field::Identificatie) {
				$system = $metadata[self::KEY_IDENTIFICATIE_SYSTEEM] ?? ($tags ?? [])[self::KEY_IDENTIFICATIE_SYSTEEM] ?? null;
				if ($system !== null && $system !== '') {
					$details['kenmerkSysteem'] = rawurldecode($system);
				}
			}
			if ($field->isBegrip()) {
				[$found[0], $details] = $this->begrip($field, $found[0], $found[1] === Source::S3ObjectMetadata ? $metadata : ($tags ?? []));
			}
			$values[] = $this->fieldValue($field, $found[0], $found[1], $readAt, $details);
		}

		return array_values(array_filter($values));
	}

	/**
	 * The begripCode and begripBegrippenlijst next to a begrip label, read from
	 * the map the label came from.
	 *
	 * @param list<string> $raw
	 * @param array<string, string> $map
	 * @return array{0: list<string>, 1: array<string, string>}
	 */
	private function begrip(Field $field, array $raw, array $map): array {
		$name = self::KEY_PREFIX . $field->value;
		$details = array_filter([
			Begrip::CODE => trim(rawurldecode($map[$name . self::SUFFIX_BEGRIP_CODE] ?? '')),
			Begrip::BEGRIPPENLIJST => trim(rawurldecode($map[$name . self::SUFFIX_BEGRIP_BEGRIPPENLIJST] ?? '')),
		], static fn (string $value): bool => $value !== '');
		if ($field === Field::Waardering) {
			$known = Begrip::waardering(rawurldecode($raw[0] ?? ''), $details);
			if ($known !== null) {
				return [[$known[0]], $known[1]];
			}
		}
		return [$raw, $details];
	}

	/**
	 * Raw values of a key. For multi-value fields, numbered keys ("<name>-1", "<name>-2")
	 * are added in order, because S3 tag values cannot contain ";".
	 *
	 * @param array<string, string> $map
	 * @return list<string>
	 */
	private function collect(array $map, string $name, bool $multiple): array {
		$raw = isset($map[$name]) ? [$map[$name]] : [];
		if (!$multiple) {
			return $raw;
		}
		$numbered = [];
		foreach ($map as $key => $value) {
			if (preg_match('/^' . preg_quote($name, '/') . '-(\d+)$/', $key, $match) === 1) {
				$numbered[(int)$match[1]] = $value;
			}
		}
		ksort($numbered);
		return array_merge($raw, array_values($numbered));
	}

	/**
	 * @param list<string> $raw
	 * @param array<string, string> $details
	 */
	private function fieldValue(Field $field, array $raw, Source $source, DateTimeImmutable $readAt, array $details = []): ?FieldValue {
		$parts = $field->isMultiple() ? explode(';', implode(';', $raw)) : [$raw[0] ?? ''];
		$values = array_values(array_filter(
			array_map(static fn (string $part): string => trim(rawurldecode($part)), $parts),
			static fn (string $value): bool => $value !== '',
		));
		return $values === [] ? null : new FieldValue($field, $values, $source, $readAt, $details);
	}

	private function retention(S3ObjectLocation $location, DateTimeImmutable $readAt): ?FieldValue {
		$args = ['Bucket' => $location->bucket, 'Key' => $location->key];
		$retention = $this->call(fn () => $location->client->getObjectRetention($args)['Retention'] ?? null, 'GetObjectRetention', $location);
		$legalHold = $this->call(fn () => $location->client->getObjectLegalHold($args)['LegalHold']['Status'] ?? null, 'GetObjectLegalHold', $location);

		$details = [];
		if (is_string($legalHold) && $legalHold !== '') {
			$details['legalHold'] = $legalHold;
		} elseif ($legalHold === false) {
			$details['legalHold'] = 'OFF';
		}

		$until = is_array($retention) ? $this->toUtc($retention['RetainUntilDate'] ?? null) : null;
		if ($until !== null) {
			$details['mode'] = (string)($retention['Mode'] ?? '');
			return new FieldValue(Field::Bewaartermijn, [$until], Source::S3ObjectLock, $readAt, array_filter($details));
		}
		if (($details['legalHold'] ?? '') === 'ON') {
			return new FieldValue(Field::Bewaartermijn, [self::RETENTION_INDEFINITE], Source::S3ObjectLock, $readAt, $details);
		}
		return null;
	}

	/**
	 * @return array<string, string> metadata names without the x-amz-meta- prefix, lower case
	 */
	private function userMetadata(S3ObjectLocation $location): array {
		$result = $this->call(fn () => $location->client->headObject(['Bucket' => $location->bucket, 'Key' => $location->key])['Metadata'] ?? [], 'HeadObject', $location);
		$metadata = [];
		foreach (is_array($result) ? $result : [] as $name => $value) {
			$metadata[strtolower((string)$name)] = (string)$value;
		}
		return $metadata;
	}

	/**
	 * @return array<string, string>
	 */
	private function tags(S3ObjectLocation $location): array {
		$result = $this->call(fn () => $location->client->getObjectTagging(['Bucket' => $location->bucket, 'Key' => $location->key])['TagSet'] ?? [], 'GetObjectTagging', $location);
		$tags = [];
		foreach (is_array($result) ? $result : [] as $tag) {
			if (is_array($tag) && isset($tag['Key'], $tag['Value'])) {
				$tags[strtolower((string)$tag['Key'])] = (string)$tag['Value'];
			}
		}
		return $tags;
	}

	private function toUtc(mixed $date): ?string {
		try {
			if ($date instanceof DateTimeInterface) {
				$date = DateTimeImmutable::createFromInterface($date);
			} elseif (is_string($date) && $date !== '') {
				$date = new DateTimeImmutable($date);
			} else {
				return null;
			}
			return $date->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * Runs one S3 call. A setting the object does not have yields false;
	 * other failures are logged and yield null.
	 */
	private function call(callable $request, string $operation, S3ObjectLocation $location): mixed {
		try {
			return $request();
		} catch (AwsException $e) {
			if (in_array($e->getAwsErrorCode(), self::ABSENT_ERRORS, true)) {
				return false;
			}
			$this->logger->warning('archiefmeta: {operation} failed for {bucket}/{key}', [
				'operation' => $operation,
				'bucket' => $location->bucket,
				'key' => $location->key,
				'exception' => $e,
			]);
		} catch (Throwable $e) {
			$this->logger->warning('archiefmeta: {operation} failed for {bucket}/{key}', [
				'operation' => $operation,
				'bucket' => $location->bucket,
				'key' => $location->key,
				'exception' => $e,
			]);
		}
		return null;
	}
}
