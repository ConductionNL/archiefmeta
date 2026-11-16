<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Command;

use OCA\ArchiefMeta\Service\NodeResolver;
use OCA\ArchiefMeta\Storage\IMetadataStore;
use OCP\Files\NotFoundException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ archiefmeta:show <path|fileid> [--json]
 */
class Show extends Command {
	public function __construct(
		private NodeResolver $resolver,
		private IMetadataStore $store,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('archiefmeta:show')
			->setDescription('Show the stored archival metadata of a file')
			->addArgument('target', InputArgument::REQUIRED, 'File ID, or path such as alice/files/Report.pdf')
			->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$node = $this->resolver->resolve((string)$input->getArgument('target'));
		} catch (NotFoundException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return self::FAILURE;
		}

		$values = $this->store->load($node->getId());
		if ($input->getOption('json')) {
			$data = [];
			foreach ($values as $value) {
				$data[$value->field->value] = $value->toArray();
			}
			$output->writeln(json_encode(['fileId' => $node->getId(), 'fields' => (object)$data], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
			return self::SUCCESS;
		}

		if ($values === []) {
			$output->writeln('No archival metadata stored for this file.');
			return self::SUCCESS;
		}

		$table = new Table($output);
		$table->setHeaders(['Field', 'Value(s)', 'Source', 'Read at', 'Details']);
		foreach ($values as $value) {
			$details = [];
			foreach ($value->details as $key => $detail) {
				$details[] = $key . '=' . $detail;
			}
			$table->addRow([
				$value->field->value,
				implode("\n", $value->values),
				$value->source->value,
				$value->readAt->format(DATE_ATOM),
				implode("\n", $details),
			]);
		}
		$table->render();
		return self::SUCCESS;
	}
}
