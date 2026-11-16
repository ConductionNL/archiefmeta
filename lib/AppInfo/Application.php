<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\AppInfo;

use OCA\ArchiefMeta\Listener\LoadSidebarScript;
use OCA\ArchiefMeta\Listener\RefreshOnWrite;
use OCA\ArchiefMeta\Service\MetadataRefresher;
use OCA\ArchiefMeta\Source\AdministratorSource;
use OCA\ArchiefMeta\Source\FileIdSource;
use OCA\ArchiefMeta\Source\S3\S3MetadataSource;
use OCA\ArchiefMeta\Storage\FilesMetadataStore;
use OCA\ArchiefMeta\Storage\IMetadataStore;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'archiefmeta';

	/**
	 * @param array<string, mixed> $urlParams
	 */
	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(IMetadataStore::class, FilesMetadataStore::class);

		// Order is precedence: the storage layer first, the file ID only as a last resort.
		$context->registerService(MetadataRefresher::class, static fn (ContainerInterface $c): MetadataRefresher => new MetadataRefresher(
			[
				$c->get(S3MetadataSource::class),
				$c->get(AdministratorSource::class),
				$c->get(FileIdSource::class),
			],
			$c->get(IMetadataStore::class),
			$c->get(ITimeFactory::class),
			$c->get(LoggerInterface::class),
		));

		$context->registerEventListener(NodeWrittenEvent::class, RefreshOnWrite::class);
		$context->registerEventListener(NodeCopiedEvent::class, RefreshOnWrite::class);
		$context->registerEventListener(NodeRenamedEvent::class, RefreshOnWrite::class);
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadSidebarScript::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
