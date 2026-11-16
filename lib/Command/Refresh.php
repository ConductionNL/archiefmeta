<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Command;

use OCA\ArchiefMeta\Service\MetadataRefresher;
use OCA\ArchiefMeta\Service\NodeResolver;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ archiefmeta:refresh <path|fileid> [--recursive]
 */
class Refresh extends Command {
	public function __construct(
		private NodeResolver $resolver,
		private MetadataRefresher $refresher,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('archiefmeta:refresh')
			->setDescription('Read the archival metadata of a file again from its sources')
			->addArgument('target', InputArgument::REQUIRED, 'File ID, or path such as alice/files/Report.pdf')
			->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Refresh every file below a folder');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$node = $this->resolver->resolve((string)$input->getArgument('target'));
		} catch (NotFoundException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return self::FAILURE;
		}

		if ($node instanceof Folder && !$input->getOption('recursive')) {
			$output->writeln('<error>This is a folder. Use --recursive to refresh the files in it.</error>');
			return self::FAILURE;
		}

		$count = 0;
		foreach ($this->files($node) as $file) {
			$values = $this->refresher->refresh($file);
			$sources = array_unique(array_map(static fn ($value): string => $value->source->value, $values));
			$output->writeln(sprintf('%d %s: %d field(s) from %s', $file->getId(), $file->getPath(), count($values), $sources === [] ? 'no source' : implode(', ', $sources)));
			$count++;
		}
		$output->writeln(sprintf('Refreshed %d file(s).', $count));
		return self::SUCCESS;
	}

	/**
	 * @return iterable<File>
	 */
	private function files(Node $node): iterable {
		if ($node instanceof File) {
			yield $node;
			return;
		}
		if ($node instanceof Folder) {
			foreach ($node->getDirectoryListing() as $child) {
				yield from $this->files($child);
			}
		}
	}
}
