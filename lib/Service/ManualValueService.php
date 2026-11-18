<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\ArchiefMeta\Db\ManualValue;
use OCA\ArchiefMeta\Db\ManualValueMapper;
use OCA\ArchiefMeta\Model\Begrip;
use OCA\ArchiefMeta\Model\Field;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Keeps the values an administrator sets for files that have no value in the storage layer.
 */
class ManualValueService {
	public function __construct(
		private ManualValueMapper $mapper,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Replaces the administrator value of one field.
	 *
	 * A waardering given as an MDTO code or label is completed from the MDTO list;
	 * other waarderingen need their own begrippenlijst.
	 *
	 * @param list<string> $values
	 * @param array<string, string> $details begripCode and begripBegrippenlijst of a begrip field
	 * @throws InvalidArgumentException when the values do not fit the field
	 */
	public function set(int $fileId, Field $field, array $values, string $setBy, array $details = []): void {
		$values = array_values(array_filter(array_map('trim', $values), static fn (string $value): bool => $value !== ''));
		if ($values === []) {
			throw new InvalidArgumentException(sprintf('%s needs a value', $field->value));
		}
		if (!$field->isMultiple() && count($values) > 1) {
			throw new InvalidArgumentException(sprintf('%s takes one value', $field->value));
		}
		if ($field === Field::Bewaartermijn) {
			$values = [$this->normaliseDate($values[0])];
		}
		$details = $field->isBegrip()
			? array_filter(array_map('trim', array_intersect_key($details, [Begrip::CODE => true, Begrip::BEGRIPPENLIJST => true])), static fn (string $value): bool => $value !== '')
			: [];
		if ($field === Field::Waardering) {
			$known = Begrip::waardering($values[0], $details);
			if ($known !== null) {
				[$values, $details] = [[$known[0]], $known[1]];
			} elseif (strcasecmp($details[Begrip::BEGRIPPENLIJST] ?? Begrip::WAARDERINGEN, Begrip::WAARDERINGEN) === 0) {
				throw new InvalidArgumentException('waardering must be B, V or N, or its MDTO label, unless you give another begrippenlijst');
			}
		}

		$this->mapper->deleteByFileId($fileId, $field->value);
		$entity = new ManualValue();
		$entity->setFileId($fileId);
		$entity->setField($field->value);
		$entity->setValues($values);
		$entity->setDetails($details);
		$entity->setSetBy($setBy);
		$entity->setSetAt($this->timeFactory->getTime());
		$this->mapper->insert($entity);
	}

	public function clear(int $fileId, ?Field $field = null): void {
		$this->mapper->deleteByFileId($fileId, $field?->value);
	}

	/**
	 * Accepts a date (2036-01-01) or a date and time with offset, and returns it in UTC.
	 */
	private function normaliseDate(string $value): string {
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
		if ($date === false || $date->format('Y-m-d') !== $value) {
			$date = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
		}
		if ($date === false) {
			throw new InvalidArgumentException('bewaartermijn must be a date such as 2036-01-01');
		}
		return $date->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
	}
}
