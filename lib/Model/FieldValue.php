<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Model;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * One field's value(s) together with their source and the moment they were read.
 *
 * Details carry source specific qualifiers, such as the Object Lock mode or legal hold.
 */
final class FieldValue {
	/**
	 * @param list<string> $values
	 * @param array<string, string> $details
	 */
	public function __construct(
		public readonly Field $field,
		public readonly array $values,
		public readonly Source $source,
		public readonly DateTimeImmutable $readAt,
		public readonly array $details = [],
	) {
		if ($values === []) {
			throw new InvalidArgumentException('A field value needs at least one value');
		}
	}

	/**
	 * @return array{values: list<string>, source: string, readAt: string, details: array<string, string>}
	 */
	public function toArray(): array {
		return [
			'values' => $this->values,
			'source' => $this->source->value,
			'readAt' => $this->readAt->format(DateTimeInterface::ATOM),
			'details' => $this->details,
		];
	}

	/**
	 * Rebuilds a value from its stored form, or returns null when the data is not usable.
	 *
	 * @param array<mixed> $data
	 */
	public static function fromArray(Field $field, array $data): ?self {
		$source = Source::tryFrom((string)($data['source'] ?? ''));
		$values = array_values(array_filter(
			array_map('strval', (array)($data['values'] ?? [])),
			static fn (string $value): bool => $value !== '',
		));
		$readAt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, (string)($data['readAt'] ?? ''));
		if ($source === null || $values === [] || $readAt === false) {
			return null;
		}

		$details = [];
		foreach ((array)($data['details'] ?? []) as $key => $value) {
			$details[(string)$key] = (string)$value;
		}

		return new self($field, $values, $source, $readAt, $details);
	}
}
