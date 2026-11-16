<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ManualValue>
 */
class ManualValueMapper extends QBMapper {
	public const TABLE = 'archiefmeta_manual';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, ManualValue::class);
	}

	/**
	 * @return list<ManualValue>
	 */
	public function findByFileId(int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	public function deleteByFileId(int $fileId, ?string $field = null): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		if ($field !== null) {
			$qb->andWhere($qb->expr()->eq('field', $qb->createNamedParameter($field)));
		}
		$qb->executeStatement();
	}
}
