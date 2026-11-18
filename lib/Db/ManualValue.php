<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A value an administrator set for one field of one file.
 *
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getField()
 * @method void setField(string $field)
 * @method string getValuesJson()
 * @method void setValuesJson(string $valuesJson)
 * @method string|null getDetailsJson()
 * @method void setDetailsJson(?string $detailsJson)
 * @method string getSetBy()
 * @method void setSetBy(string $setBy)
 * @method int getSetAt()
 * @method void setSetAt(int $setAt)
 */
class ManualValue extends Entity {
	protected int $fileId = 0;
	protected string $field = '';
	protected string $valuesJson = '[]';
	protected ?string $detailsJson = null;
	protected string $setBy = '';
	protected int $setAt = 0;

	public function __construct() {
		$this->addType('fileId', Types::BIGINT);
		$this->addType('field', Types::STRING);
		$this->addType('valuesJson', Types::TEXT);
		$this->addType('detailsJson', Types::TEXT);
		$this->addType('setBy', Types::STRING);
		$this->addType('setAt', Types::BIGINT);
	}

	/**
	 * @return list<string>
	 */
	public function getValues(): array {
		$decoded = json_decode($this->getValuesJson(), true);
		if (!is_array($decoded)) {
			return [];
		}
		return array_values(array_filter(array_map('strval', $decoded), static fn (string $value): bool => $value !== ''));
	}

	/**
	 * @param list<string> $values
	 */
	public function setValues(array $values): void {
		$this->setValuesJson(json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
	}

	/**
	 * @return array<string, string>
	 */
	public function getDetails(): array {
		$decoded = json_decode($this->getDetailsJson() ?? '', true);
		$details = [];
		foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
			if (is_scalar($value) && (string)$value !== '') {
				$details[(string)$key] = (string)$value;
			}
		}
		return $details;
	}

	/**
	 * @param array<string, string> $details
	 */
	public function setDetails(array $details): void {
		$this->setDetailsJson($details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
	}
}
