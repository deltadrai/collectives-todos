<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCP\Files\File;
use OCP\Files\Folder;
use OCA\CollectiveTodos\Service\CheckboxParser;
use OCA\CollectiveTodos\Service\CheckboxAggregator;
use OCA\CollectiveTodos\Service\TodosPageGenerator;

class InitCollectivesCommand extends Command
{
    private CheckboxParser $parser;
    private CheckboxAggregator $aggregator;
    private TodosPageGenerator $generator;

    public function __construct(
        CheckboxParser $parser,
        CheckboxAggregator $aggregator,
        TodosPageGenerator $generator
    ) {
        parent::__construct();
        $this->parser = $parser;
        $this->aggregator = $aggregator;
        $this->generator = $generator;
    }

    protected function configure(): void
    {
        $this
            ->setName('collectives_todos:init')
            ->setDescription('Initialize existing collectives with Todos aggregation: scan all pages and create the Todos page')
            ->addArgument(
                'collectives-id',
                InputArgument::REQUIRED,
                'The collectives id (as used by the Collectives app), the collectives folder file id, or a folder path'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $collectiveId = $input->getArgument('collectives-id');

        try {
            $collectiveFolder = $this->aggregator->getFolder($collectiveId);
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not find collectives folder for "' . $collectiveId . '": ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $this->aggregator->clear($collectiveFolder);

        $pageCount = 0;
        $checkboxCount = 0;
        foreach ($this->scanMarkdownFiles($collectiveFolder) as $file) {
            $checkboxes = $this->parser->parse($file->getContent());
            $this->aggregator->updatePageCheckboxes(
                $collectiveFolder,
                (string)$file->getId(),
                $this->aggregator->derivePageTitle($file),
                $checkboxes
            );
            $pageCount++;
            $checkboxCount += count($checkboxes);
        }

        $this->generator->regenerateTodosPage($collectiveFolder);

        $output->writeln('<info>Initialized collectives: ' . $collectiveFolder->getName() . '</info>');
        $output->writeln('<info>Scanned ' . $pageCount . ' pages, found ' . $checkboxCount . ' checkboxes.</info>');

        return Command::SUCCESS;
    }

    /**
     * Recursively yield all markdown files of a collectives,
     * skipping the generated Todos page and hidden folders.
     *
     * @return \Generator<File>
     */
    private function scanMarkdownFiles(Folder $folder): \Generator
    {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof Folder) {
                if (str_starts_with($node->getName(), '.')) {
                    continue;
                }
                yield from $this->scanMarkdownFiles($node);
            } elseif ($node instanceof File
                && $node->getMimeType() === 'text/markdown'
                && $node->getName() !== TodosPageGenerator::TODOS_PAGE_FILENAME) {
                yield $node;
            }
        }
    }
}
