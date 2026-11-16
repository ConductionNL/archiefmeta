<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ArchiefMeta\Command;

use InvalidArgumentException;
use OCA\ArchiefMeta\Model\Field;
use OCA\ArchiefMeta\Service\ManualValueService;
use OCA\ArchiefMeta\Service\MetadataRefresher;
use OCA\ArchiefMeta\Service\NodeResolver;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ archiefmeta:set <path|fileid> [--classificatie=..] [--zorgdrager=..]... [--bewaartermijn=..] [--identificatie=..] [--clear=<field>]... [--clear-all]
 *
 * Values set here have the source "administrator". The storage layer still wins
 * for any field it has a value for.
 */
class SetValues extends Command {
	public function __construct(
		private NodeResolver $resolver,
		private ManualValueService $manualValues,
		private MetadataRefresher $refresher,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('archiefmeta:set')
			->setDescription('Set archival metadata for a file as administrator')
			->addArgument('target', InputArgument::REQUIRED, 'File ID, or path such as alice/files/Report.pdf')
			->addOption('clear', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Remove the administrator value of this field')
			->addOption('clear-all', null, InputOption::VALUE_NONE, 'Remove all administrator values of this file');
		foreach (Field::cases() as $field) {
			$mode = $field->isMultiple() ? InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY : InputOption::VALUE_REQUIRED;
			$description = match ($field) {
				Field::Classificatie => 'Classification; repeat for more than one',
				Field::Bewaartermijn => 'Retain until date, such as 2036-01-01',
				Field::Zorgdrager => 'Custodian organisation; repeat for more than one',
				Field::Identificatie => 'Identifier of the record',
			};
			$this->addOption($field->value, null, $mode, $description);
		}
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$node = $this->resolver->resolve((string)$input->getArgument('target'));
		} catch (NotFoundException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return self::FAILURE;
		}
		if (!$node instanceof File) {
			$output->writeln('<error>Archival metadata can only be set on a file.</error>');
			return self::FAILURE;
		}

		try {
			if ($input->getOption('clear-all')) {
				$this->manualValues->clear($node->getId());
			}
			foreach ((array)$input->getOption('clear') as $name) {
				$field = Field::tryFrom((string)$name) ?? throw new InvalidArgumentException('Unknown field ' . $name);
				$this->manualValues->clear($node->getId(), $field);
			}
			foreach (Field::cases() as $field) {
				$option = $input->getOption($field->value);
				$values = array_values(array_map('strval', (array)$option));
				if ($option === null || $values === []) {
					continue;
				}
				$this->manualValues->set($node->getId(), $field, $values, 'occ');
			}
		} catch (InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return self::FAILURE;
		}

		foreach ($this->refresher->refresh($node) as $value) {
			$output->writeln(sprintf('%s: %s (%s)', $value->field->value, implode('; ', $value->values), $value->source->value));
		}
		return self::SUCCESS;
	}
}
