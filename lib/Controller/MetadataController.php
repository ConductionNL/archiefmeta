<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Controller;

use OCA\ArchiefMeta\Model\Field;
use OCA\ArchiefMeta\Storage\IMetadataStore;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Read-only access to a file's archival metadata. There is deliberately no write endpoint.
 */
class MetadataController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private IMetadataStore $store,
		private IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Returns the stored archival metadata of a file the current user can access.
	 *
	 * @param int $fileId the Nextcloud file ID
	 * @return DataResponse<Http::STATUS_OK, array{fileId: int, fields: list<array<string, mixed>>}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: metadata returned; fields without a value have "value": null
	 * 404: the file does not exist or the user cannot access it
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/files/{fileId}', requirements: ['fileId' => '\d+'])]
	public function show(int $fileId): DataResponse {
		$user = $this->userSession->getUser();
		$node = $user === null ? null : $this->rootFolder->getUserFolder($user->getUID())->getFirstNodeById($fileId);
		if ($node === null) {
			return new DataResponse(['message' => $this->l->t('File not found')], Http::STATUS_NOT_FOUND);
		}

		$stored = [];
		foreach ($this->store->load($node->getId()) as $value) {
			$stored[$value->field->value] = $value;
		}

		$fields = [];
		foreach (Field::cases() as $field) {
			$value = $stored[$field->value] ?? null;
			$fields[] = [
				'field' => $field->value,
				'multiple' => $field->isMultiple(),
				'value' => $value === null ? null : $value->toArray() + [
					'sourceLabel' => $value->source->label($this->l),
					'storageLayer' => $value->source->isStorageLayer(),
				],
			];
		}

		return new DataResponse(['fileId' => $node->getId(), 'fields' => $fields]);
	}
}
