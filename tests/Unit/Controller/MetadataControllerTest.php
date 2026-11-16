<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\ArchiefMeta\Controller\MetadataController;
use OCA\ArchiefMeta\Model\Field;
use OCA\ArchiefMeta\Model\FieldValue;
use OCA\ArchiefMeta\Model\Source;
use OCA\ArchiefMeta\Storage\IMetadataStore;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class MetadataControllerTest extends TestCase {
	private function controller(?File $visible, IMetadataStore $store, bool $loggedIn = true): MetadataController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn ? $user : null);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturn($visible);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('bob')->willReturn($userFolder);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		return new MetadataController('archiefmeta', $this->createMock(IRequest::class), $session, $root, $store, $l);
	}

	public function testReturnsEveryFieldWithSourceForAnAccessibleFile(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$store = $this->createMock(IMetadataStore::class);
		$store->method('load')->with(42)->willReturn([
			new FieldValue(Field::Bewaartermijn, ['2036-01-01T00:00:00+00:00'], Source::S3ObjectLock, new DateTimeImmutable('2026-11-16T10:00:00+00:00'), ['mode' => 'GOVERNANCE']),
		]);

		$response = $this->controller($file, $store)->show(42);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(42, $data['fileId']);
		$this->assertSame(['classificatie', 'bewaartermijn', 'zorgdrager', 'identificatie'], array_column($data['fields'], 'field'));
		$this->assertNull($data['fields'][0]['value']);
		$this->assertSame([
			'values' => ['2036-01-01T00:00:00+00:00'],
			'source' => 's3-object-lock',
			'readAt' => '2026-11-16T10:00:00+00:00',
			'details' => ['mode' => 'GOVERNANCE'],
			'sourceLabel' => 'Storage layer (S3 Object Lock)',
			'storageLayer' => true,
		], $data['fields'][1]['value']);
	}

	public function testFileTheUserCannotSeeIsNotFound(): void {
		$store = $this->createMock(IMetadataStore::class);
		$store->expects($this->never())->method('load');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(null, $store)->show(42)->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(null, $store, false)->show(42)->getStatus());
	}

	public function testOffersNoWriteEndpoint(): void {
		$verbs = [];
		foreach ((new ReflectionClass(MetadataController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			foreach ($method->getAttributes(ApiRoute::class) as $attribute) {
				$verbs[] = $attribute->getArguments()['verb'];
			}
		}
		$this->assertSame(['GET'], $verbs);
	}
}
